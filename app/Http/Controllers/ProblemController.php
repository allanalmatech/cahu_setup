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
use Services\Lookups;
use Services\Notifier;

/**
 * Problem and incident reports.
 *
 * A problem report is what turns an agronomic worry into a tracked obligation.
 * Unlike an inspection, which is an observation, a problem carries a status
 * that the cooperative is answerable for: it is reported, then someone is
 * accountable for it, then something is done, then it is resolved. The status
 * machine below is the single place that decides which transition is legal, so
 * no screen can quietly move a report backwards or skip the person who has to
 * act on it.
 *
 * Reports are never hard deleted. They are the evidence that a farmer raised a
 * concern, and that evidence outlives the concern itself.
 */
final class ProblemController extends Controller
{
    /**
     * The problem_reports.status ENUM, in the order a report moves through them.
     */
    private const STATUSES = "'reported','assigned','investigating','action_taken','resolved','closed'";

    /**
     * Legal status transitions. A report can be closed, and a closed report can
     * be reopened while someone is still chasing it, but a resolved report
     * cannot silently become 'reported' again because that would erase the fact
     * that someone dealt with it.
     */
    private const TRANSITIONS = [
        'reported'      => ['assigned', 'investigating', 'action_taken', 'resolved', 'closed'],
        'assigned'      => ['investigating', 'action_taken', 'resolved', 'closed'],
        'investigating' => ['action_taken', 'resolved', 'closed'],
        'action_taken'  => ['resolved', 'investigating', 'closed'],
        'resolved'      => ['closed', 'investigating'],
        'closed'        => ['investigating'],
    ];

