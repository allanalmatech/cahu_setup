<?php
declare(strict_types=1);

namespace Http\Controllers;

use Core\Audit;
use Core\Controller;
use Core\Csrf;
use Core\Flash;
use Core\Form;
use Core\HttpException;
use Core\Identifier;
use Core\Paginator;
use Core\Response;
use Core\Uploader;
use Services\Lookups;
use Services\Notifier;

/**
 * Farmer registration and the farmer's permanent digital record.
 *
 * A farmer is never hard-deleted. Deactivating a farmer keeps the row, and
 * therefore the identifier, so every garden, inspection, harvest and sale that
 * references it still resolves. Reusing `farmer_code` would break those
 * references, which is why `destroy()` sets a status instead of deleting.
 *
 * @see app/Http/Controllers/Api/V1/ApiController.php for the mobile equivalent,
 *      which writes the same tables and honours `client_uuid` for de-duplication.
 */
final class FarmerController extends Controller
{
    private const UPLOAD_FOLDER = 'farmers';

    private Lookups $lookups;
    private Notifier $notifier;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups  = new Lookups($db);
        $this->notifier = new Notifier($db);
    }

    /**
     * The farmer register: search, filters, sorting and pagination.
     *
     * The three aggregate columns (garden count, acreage, overdue activities)
     * are correlated subqueries rather than joins. A join would multiply the
     * farmer row by its gardens and make the paging counts wrong, which shows up
     * as duplicated farmers on page 2.
     */
    public function index(): Response
    {
        $params = $this->listParams();

        $where  = ['f.deleted_at IS NULL'];
        $values = [];

        if ($params['search'] !== '') {
            // One placeholder per column, all bound to the same term. The
            // connection uses native prepared statements, where PDO maps each
            // placeholder to exactly one bound value, so a single :s used five
            // times asks for a parameter that was never supplied and the query
            // fails with HY093.
            $term = '%' . $params['search'] . '%';

            $where[]      = '(f.farmer_code LIKE :s1 OR f.full_name LIKE :s2 OR f.phone LIKE :s3
                              OR f.national_id LIKE :s4 OR f.email LIKE :s5)';
            $values['s1'] = $term;
            $values['s2'] = $term;
            $values['s3'] = $term;
            $values['s4'] = $term;
            $values['s5'] = $term;
        }

        foreach (['status' => 'f.status', 'district_id' => 'f.district_id', 'group_id' => 'f.group_id', 'officer_id' => 'f.officer_id'] as $key => $column) {
            if (isset($params['filters'][$key]) && $params['filters'][$key] !== '') {
                $where[]            = $column . ' = :' . $key;
                $values[$key]       = $params['filters'][$key];
            }
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total     = (int) $this->db->scalar('SELECT COUNT(*) FROM farmers f' . $whereSql, $values);
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $rows = $this->db->select(
            'SELECT f.*, g.name AS group_name, o.full_name AS officer_name, d.name AS district_name,
                    (SELECT COUNT(*) FROM gardens gd WHERE gd.farmer_id = f.id AND gd.deleted_at IS NULL) AS garden_count,
                    (SELECT COALESCE(SUM(gd.acreage), 0) FROM gardens gd WHERE gd.farmer_id = f.id AND gd.deleted_at IS NULL) AS total_acreage,
                    (SELECT COUNT(*) FROM crop_activities a WHERE a.farmer_id = f.id AND a.status = \'overdue\') AS overdue_count
             FROM farmers f
             LEFT JOIN farmer_groups g ON g.id = f.group_id
             LEFT JOIN field_officers o ON o.id = f.officer_id
             LEFT JOIN districts d ON d.id = f.district_id'
            . $whereSql
            . ' ORDER BY ' . $this->sortClause($params['sort'], $params['direction'])
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('farmers/index', [
            'pageTitle'   => 'Farmers',
            'rows'        => $rows,
            'paginator'   => $paginator,
            'sort'        => $params['sort'],
            'direction'   => $params['direction'],
            'filters'     => [
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions("'active','inactive','pending','deceased','exited'", 'All statuses')],
                ['key' => 'district_id', 'label' => 'District', 'options' => $this->lookups->districtOptions()],
                ['key' => 'group_id', 'label' => 'Group', 'options' => $this->groupOptions()],
                ['key' => 'officer_id', 'label' => 'Officer', 'options' => $this->lookups->officerOptions('All officers')],
            ],
        ]);
    }

    /**
     * Whitelists the ORDER BY column.
     *
     * The sort key arrives from a query string, so it cannot be interpolated
     * without a check. An unrecognised key falls back to newest-first rather
     * than raising, so a stale bookmarked URL still renders.
     */
    private function sortClause(string $sort, string $direction): string
    {
        $allowed = ['farmer_code', 'full_name', 'phone', 'registration_date', 'status', 'total_acreage', 'created_at'];
        $column  = in_array($sort, $allowed, true) ? $sort : 'created_at';
        $order   = $direction === 'asc' ? 'ASC' : 'DESC';

        return 'f.' . $column . ' ' . $order;
    }

    /**
     * Empty registration form.
     *
     * `$gpsLng` exists so the form view can toggle a "use my current position"
     * button off one string without a second branch.
     */
    public function create(): Response
    {
        return $this->render('farmers/form', [
            'pageTitle' => 'Register Farmer',
            'record'    => null,
            'sections'  => $this->sections(),
            'gpsLng'    => '',
        ]);
    }

    /**
     * Persist a new farmer, with the group membership and officer assignment.
     *
     * Both of those live in their own tables, so the whole write is one
     * transaction: a farmer with no group row would be invisible to the group
     * roll-ups, and a gardener assigned to an officer with no assignment row
     * would never appear on that officer's list. Both are worse than a failed
     * save, because nothing would look broken.
     */
    public function store(): Response
    {
        $this->assertCsrf();

        $sections = $this->sections();
        $fields   = array_merge(...array_column($sections, 'fields'));
        $input    = $this->request->all();

        $validator = Form::validate($fields, $input);
        $validator->unique('national_id', 'farmers', 'national_id', null, 'National ID number');
        $validator->unique('phone', 'farmers', 'phone', null, 'Phone number');

        if ($validator->fails()) {
            return $this->backWithErrors(url('farmers/create'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, [
            'gender', 'date_of_birth', 'national_id', 'alt_phone', 'email', 'profile_photo',
            'region_id', 'district_id', 'sub_county_id', 'parish_id', 'village_id', 'address',
            'latitude', 'longitude', 'group_id', 'officer_id', 'project_code', 'education_level',
            'household_size', 'bank_name', 'bank_account', 'mobile_money', 'notes',
        ]);

        $photo = $this->uploadPhoto();
        $values['profile_photo'] = $photo ?? ($input['profile_photo'] ?? null);

        $id = $this->db->transaction(function () use ($values): int {
            $values['farmer_code']       = Identifier::generate('farmer');
            $values['created_by']        = $this->auth->id();
            $values['registration_date'] = $values['registration_date'] ?? date('Y-m-d');

            $id = $this->db->insert('farmers', $values);

            if (!empty($values['group_id'])) {
                $this->db->insert('farmer_group_members', [
                    'group_id'    => (int) $values['group_id'],
                    'farmer_id'   => $id,
                    'joined_date' => $values['registration_date'] ?? date('Y-m-d'),
                    'status'      => 'active',
                ]);
            }

            if (!empty($values['officer_id'])) {
                $this->db->insert('officer_assignments', [
                    'officer_id'  => (int) $values['officer_id'],
                    'entity_type' => 'farmer',
                    'entity_id'   => $id,
                    'assigned_by' => $this->auth->id(),
                ]);
            }

            return $id;
        });

        $farmer = $this->findOrFail($id);

        Audit::log('create', 'farmers', 'farmer', $id, $farmer['farmer_code'], null, $values);

        if (!empty($values['email'])) {
            $mailer = new \Services\Mailer($this->db);
            $mailer->send(
                $values['email'],
                'Welcome to CAHU Platform',
                "Dear {$values['full_name']},\n\nYour farmer profile has been successfully registered on the CAHU Platform. Your unique Farmer ID is: {$farmer['farmer_code']}.\n\nThank you for joining the Cooperative Agribusiness Hub Uganda."
            );
        }

        $this->notifier->notify([
            'type'        => 'farmer_registered',
            'title'       => 'Farmer registered',
            'message'     => $farmer['full_name'] . ' has been registered as ' . $farmer['farmer_code'] . '.',
            'user_id'     => $this->auth->id(),
            'link'        => url('farmers/' . $farmer['farmer_code']),
            'entity_type' => 'farmer',
            'entity_id'   => $id,
        ]);

        Flash::success('Farmer ' . $farmer['farmer_code'] . ' has been registered.');

        return $this->redirect(url('farmers/' . $farmer['farmer_code']));
    }

    /**
     * The farmer's digital record: performance, gardens, plantings, activity
     * state and the tab counters.
     *
     * Every query filters on the resolved farmer, not on a code from the URL, so
     * the page cannot leak another farmer's data through a mismatched join.
     * `LIMIT 50` on plantings is deliberate: this is a summary page, and the
     * full season history belongs in the crops report.
     */
    public function show(string $code): Response
    {
        $farmer = $this->findByCodeOrFail($code);

        $performance = $this->performance($farmer['id']);

        $gardens = $this->db->select(
            'SELECT g.*, d.name AS district_name
             FROM gardens g LEFT JOIN districts d ON d.id = g.district_id
             WHERE g.farmer_id = :id AND g.deleted_at IS NULL ORDER BY g.name',
            ['id' => $farmer['id']]
        );

        $crops = $this->db->select(
            'SELECT p.*, g.garden_code, g.name AS garden_name, c.name AS crop_name, v.name AS variety_name,
                    s.season_code
             FROM plantings p
             INNER JOIN gardens g ON g.id = p.garden_id
             INNER JOIN crops c ON c.id = p.crop_id
             LEFT JOIN crop_varieties v ON v.id = p.variety_id
             INNER JOIN seasons s ON s.id = p.season_id
             WHERE p.farmer_id = :id ORDER BY p.planting_date DESC LIMIT 50',
            ['id' => $farmer['id']]
        );

        $activitySummary = [
            'completed' => (int) $this->db->scalar("SELECT COUNT(*) FROM crop_activities WHERE farmer_id = :id AND status = 'completed'", ['id' => $farmer['id']]),
            'pending'   => (int) $this->db->scalar("SELECT COUNT(*) FROM crop_activities WHERE farmer_id = :id AND status = 'pending'", ['id' => $farmer['id']]),
            'due'       => (int) $this->db->scalar("SELECT COUNT(*) FROM crop_activities WHERE farmer_id = :id AND status = 'due'", ['id' => $farmer['id']]),
            'overdue'   => (int) $this->db->scalar("SELECT COUNT(*) FROM crop_activities WHERE farmer_id = :id AND status = 'overdue'", ['id' => $farmer['id']]),
        ];

        $expected = (float) $this->db->scalar(
            'SELECT COALESCE(SUM(hf.expected_production), 0) FROM harvest_forecasts hf
             INNER JOIN seasons s ON s.id = hf.season_id WHERE hf.farmer_id = :id AND s.status = \'open\'',
            ['id' => $farmer['id']]
        );

        $actual = (float) $this->db->scalar(
            'SELECT COALESCE(SUM(hr.quantity_harvested), 0) FROM harvest_records hr
             INNER JOIN seasons s ON s.id = hr.season_id WHERE hr.farmer_id = :id AND s.status = \'open\'',
            ['id' => $farmer['id']]
        );

        return $this->render('farmers/show', [
            'pageTitle'        => $farmer['full_name'],
            'farmer'           => $farmer,
            'performance'      => $performance,
            'gardens'          => $gardens,
            'crops'            => $crops,
            'activitySummary'  => $activitySummary,
            'expected'         => $expected,
            'actual'           => $actual,
            'tabs'             => $this->tabs($farmer, $gardens, $crops, $activitySummary),
        ]);
    }

    /** The farmer form, pre-filled. Same view as `create()`: one form, one set of rules. */
    public function edit(string $code): Response
    {
        $farmer = $this->findByCodeOrFail($code);

        return $this->render('farmers/form', [
            'pageTitle' => 'Edit ' . $farmer['farmer_code'],
            'record'    => $farmer,
            'sections'  => $this->sections(),
            'gpsLng'    => (string) ($farmer['longitude'] ?? ''),
        ]);
    }

    /**
     * Update a farmer, reconciling the group and officer side-tables.
     *
     * The previous group and officer are captured before the write and compared
     * as strings, because the form posts `''` for "none" and the column holds
     * NULL. Comparing loosely here would see `NULL != ''` and rewrite the
     * membership on every save, silently resetting `joined_date` and logging a
     * spurious change.
     *
     * The membership rows are only touched when the value actually changed, so
     * an ordinary edit does not churn the join date.
     */
    public function update(string $code): Response
    {
        $this->assertCsrf();

        $farmer = $this->findByCodeOrFail($code);
        $input  = $this->request->all();

        $sections = $this->sections();
        $fields   = array_merge(...array_column($sections, 'fields'));

        $validator = Form::validate($fields, $input);
        $validator->unique('national_id', 'farmers', 'national_id', (int) $farmer['id'], 'National ID number');
        $validator->unique('phone', 'farmers', 'phone', (int) $farmer['id'], 'Phone number');

        if ($validator->fails()) {
            return $this->backWithErrors(url('farmers/' . $code . '/edit'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, [
            'gender', 'date_of_birth', 'national_id', 'alt_phone', 'email', 'profile_photo',
            'region_id', 'district_id', 'sub_county_id', 'parish_id', 'village_id', 'address',
            'latitude', 'longitude', 'group_id', 'officer_id', 'project_code', 'education_level',
            'household_size', 'bank_name', 'bank_account', 'mobile_money', 'notes',
        ]);

        $photo = $this->uploadPhoto();
        if ($photo !== null) {
            $values['profile_photo'] = $photo;
        }

        $previousGroup   = $farmer['group_id'];
        $previousOfficer = $farmer['officer_id'];

        $this->db->transaction(function () use ($values, $farmer, $previousGroup, $previousOfficer): void {
            $this->db->update('farmers', $values, 'id = :id', ['id' => $farmer['id']]);

            if ((string) $previousGroup !== (string) ($values['group_id'] ?? '')) {
                $this->db->delete(
                    'farmer_group_members',
                    'farmer_id = :farmer',
                    ['farmer' => $farmer['id']]
                );

                if (!empty($values['group_id'])) {
                    $this->db->insert('farmer_group_members', [
                        'group_id'    => (int) $values['group_id'],
                        'farmer_id'   => (int) $farmer['id'],
                        'joined_date' => date('Y-m-d'),
                        'status'      => 'active',
                    ]);
                }
            }

            if ((string) $previousOfficer !== (string) ($values['officer_id'] ?? '')) {
                $this->db->delete(
                    'officer_assignments',
                    "officer_id = :officer AND entity_type = 'farmer' AND entity_id = :farmer",
                    ['officer' => $previousOfficer ?: 0, 'farmer' => $farmer['id']]
                );

                if (!empty($values['officer_id'])) {
                    $this->db->insert('officer_assignments', [
                        'officer_id'  => (int) $values['officer_id'],
                        'entity_type' => 'farmer',
                        'entity_id'   => (int) $farmer['id'],
                        'assigned_by' => $this->auth->id(),
                    ]);
                }
            }
        });

        Audit::log('update', 'farmers', 'farmer', (int) $farmer['id'], $farmer['farmer_code'], $farmer, $values);

        Flash::success('Farmer record updated.');

        return $this->redirect(url('farmers/' . $farmer['farmer_code']));
    }

    /**
     * Deactivate a farmer, and only ever that.
     *
     * Refuses while gardens are still registered: the alternative is a farmer
     * record with no gardens, which reads as data loss to the cooperative even
     * though the rows are still there. The `farmer_code` is kept, and the
     * officer assignment is removed so work is not routed to someone who no
     * longer has the farmer.
     */
    public function destroy(string $code): Response
    {
        $this->assertCsrf();
        $farmer = $this->findByCodeOrFail($code);

        $gardens = (int) $this->db->scalar('SELECT COUNT(*) FROM gardens WHERE farmer_id = :id AND deleted_at IS NULL', ['id' => $farmer['id']]);

        if ($gardens > 0) {
            Flash::error('This farmer still has ' . $gardens . ' registered garden(s). Remove or reassign them first so the farm history is preserved.');

            return $this->redirect(url('farmers/' . $farmer['farmer_code']));
        }

        $this->db->update('farmers', ['status' => 'inactive'], 'id = :id', ['id' => $farmer['id']]);
        $this->db->delete('officer_assignments', "entity_type = 'farmer' AND entity_id = :id", ['id' => $farmer['id']]);

        Audit::log('deactivate', 'farmers', 'farmer', (int) $farmer['id'], $farmer['farmer_code'], $farmer, ['status' => 'inactive']);

        Flash::success('Farmer ' . $farmer['farmer_code'] . ' has been deactivated. The identifier is retained and will not be reused.');

        return $this->redirect(url('farmers'));
    }

    /**
     * The whole register as CSV, ignoring the current filters.
     *
     * This is the archival export, not a filtered export: the filtered view is
     * already printable from the register itself. It is logged as `export`
     * because a full contact list leaving the system is worth an audit trail.
     */
    public function export(): Response
    {
        $rows = $this->db->select(
            'SELECT f.farmer_code, f.full_name, f.gender, f.phone, f.alt_phone, f.email,
                    d.name AS district, sc.name AS sub_county, f.village_name,
                    f.registration_date, f.status, f.farmer_type, g.name AS farmer_group,
                    o.full_name AS field_officer, f.latitude, f.longitude
             FROM farmers f
             LEFT JOIN districts d ON d.id = f.district_id
             LEFT JOIN sub_counties sc ON sc.id = f.sub_county_id
             LEFT JOIN farmer_groups g ON g.id = f.group_id
             LEFT JOIN field_officers o ON o.id = f.officer_id
             WHERE f.deleted_at IS NULL ORDER BY f.farmer_code'
        );

        Audit::log('export', 'farmers', 'farmer', null, 'farmer register');

        return $this->csv('farmers-' . date('Y-m-d') . '.csv', [
            'farmer_code', 'full_name', 'gender', 'phone', 'alt_phone', 'email', 'district',
            'sub_county', 'village_name', 'registration_date', 'status', 'farmer_type',
            'farmer_group', 'field_officer', 'latitude', 'longitude',
        ], $rows);
    }

    /**
     * Print layout for the farmer record: no navigation, no chrome.
     *
     * Passed an empty layout so the output can be handed straight to a printer
     * or PDF'd without post-processing.
     */
    public function printView(string $code): Response
    {
        $farmer = $this->findByCodeOrFail($code);

        $gardens = $this->db->select(
            'SELECT * FROM gardens WHERE farmer_id = :id AND deleted_at IS NULL ORDER BY name',
            ['id' => $farmer['id']]
        );

        $crops = $this->db->select(
            'SELECT p.*, g.garden_code, c.name AS crop_name, v.name AS variety_name, s.season_code
             FROM plantings p
             INNER JOIN gardens g ON g.id = p.garden_id
             INNER JOIN crops c ON c.id = p.crop_id
             LEFT JOIN crop_varieties v ON v.id = p.variety_id
             INNER JOIN seasons s ON s.id = p.season_id
             WHERE p.farmer_id = :id ORDER BY p.planting_date DESC',
            ['id' => $farmer['id']]
        );

        return $this->render('farmers/print', [
            'pageTitle' => 'Farmer record - ' . $farmer['farmer_code'],
            'farmer'    => $farmer,
            'gardens'   => $gardens,
            'crops'     => $crops,
        ], '');
    }

    /* ------------------------------------------------------------------ */
    /* Documents                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Documents attached to the farmer (land agreement, ID copy, consent form).
     *
     * The uploader name is joined in rather than looked up per row in the view:
     * the list is rendered once, and a query inside the loop is what turns a
     * twenty-row table into thirty queries.
     */
    public function documents(string $code): Response
    {
        $farmer = $this->findByCodeOrFail($code);

        $documents = $this->db->select(
            'SELECT d.*, u.username AS uploaded_by_name
             FROM farmer_documents d
             LEFT JOIN users u ON u.id = d.uploaded_by
             WHERE d.farmer_id = :id ORDER BY d.created_at DESC',
            ['id' => $farmer['id']]
        );

        return $this->render('farmers/documents', [
            'pageTitle' => 'Documents - ' . $farmer['farmer_code'],
            'farmer'    => $farmer,
            'documents' => $documents,
        ]);
    }

    /**
     * Store an uploaded document and its metadata row.
     *
     * `original_name` comes straight from `$_FILES` and is therefore attacker
     * controlled, so it is truncated to 255 bytes to fit the column and never
     * used as a path. The stored path is the one the uploader generated, which
     * is a random name inside the farmer's own folder.
     */
    public function storeDocument(string $code): Response
    {
        $this->assertCsrf();
        $farmer = $this->findByCodeOrFail($code);

        $uploader = $this->uploader();
        $result   = $uploader->document('document', self::UPLOAD_FOLDER . '/' . $farmer['id']);

        if ($result['success'] !== true) {
            Flash::error($result['error'] ?? 'The document could not be uploaded.');

            return $this->redirect(url('farmers/' . $code . '/documents'));
        }

        $this->db->insert('farmer_documents', [
            'farmer_id'     => $farmer['id'],
            'doc_type'      => $this->request->input('doc_type', 'other'),
            'title'         => $this->request->string('title') ?: null,
            'file_path'     => $result['path'],
            'original_name' => substr((string) ($_FILES['document']['name'] ?? ''), 0, 255),
            'file_size'     => (int) ($_FILES['document']['size'] ?? 0),
            'mime_type'     => (string) ($_FILES['document']['type'] ?? ''),
            'expires_at'    => $this->request->date('expires_at') ?: null,
            'uploaded_by'   => $this->auth->id(),
        ]);

        Audit::log('upload', 'farmers', 'farmer', (int) $farmer['id'], $farmer['farmer_code'], null, ['doc_type' => $this->request->input('doc_type')]);

        Flash::success('Document uploaded.');

        return $this->redirect(url('farmers/' . $code . '/documents'));
    }

    /**
     * Remove a document row and the file behind it.
     *
     * The farmer code is joined in so the redirect can land back on the right
     * farmer's document list without a second lookup.
     */
    public function deleteDocument(string $id): Response
    {
        $this->assertCsrf();

        $document = $this->db->selectOne(
            'SELECT d.*, f.farmer_code FROM farmer_documents d
             INNER JOIN farmers f ON f.id = d.farmer_id
             WHERE d.id = :id',
            ['id' => $id]
        );

        if ($document === null) {
            throw new HttpException(404, 'Document not found.');
        }

        $this->db->delete('farmer_documents', 'id = :id', ['id' => $id]);
        $this->removeFile((string) $document['file_path']);

        Audit::log('delete', 'farmers', 'farmer_document', (int) $id, $document['doc_type']);

        Flash::success('Document removed.');

        return $this->redirect(url('farmers/' . (string) $document['farmer_code'] . '/documents'));
    }

    /* ------------------------------------------------------------------ */
    /* Supporting data                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Configurable participation score. Weights live in system_settings so the
     * indicator reflects an agreed policy rather than arbitrary numbers.
     */
    public function performance(int $farmerId): array
    {
        $weights = [
            'activity_completion' => (float) (\Core\Setting::get('score.weight.activity_completion', 40)),
            'inspection_uptake'   => (float) (\Core\Setting::get('score.weight.inspection_uptake', 15)),
            'input_usage'         => (float) (\Core\Setting::get('score.weight.input_usage', 10)),
            'problem_reporting'   => (float) (\Core\Setting::get('score.weight.problem_reporting', 10)),
            'harvest_submission'  => (float) (\Core\Setting::get('score.weight.harvest_submission', 15)),
            'field_visit'         => (float) (\Core\Setting::get('score.weight.field_visit', 10)),
        ];

        $activities = [
            'completed' => (int) $this->db->scalar("SELECT COUNT(*) FROM crop_activities WHERE farmer_id = :id AND status = 'completed'", ['id' => $farmerId]),
            'total'     => (int) $this->db->scalar("SELECT COUNT(*) FROM crop_activities WHERE farmer_id = :id AND status <> 'cancelled'", ['id' => $farmerId]),
        ];

        $gardens = [
            'inspected' => (int) $this->db->scalar('SELECT COUNT(DISTINCT garden_id) FROM inspections WHERE farmer_id = :id', ['id' => $farmerId]),
            'total'     => (int) $this->db->scalar('SELECT COUNT(*) FROM gardens WHERE farmer_id = :id AND deleted_at IS NULL', ['id' => $farmerId]),
        ];

        $metrics = [
            'activity_completion' => percent($activities['completed'], $activities['total']),
            'inspection_uptake'   => percent($gardens['inspected'], $gardens['total']),
            'input_usage'         => percent(
                (int) $this->db->scalar('SELECT COUNT(DISTINCT input_id) FROM input_usage WHERE farmer_id = :id', ['id' => $farmerId]),
                max(1, (int) $this->db->scalar('SELECT COUNT(*) FROM plantings WHERE farmer_id = :id AND status = \'planted\'', ['id' => $farmerId]))
            ),
            'problem_reporting'   => percent(
                (int) $this->db->scalar('SELECT COUNT(*) FROM problem_reports WHERE farmer_id = :id', ['id' => $farmerId]),
                max(1, (int) $this->db->scalar('SELECT COUNT(*) FROM crop_activities WHERE farmer_id = :id', ['id' => $farmerId]))
            ),
            'harvest_submission'  => percent(
                (int) $this->db->scalar('SELECT COUNT(*) FROM harvest_records WHERE farmer_id = :id', ['id' => $farmerId]),
                max(1, (int) $this->db->scalar('SELECT COUNT(*) FROM plantings WHERE farmer_id = :id AND status <> \'planned\'', ['id' => $farmerId]))
            ),
            'field_visit'         => percent(
                (int) $this->db->scalar('SELECT COUNT(*) FROM field_visits WHERE farmer_id = :id', ['id' => $farmerId]),
                max(1, (int) $this->db->scalar('SELECT COUNT(*) FROM plantings WHERE farmer_id = :id', ['id' => $farmerId]))
            ),
        ];

        $score = 0.0;
        foreach ($weights as $key => $weight) {
            $score += ($metrics[$key] ?? 0) * ($weight / 100);
        }

        return [
            'score'     => round(min(100, $score), 1),
            'grade'     => $this->grade($score),
            'metrics'   => $metrics,
            'weights'   => $weights,
            'activities' => $activities,
            'gardens'   => $gardens,
        ];
    }

    private function grade(float $score): string
    {
        return match (true) {
            $score >= 85 => 'Excellent',
            $score >= 70 => 'Good',
            $score >= 50 => 'Fair',
            $score >= 30 => 'Needs support',
            default      => 'At risk',
        };
    }

    private function tabs(array $farmer, array $gardens, array $crops, array $activitySummary): array
    {
        $code = (string) $farmer['farmer_code'];

        $counts = [
            'gardens'     => count($gardens),
            'crops'       => count($crops),
            'activities'  => $activitySummary['completed'] + $activitySummary['pending'] + $activitySummary['due'] + $activitySummary['overdue'],
            'problems'    => (int) $this->db->scalar('SELECT COUNT(*) FROM problem_reports WHERE farmer_id = :id', ['id' => $farmer['id']]),
            'harvest'     => (int) $this->db->scalar('SELECT COUNT(*) FROM harvest_records WHERE farmer_id = :id', ['id' => $farmer['id']]),
            'documents'   => (int) $this->db->scalar('SELECT COUNT(*) FROM farmer_documents WHERE farmer_id = :id', ['id' => $farmer['id']]),
        ];

        return [
            ['label' => 'Overview',   'url' => url('farmers/' . $code),                'match' => url('farmers/' . $code),                'count' => null],
            ['label' => 'Gardens',    'url' => url('gardens?farmer_id=' . $farmer['id']), 'match' => url('gardens'),                      'count' => $counts['gardens']],
            ['label' => 'Crops',      'url' => url('plantings?farmer_id=' . $farmer['id']), 'match' => url('plantings'),                  'count' => $counts['crops']],
            ['label' => 'Activities', 'url' => url('activities?farmer_id=' . $farmer['id']), 'match' => url('activities'),                'count' => $counts['activities']],
            ['label' => 'Inputs',     'url' => url('input-usage?farmer_id=' . $farmer['id']), 'match' => url('input-usage'),              'count' => null],
            ['label' => 'Inspections', 'url' => url('inspections?farmer_id=' . $farmer['id']), 'match' => url('inspections'),             'count' => null],
            ['label' => 'Problems',   'url' => url('problems?farmer_id=' . $farmer['id']), 'match' => url('problems'),                'count' => $counts['problems']],
            ['label' => 'Harvest',    'url' => url('harvest?farmer_id=' . $farmer['id']), 'match' => url('harvest'),                 'count' => $counts['harvest']],
            ['label' => 'Documents',  'url' => url('farmers/' . $code . '/documents'), 'match' => url('farmers/' . $code . '/documents'), 'count' => $counts['documents']],
        ];
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'title'  => 'Identity',
                'fields' => [
                    ['name' => 'farmer_code', 'label' => 'Farmer ID', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued automatically when the record is saved.'],
                    ['name' => 'full_name', 'label' => 'Full name', 'type' => Form::TEXT, 'required' => true, 'colspan' => 6],
                    ['name' => 'gender', 'label' => 'Gender', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions("'male','female','other'", 'Not stated')],
                    ['name' => 'date_of_birth', 'label' => 'Date of birth', 'type' => Form::DATE, 'colspan' => 6],
                    ['name' => 'national_id', 'label' => 'National ID number', 'type' => Form::TEXT, 'colspan' => 6],
                    ['name' => 'phone', 'label' => 'Phone number', 'type' => Form::TEL, 'required' => true, 'colspan' => 6,
                     'help' => 'Used for SMS reminders and advisories.'],
                    ['name' => 'alt_phone', 'label' => 'Alternative phone', 'type' => Form::TEL, 'colspan' => 6],
                    ['name' => 'email', 'label' => 'Email address', 'type' => Form::EMAIL, 'colspan' => 6],
                    ['name' => 'profile_photo', 'label' => 'Profile photo', 'type' => Form::FILE, 'colspan' => 12,
                     'accept' => 'image/jpeg,image/png', 'filetype' => 'image',
                     'help' => 'JPEG or PNG, up to 4 MB.'],
                ],
            ],
            [
                'title'  => 'Location',
                'fields' => [
                    ['name' => 'region_id', 'label' => 'Region', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::options($this->lookups->regions(), 'id', 'name', 'Select region...'),
                     'table' => 'regions'],
                    ['name' => 'district_id', 'label' => 'District', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->districtOptions('Select district...'), 'table' => 'districts'],
                    ['name' => 'sub_county_id', 'label' => 'Sub-county', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->subCountyOptions('Select sub-county...'), 'table' => 'sub_counties'],
                    ['name' => 'parish_id', 'label' => 'Parish', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->parishOptions('Select parish...'), 'table' => 'parishes'],
                    ['name' => 'village_id', 'label' => 'Village', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->villageOptions('Select village...'), 'table' => 'villages'],
                    ['name' => 'village_name', 'label' => 'Village / site description', 'type' => Form::TEXT, 'colspan' => 6,
                     'help' => 'Free text when the village is not in the register.'],
                    ['name' => 'latitude', 'label' => 'GPS location', 'type' => Form::GPS, 'colspan' => 12,
                     'help' => 'Where the farmer lives or operates.'],
                ],
            ],
            [
                'title'  => 'Membership and status',
                'fields' => [
                    ['name' => 'registration_date', 'label' => 'Registration date', 'type' => Form::DATE, 'required' => true, 'colspan' => 6],
                    ['name' => 'status', 'label' => 'Farmer status', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions("'active','inactive','pending','deceased','exited'", '')],
                    ['name' => 'farmer_type', 'label' => 'Farmer type', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions("'smallholder','commercial','youth','woman','special_needs'", '')],
                    ['name' => 'group_id', 'label' => 'Cooperative / group', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->groupOptions('Not in a group'), 'table' => 'farmer_groups'],
                    ['name' => 'officer_id', 'label' => 'Assigned field officer', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->officerOptions(), 'table' => 'field_officers'],
                    ['name' => 'project_code', 'label' => 'Programme / project', 'type' => Form::TEXT, 'colspan' => 6],
                    ['name' => 'education_level', 'label' => 'Education level', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions("'none','primary','secondary','tertiary','other'", 'Not stated')],
                    ['name' => 'household_size', 'label' => 'Household size', 'type' => Form::NUMBER, 'min' => 1, 'max' => 50, 'colspan' => 6],
                    ['name' => 'bank_name', 'label' => 'Bank', 'type' => Form::TEXT, 'colspan' => 6],
                    ['name' => 'bank_account', 'label' => 'Bank account', 'type' => Form::TEXT, 'colspan' => 6],
                    ['name' => 'mobile_money', 'label' => 'Mobile money number', 'type' => Form::TEXT, 'colspan' => 6],
                    ['name' => 'notes', 'label' => 'Notes', 'type' => Form::TEXTAREA, 'colspan' => 12],
                ],
            ],
        ];
    }

    private function groupOptions(string $placeholder = 'Select group...'): array
    {
        return Form::options(
            $this->db->select("SELECT id, name FROM farmer_groups WHERE status = 'active' AND deleted_at IS NULL ORDER BY name"),
            'id',
            'name',
            $placeholder
        );
    }

    /**
     * Handle an optional profile photo upload.
     *
     * Returns null in three different situations, and the callers need to tell
     * them apart:
     *   - no file was sent at all, so the existing photo must be left alone;
     *   - the upload was refused (wrong type, too large), where the user is
     *     warned and the rest of the form still saves;
     *   - it succeeded, and the new path should replace the old one.
     */
    private function uploadPhoto(): ?string
    {
        if (!isset($_FILES['profile_photo']) || ($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $result = $this->uploader()->image('profile_photo', self::UPLOAD_FOLDER, $this->maxImageKb());

        if ($result['success'] !== true) {
            Flash::warning($result['error'] ?? 'The photo could not be uploaded.');
        }

        return $result['success'] === true ? $result['path'] : null;
    }

    private function uploader(): Uploader
    {
        return new Uploader($this->config('uploads.path'), $this->config('uploads.url'));
    }

    private function maxImageKb(): int
    {
        return (int) $this->config('uploads.max_image_kb', 4096);
    }

    /**
     * Delete a file from disk, given its public URL.
     *
     * The public URL is mapped back to a filesystem path by stripping the
     * configured URL prefix, so the two can be configured independently (a
     * CDN or subfolder in front of the uploads directory). `is_file` guards the
     * unlink: a missing file is not an error worth surfacing to the user, and
     * the row is already gone.
     */
    private function removeFile(string $path): void
    {
        $file = (string) $this->config('uploads.path') . '/' . ltrim(
            str_replace((string) $this->config('uploads.url'), '', $path),
            '/'
        );

        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Load one farmer by its human-facing code, with every lookup label joined
     * in, or fail with a 404.
     *
     * The code is what appears in URLs, emails and printed records, so it is
     * the key used for lookup rather than the numeric id. Soft-deleted rows are
     * excluded, which is why `deleted_at IS NULL` appears here even though the
     * table is never hard-deleted from.
     */
    private function findByCodeOrFail(string $code): array
    {
        $farmer = $this->db->selectOne(
            'SELECT f.*, g.name AS group_name, o.officer_code, o.full_name AS officer_name,
                    r.name AS region_name, d.name AS district_name, sc.name AS sub_county_name,
                    p.name AS parish_name, v.name AS registered_village_name
             FROM farmers f
             LEFT JOIN farmer_groups g ON g.id = f.group_id
             LEFT JOIN field_officers o ON o.id = f.officer_id
             LEFT JOIN regions r ON r.id = f.region_id
             LEFT JOIN districts d ON d.id = f.district_id
             LEFT JOIN sub_counties sc ON sc.id = f.sub_county_id
             LEFT JOIN parishes p ON p.id = f.parish_id
             LEFT JOIN villages v ON v.id = f.village_id
             WHERE f.farmer_code = :code AND f.deleted_at IS NULL LIMIT 1',
            ['code' => $code]
        );

        if ($farmer === null) {
            throw new HttpException(404, 'No farmer found with the ID ' . $code . '.');
        }

        return $farmer;
    }

    private function findOrFail(int $id): array
    {
        $farmer = $this->db->selectOne('SELECT * FROM farmers WHERE id = :id', ['id' => $id]);

        if ($farmer === null) {
            throw new HttpException(404, 'The farmer record could not be found.');
        }

        return $farmer;
    }

    /**
     * Reject a state-changing request that arrived without a valid CSRF token.
     *
     * 419 rather than 403: the usual cause is an expired session, and telling
     * the user to sign in again is more useful than telling them they are
     * forbidden.
     */
    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }
}