    private Lookups $lookups;
    private Notifier $notifier;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups  = new Lookups($db);
        $this->notifier = new Notifier($db);
    }

    /* ------------------------------------------------------------------ */
    /* Register                                                             */
    /* ------------------------------------------------------------------ */

    public function index(): Response
    {
        $params = $this->listParams();

        $values = [];
        $where  = ['1 = 1' . $this->scopeWhere($values)];

        if ($params['search'] !== '') {
            $where[]      = '(pr.problem_code LIKE :s OR f.full_name LIKE :s OR f.farmer_code LIKE :s
                             OR g.name LIKE :s OR g.garden_code LIKE :s OR pr.description LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        foreach (['status' => 'pr.status', 'severity' => 'pr.severity', 'problem_type_id' => 'pr.problem_type_id'] as $key => $column) {
            if (($params['filters'][$key] ?? '') !== '') {
                $where[]      = $column . ' = :' . $key;
                $values[$key] = $params['filters'][$key];
            }
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total     = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM problem_reports pr
             INNER JOIN farmers f ON f.id = pr.farmer_id
             LEFT JOIN gardens g ON g.id = pr.garden_id' . $whereSql,
            $values
        );
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        // Open and critical reports first: the register exists to work a queue,
        // not to read a diary.
        $sortable = ['report_date', 'severity', 'status', 'problem_code'];
        $column   = in_array($params['sort'], $sortable, true) ? $params['sort'] : 'report_date';
        $order    = $params['direction'] === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->db->select(
            'SELECT pr.*, f.full_name AS farmer_name, f.farmer_code,
                    g.garden_code, g.name AS garden_name,
                    pt.name AS problem_type_name, pt.category AS problem_category, pt.is_critical,
                    o.full_name AS officer_name,
                    (SELECT COUNT(*) FROM photos ph WHERE ph.entity_type = :photo_type AND ph.entity_id = pr.id) AS photo_count
             FROM problem_reports pr
             INNER JOIN farmers f ON f.id = pr.farmer_id
             LEFT JOIN gardens g ON g.id = pr.garden_id
             LEFT JOIN problem_types pt ON pt.id = pr.problem_type_id
             LEFT JOIN field_officers o ON o.id = pr.assigned_officer_id'
            . $whereSql
            . ' ORDER BY (pr.status IN (\'reported\',\'assigned\',\'investigating\')) DESC,
                       FIELD(pr.severity, \'critical\',\'high\',\'medium\',\'low\'),
                       pr.report_date ' . $order
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values + ['photo_type' => 'problem_report']
        );

        return $this->render('problems/index', [
            'pageTitle' => 'Problem reports',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $params['sort'],
            'direction' => $params['direction'],
            'summary'   => $this->summary(),
            'filters'   => [
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions(self::STATUSES, 'All statuses')],
                ['key' => 'severity', 'label' => 'Severity', 'options' => Form::enumOptions("'low','medium','high','critical'", 'Any severity')],
                ['key' => 'problem_type_id', 'label' => 'Problem type', 'options' => $this->lookups->problemTypeOptions('All problem types')],
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Report a problem                                                     */
    /* ------------------------------------------------------------------ */

    public function create(): Response
    {
        $gardenId = $this->request->int('garden_id');

        $record = null;

        if ($gardenId > 0) {
            $garden = $this->db->selectOne(
                'SELECT g.id, g.farmer_id, g.garden_code, g.name
                 FROM gardens g WHERE g.id = :id AND g.deleted_at IS NULL LIMIT 1',
                ['id' => $gardenId]
            );

            if ($garden !== null) {
                $record = [
                    'garden_id' => $garden['id'],
                    'farmer_id' => $garden['farmer_id'],
                ];
            }
        }

        return $this->render('problems/form', [
            'pageTitle' => 'Report a problem',
            'record'    => $record,
            'sections'  => $this->sections(),
            'gpsLng'    => '',
        ]);
    }

    public function store(): Response
    {
        $this->assertCsrf();

        $post   = $this->request->all();
        $fields = array_merge(...array_column($this->sections(), 'fields'));

        $validator = Form::validate($fields, $post);

        if ($validator->fails()) {
            return $this->backWithErrors(url('problems/create'), $validator->errors(), $post);
        }

        $farmer = $this->farmerFor($post);

        $values = Form::extract($fields, $post, $this->nullableColumns());

        // The report inherits the farmer's location context from the garden when
        // the report itself did not state one, so a map view does not show a
        // farmer-level problem floating in an unknown district.
        $garden = $this->gardenContext($values['garden_id'] ?? null);

        if ($garden !== null) {
            $values['region_id']   = $values['region_id'] ?? ($garden['region_id'] ?? null);
            $values['district_id'] = $values['district_id'] ?? ($garden['district_id'] ?? null);
        }

        $values['problem_code'] = Identifier::generate('problem_report', $this->dateArg($values['report_date'] ?? null));
        $values['farmer_id']    = (int) $farmer['id'];
        $values['status']       = 'reported';

        $type = $this->db->selectOne(
            'SELECT name, is_critical FROM problem_types WHERE id = :id LIMIT 1',
            ['id' => (int) $values['problem_type_id']]
        );

        // A type the register marks as critical always starts critical. Letting
        // the reporter downgrade it would hide an outbreak behind a mild label.
        if ($type !== null && (int) $type['is_critical'] === 1) {
            $values['severity'] = 'critical';
        }

        $id = $this->db->insert('problem_reports', $values);

        Audit::log('create', 'problems', 'problem_report', $id, (string) $values['problem_code'], null, $values);

        $this->storePhoto($id, $post);

        // A reported problem with nobody on it is a problem nobody is working on,
        // so both sides are told: the farmer that their report was received and
        // the officer that it arrived.
        $this->notifier->notify([
            'type'        => 'problem_reported',
            'title'       => 'Problem reported',
            'message'     => sprintf(
                '%s was reported on %s and recorded as %s severity.',
                (string) ($type['name'] ?? 'A problem'),
                format_date((string) $values['report_date']),
                (string) $values['severity']
            ),
            'farmer_id'   => (int) $values['farmer_id'],
            'officer_id'  => $values['assigned_officer_id'] ?? null,
            'link'        => url('problems/' . $values['problem_code']),
            'entity_type' => 'problem_report',
            'entity_id'   => $id,
            'dedupe_key'  => 'problem_reported:' . $values['problem_code'],
        ]);

        Flash::success('Problem report ' . $values['problem_code'] . ' has been logged and queued for follow-up.');

        return $this->redirect(url('problems/' . $values['problem_code']));
    }

    /* ------------------------------------------------------------------ */
    /* Detail                                                               */
    /* ------------------------------------------------------------------ */

    public function show(string $code): Response
    {
        $problem = $this->findByCodeOrFail($code);
        $this->assertScope($problem);

        $photos = $this->db->select(
            'SELECT * FROM photos WHERE entity_type = :type AND entity_id = :id ORDER BY id DESC',
            ['type' => 'problem_report', 'id' => $problem['id']]
        );

        // An inspection of the same garden around the same time is usually the
        // evidence behind a report, so it is surfaced rather than left in
        // another register for someone to go and find.
        $relatedInspections = $this->db->select(
            'SELECT inspection_code, inspection_date, crop_condition, pest_status, disease_status, officer_id
             FROM inspections
             WHERE garden_id = :garden AND inspection_date BETWEEN DATE_SUB(:from_date, INTERVAL 7 DAY) AND :to_date
             ORDER BY inspection_date DESC LIMIT 5',
            [
                'garden'   => $problem['garden_id'] ?? 0,
                'from_date' => (string) $problem['report_date'],
                'to_date'  => (string) $problem['report_date'],
            ]
        );

        return $this->render('problems/show', [
            'pageTitle'          => 'Problem ' . $code,
            'problem'            => $problem,
            'photos'             => $photos,
            'relatedInspections' => $relatedInspections,
            'nextStatuses'       => self::TRANSITIONS[(string) $problem['status']] ?? [],
            // Anyone who can file a report can also see what still needs doing on
            // one, and an officer only sees reports in their own scope anyway.
            'canManage'          => $this->auth->can('problems.manage'),
            'officerOptions'     => $this->lookups->officerOptions('Leave unassigned'),
        ]);
    }

    public function edit(string $code): Response
    {
        $problem = $this->findByCodeOrFail($code);
        $this->assertScope($problem);

        return $this->render('problems/form', [
            'pageTitle' => 'Edit ' . $code,
            'record'    => $problem,
            'sections'  => $this->sections(),
            'gpsLng'    => (string) ($problem['longitude'] ?? ''),
        ]);
    }

    public function update(string $code): Response
    {
        $this->assertCsrf();

        $problem = $this->findByCodeOrFail($code);
        $this->assertScope($problem);

        $post   = $this->request->all();
        $fields = array_merge(...array_column($this->sections(), 'fields'));

        $validator = Form::validate($fields, $post);

        if ($validator->fails()) {
            return $this->backWithErrors(url('problems/' . $code . '/edit'), $validator->errors(), $post);
        }

        $values = Form::extract($fields, $post, $this->nullableColumns());

        $garden = $this->gardenContext($values['garden_id'] ?? null);

        if ($garden !== null) {
            $values['region_id']   = $values['region_id'] ?? ($garden['region_id'] ?? null);
            $values['district_id'] = $values['district_id'] ?? ($garden['district_id'] ?? null);
        }

        // Status moves through updateStatus, which enforces the machine and
        // writes the resolution timestamp. Letting it ride along with the form
        // post would let a crafted request skip a step.
        unset($values['status']);

        $before = $problem;

        $this->db->update('problem_reports', $values, 'id = :id', ['id' => $problem['id']]);

        Audit::log('update', 'problems', 'problem_report', (int) $problem['id'], $code, $before, $values);

        Flash::success('Problem report ' . $code . ' has been updated.');

        return $this->redirect(url('problems/' . $code));
    }

    /* ------------------------------------------------------------------ */
    /* Workflow                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * The only way a report changes status. Enforces the transition table and
     * keeps the resolution stamp and the resolver in step with it, so a report
     * can never read "resolved" with nobody accountable for it.
     */
    public function updateStatus(string $code): Response
    {
        $this->assertCsrf();

        $problem = $this->findByCodeOrFail($code);
        $this->assertScope($problem);

        $from    = (string) $problem['status'];
        $to      = $this->request->string('status');
        $allowed = self::TRANSITIONS[$from] ?? [];

        if ($to === '' || !in_array($to, $allowed, true)) {
            Flash::error(sprintf(
                'A report at "%s" cannot move to "%s". The next step from here is %s.',
                ucwords(str_replace('_', ' ', $from)),
                $to === '' ? 'nothing' : strtolower(str_replace('_', ' ', $to)),
                $allowed === []
                    ? 'nowhere: this report is at the end of its workflow'
                    : implode(', ', array_map(
                        static fn (string $status): string => strtolower(str_replace('_', ' ', $status)),
                        $allowed
                    ))
            ));

            return $this->redirect(url('problems/' . $code));
        }

        $values = ['status' => $to];

        $actionTaken = $this->request->string('action_taken');
        $resolution  = $this->request->string('resolution');

        if ($actionTaken !== '' && $from !== 'resolved' && $to !== 'assigned' && $to !== 'reported') {
            $values['action_taken'] = $actionTaken;
        }

        if ($resolution !== '') {
            $values['resolution'] = $resolution;
        }

        if ($to === 'resolved') {
            // Resolving without saying what was done produces a report that
            // looks closed and tells the farmer nothing.
            if (($values['action_taken'] ?? $problem['action_taken'] ?? '') === ''
                && ($values['resolution'] ?? '') === ''
                && (string) ($problem['resolution'] ?? '') === '') {
                Flash::error('Record what was done about this problem before marking it resolved.');

                return $this->redirect(url('problems/' . $code));
            }

            $values['resolved_at'] = date('Y-m-d H:i:s');
            $values['resolved_by'] = $this->auth->id();
        }

        if ($from === 'resolved' || $from === 'closed') {
            $values['resolved_at'] = null;
            $values['resolved_by'] = null;
        }

        if (($values['assigned_officer_id'] ?? null) === null) {
            $officerId = $this->request->int('assigned_officer_id');

            if ($officerId > 0) {
                $values['assigned_officer_id'] = $officerId;
            }
        }

        // A report can only sit in "assigned" if somebody is actually assigned.
        if ($to === 'assigned') {
            $officerId = $values['assigned_officer_id'] ?? $problem['assigned_officer_id'];

            if ($officerId === null) {
                Flash::error('Choose the officer who will own this report before assigning it.');

                return $this->redirect(url('problems/' . $code));
            }

            $values['assigned_officer_id'] = (int) $officerId;
        }

        $before = $problem;

        $this->db->update('problem_reports', $values, 'id = :id', ['id' => $problem['id']]);

        Audit::log('status_change', 'problems', 'problem_report', (int) $problem['id'], $code, $before, $values);

        // The farmer raised this, so they hear how it moved and what was done.
        // Silence after a report is the failure mode this workflow exists to
        // prevent.
        $farmerId = (int) $problem['farmer_id'];

        if ($to === 'resolved') {
            $this->notifier->notify([
                'type'        => 'problem_resolved',
                'title'       => 'Problem resolved',
                'message'     => sprintf(
                    'The %s you reported (%s) has been resolved.%s',
                    (string) ($problem['problem_type_name'] ?? 'problem'),
                    $code,
                    (string) ($values['resolution'] ?? $problem['resolution'] ?? '') !== ''
                        ? ' ' . (string) ($values['resolution'] ?? $problem['resolution'])
                        : ''
                ),
                'farmer_id'   => $farmerId,
                'link'        => url('problems/' . $code),
                'entity_type' => 'problem_report',
                'entity_id'   => (int) $problem['id'],
                'dedupe_key'  => 'problem_resolved:' . $code,
            ]);
        } else {
            $this->notifier->notify([
                'type'        => 'problem_updated',
                'title'       => 'Problem update',
                'message'     => sprintf(
                    'Your report %s is now %s.',
                    $code,
                    strtolower(str_replace('_', ' ', $to))
                ),
                'farmer_id'   => $farmerId,
                'link'        => url('problems/' . $code),
                'entity_type' => 'problem_report',
                'entity_id'   => (int) $problem['id'],
                'dedupe_key'  => 'problem_updated:' . $code . ':' . $to,
            ]);
        }

        Flash::success(sprintf(
            'Report %s moved from %s to %s.',
            $code,
            strtolower(str_replace('_', ' ', $from)),
            strtolower(str_replace('_', ' ', $to))
        ));

        return $this->redirect(url('problems/' . $code));
    }

    /**
     * Reports are never removed. A withdrawal keeps the report and its history
     * intact but takes it out of the working queue, which is the closest thing
     * the workflow allows to "undo".
     */
    public function destroy(string $code): Response
    {
        $this->assertCsrf();

        $problem = $this->findByCodeOrFail($code);
        $this->assertScope($problem);

        if (!in_array((string) $problem['status'], ['closed'], true)) {
            $this->db->update('problem_reports', ['status' => 'closed'], 'id = :id', ['id' => $problem['id']]);

            Audit::log('delete', 'problems', 'problem_report', (int) $problem['id'], $code, $problem, ['status' => 'closed']);

            Flash::success(
                'Report ' . $code . ' has been closed. It stays on the record but is taken out of the working queue.'
            );

            return $this->redirect(url('problems'));
        }

        Flash::info('Report ' . $code . ' is already closed. Reports are retained as evidence and cannot be deleted.');

        return $this->redirect(url('problems/' . $code));
    }

    /* ------------------------------------------------------------------ */
    /* Field definitions                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'title'  => 'What is wrong',
                'fields' => [
                    ['name' => 'problem_code', 'label' => 'Report ID', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued automatically and never reused.'],
                    ['name' => 'problem_type_id', 'label' => 'Problem type', 'type' => Form::SELECT, 'required' => true,
                     'colspan' => 6, 'options' => $this->lookups->problemTypeOptions(), 'table' => 'problem_types',
                     'help' => 'Pick the closest match. An extension officer can refine it later.'],
                    ['name' => 'severity', 'label' => 'Severity', 'type' => Form::SELECT, 'required' => true,
                     'colspan' => 6, 'options' => Form::enumOptions("'low','medium','high','critical'", ''),
                     'help' => 'Critical problem types always record as critical.'],
                    ['name' => 'description', 'label' => 'What happened', 'type' => Form::TEXTAREA, 'required' => true,
                     'colspan' => 12, 'rows' => 4,
                     'placeholder' => 'Describe what you saw: what is affected, how much, and since when.',
                     'help' => 'Write it as a farmer would say it. This text is what an extension officer reads first.'],
                    ['name' => 'report_date', 'label' => 'Date noticed', 'type' => Form::DATE, 'required' => true,
                     'colspan' => 6, 'default' => date('Y-m-d')],
                    ['name' => 'affected_area', 'label' => 'Area affected (acres)', 'type' => Form::NUMBER,
                     'colspan' => 6, 'min' => 0, 'step' => 0.001],
                ],
            ],
            [
                'title'  => 'Where and who',
                'fields' => [
                    ['name' => 'farmer_id', 'label' => 'Farmer', 'type' => Form::SELECT, 'required' => true,
                     'colspan' => 6, 'options' => $this->lookups->farmerOptions(), 'table' => 'farmers'],
                    ['name' => 'garden_id', 'label' => 'Garden', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->gardenOptions($this->auth->farmerId()), 'table' => 'gardens',
                     'help' => 'The garden is checked against the farmer when the report is saved.'],
                    ['name' => 'crop_id', 'label' => 'Crop', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->cropOptions('Not crop specific'), 'table' => 'crops'],
                    ['name' => 'season_id', 'label' => 'Season', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->seasonOptions('Not season specific')],
                    ['name' => 'region_id', 'label' => 'Region', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->regionOptions('Not recorded'), 'table' => 'regions'],
                    ['name' => 'district_id', 'label' => 'District', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->districtOptions('Not recorded'), 'table' => 'districts'],
                    ['name' => 'planting_id', 'label' => 'Planting', 'type' => Form::SELECT, 'colspan' => 12,
                     'options' => $this->plantingOptions()],
                ],
            ],
            [
                'title'  => 'Evidence',
                'fields' => [
                    ['name' => 'latitude', 'label' => 'GPS location', 'type' => Form::GPS, 'colspan' => 12,
                     'help' => 'Capture the spot, or type the coordinates, so the report can be found on the map.'],
                    ['name' => 'assigned_officer_id', 'label' => 'Assigned officer', 'type' => Form::SELECT,
                     'colspan' => 6, 'options' => $this->lookups->officerOptions('Not yet assigned'),
                     'table' => 'field_officers'],
                    ['name' => 'photo', 'label' => 'Photo evidence', 'type' => Form::FILE, 'colspan' => 6,
                     'accept' => 'image/*'],
                ],
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function nullableColumns(): array
    {
        // latitude and longitude are absent because a GPS field is expanded into
        // both columns by Form::extract and always resolves to a value or null.
        return [
            'garden_id', 'crop_id', 'season_id', 'region_id', 'district_id', 'planting_id',
            'assigned_officer_id', 'affected_area',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Lookups and guards                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function plantingOptions(): array
    {
        $rows = $this->db->select(
            'SELECT p.id, p.planting_code, c.name AS crop_name
             FROM plantings p
             INNER JOIN crops c ON c.id = p.crop_id
             WHERE p.status <> \'terminated\'
             ORDER BY p.planting_date DESC LIMIT 300'
        );

        $options = [['value' => '', 'label' => 'Not linked to a planting']];

        foreach ($rows as $row) {
            $options[] = [
                'value' => (string) $row['id'],
                'label' => $row['crop_name'] . ' — ' . $row['planting_code'],
            ];
        }

        return $options;
    }

    /**
     * Resolves the farmer a report is filed against. A garden always implies
     * its owner, so the submitted farmer field can never point a garden-level
     * problem at the wrong person.
     *
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private function farmerFor(array $post): array
    {
        $gardenId = (int) ($post['garden_id'] ?? 0);

        if ($gardenId > 0) {
            $garden = $this->db->selectOne(
                'SELECT id, farmer_id, garden_code FROM gardens WHERE id = :id AND deleted_at IS NULL LIMIT 1',
                ['id' => $gardenId]
            );

            if ($garden === null) {
                throw new HttpException(404, 'That garden could not be found.');
            }

            if ($this->auth->farmerId() !== null && (int) $garden['farmer_id'] !== $this->auth->farmerId()) {
                throw new HttpException(404, 'That garden could not be found.');
            }

            return $garden;
        }

        $farmerId = (int) ($post['farmer_id'] ?? 0);

        $farmer = $this->db->selectOne(
            'SELECT id, farmer_code FROM farmers WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $farmerId]
        );

        if ($farmer === null) {
            throw new HttpException(404, 'That farmer could not be found.');
        }

        if ($this->auth->farmerId() !== null && (int) $farmer['id'] !== $this->auth->farmerId()) {
            throw new HttpException(404, 'That farmer could not be found.');
        }

        return $farmer;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function gardenContext(mixed $gardenId): ?array
    {
        if (!is_numeric($gardenId) || (int) $gardenId <= 0) {
            return null;
        }

        return $this->db->selectOne(
            'SELECT id, farmer_id, region_id, district_id FROM gardens WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => (int) $gardenId]
        );
    }

    /**
     * Photo evidence, attached to the report. A failed upload does not lose the
     * report: a farmer reporting a problem should never lose their description
     * because a photo would not attach.
     *
     * @param array<string,mixed> $post
     */
    private function storePhoto(int $problemId, array $post): void
    {
        if (!isset($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return;
        }

        $result = (new \Core\Uploader(
            (string) $this->config('uploads.path'),
            (string) $this->config('uploads.url')
        ))->image('photo', 'problems', (int) $this->config('uploads.max_image_kb', 4096));

        if ($result['success'] !== true) {
            Flash::warning('The report was saved, but the photo could not be attached.');

            return;
        }

        [$latitude, $longitude] = $this->gps($post);

        $this->db->insert('photos', [
            'entity_type'   => 'problem_report',
            'entity_id'     => $problemId,
            'path'          => (string) $result['path'],
            'caption'       => 'Problem evidence',
            'is_geo_tagged' => $latitude !== null ? 1 : 0,
            'latitude'      => $latitude,
            'longitude'     => $longitude,
            'uploaded_by'   => $this->auth->id(),
        ]);
    }

    /**
     * Counts for the summary strip, scoped the same way as the register.
     *
     * @return array<string,int>
     */
    private function summary(): array
    {
        $values = [];
        $scope  = $this->scopeWhere($values);

        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS total,
                    SUM(pr.status IN (\'reported\',\'assigned\',\'investigating\')) AS open_count,
                    SUM(pr.severity = \'critical\' AND pr.status <> \'closed\') AS critical,
                    SUM(pr.status = \'reported\') AS unassigned
             FROM problem_reports pr WHERE 1 = 1' . $scope,
            $values
        ) ?? [];

        return [
            'total'      => (int) ($row['total'] ?? 0),
            'open'       => (int) ($row['open_count'] ?? 0),
            'critical'   => (int) ($row['critical'] ?? 0),
            'unassigned' => (int) ($row['unassigned'] ?? 0),
        ];
    }

    /**
     * Farmers see their own reports; officers see what they are accountable for
     * plus anything still unassigned, so a report filed outside working hours
     * is never stranded.
     *
     * @param array<string,mixed> $values
     */
    private function scopeWhere(array &$values): string
    {
        $farmerId  = $this->auth->farmerId();
        $officerId = $this->auth->officerId();

        if ($farmerId !== null) {
            $values['scope_farmer'] = $farmerId;

            return ' AND pr.farmer_id = :scope_farmer';
        }

        if ($officerId !== null) {
            $values['scope_officer'] = $officerId;

            return ' AND (pr.assigned_officer_id = :scope_officer OR pr.assigned_officer_id IS NULL)';
        }

        return '';
    }

    /**
     * @param array<string,mixed> $problem
     */
    private function assertScope(array $problem): void
    {
        $farmerId  = $this->auth->farmerId();
        $officerId = $this->auth->officerId();

        if ($farmerId !== null && (int) $problem['farmer_id'] !== $farmerId) {
            throw new HttpException(404, 'That problem report could not be found.');
        }

        if ($officerId === null) {
            return;
        }

        // An officer sees what is assigned to them. Reports nobody has picked up
        // yet are visible too, otherwise a report filed outside working hours
        // would sit unread until a manager noticed it.
        if ($problem['assigned_officer_id'] !== null && (int) $problem['assigned_officer_id'] === $officerId) {
            return;
        }

        if ($problem['assigned_officer_id'] !== null) {
            throw new HttpException(404, 'That problem report could not be found.');
        }

        $assigned = $this->db->scalar(
            'SELECT 1 FROM farmers WHERE id = :id AND officer_id = :officer LIMIT 1',
            ['id' => $problem['farmer_id'], 'officer' => $officerId]
        );

        if ($assigned === null) {
            throw new HttpException(404, 'That problem report could not be found.');
        }
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }

    private function dateArg(mixed $value): ?\DateTimeInterface
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return $date === false ? null : $date;
    }

    /**
     * @return array<string,mixed>
     */
    private function findByCodeOrFail(string $code): array
    {
        $problem = $this->db->selectOne(
            'SELECT pr.*, f.full_name AS farmer_name, f.farmer_code, f.phone,
                    g.garden_code, g.name AS garden_name,
                    c.name AS crop_name, s.season_code, p.planting_code,
                    r.name AS region_name, d.name AS district_name,
                    pt.name AS problem_type_name, pt.category AS problem_category, pt.is_critical,
                    o.full_name AS officer_name, o.phone AS officer_phone
             FROM problem_reports pr
             INNER JOIN farmers f ON f.id = pr.farmer_id
             LEFT JOIN gardens g ON g.id = pr.garden_id
             LEFT JOIN crops c ON c.id = pr.crop_id
             LEFT JOIN seasons s ON s.id = pr.season_id
             LEFT JOIN plantings p ON p.id = pr.planting_id
             LEFT JOIN regions r ON r.id = pr.region_id
             LEFT JOIN districts d ON d.id = pr.district_id
             LEFT JOIN problem_types pt ON pt.id = pr.problem_type_id
             LEFT JOIN field_officers o ON o.id = pr.assigned_officer_id
             WHERE pr.problem_code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($problem === null) {
            throw new HttpException(404, 'No problem report found with the ID ' . $code . '.');
        }

        return $problem;
    }
}