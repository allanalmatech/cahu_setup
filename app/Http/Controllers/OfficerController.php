<?php
declare(strict_types=1);

namespace Http\Controllers;

use Core\Audit;
use Core\Controller;
use Core\Csrf;
use Core\Database;
use Core\Flash;
use Core\Form;
use Core\HttpException;
use Core\Identifier;
use Core\Paginator;
use Core\Response;
use Core\Uploader;
use Services\Lookups;

/**
 * Field officers and the area they are responsible for.
 *
 * An officer reaches farmers through officer_assignments, which may point at a
 * single farmer, a garden, a group or a whole district or sub-county. Because
 * the dashboard and the extension reports read farmers.officer_id, every
 * assignment and unassignment also keeps that pointer in step, and an area
 * assignment fans out to the individual farmers inside it inside a single
 * transaction.
 */
final class OfficerController extends Controller
{
    private const UPLOAD_FOLDER = 'officers';

    private const OFFICER_STATUSES = "'active','inactive','suspended'";

    /** entity_type => the table an entity_id must resolve against. */
    private const ENTITY_TABLES = [
        'farmer'      => 'farmers',
        'garden'      => 'gardens',
        'group'       => 'farmer_groups',
        'district'    => 'districts',
        'sub_county'  => 'sub_counties',
    ];

    /** entity types that do not resolve against a register table. */
    private const UNRESTRICTED_ENTITY_TYPES = ['project'];

    private Lookups $lookups;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups = new Lookups($db);
    }

    public function index(): Response
    {
        $params = $this->listParams();

        $where  = ['o.deleted_at IS NULL'];
        $values = [];

        if ($params['search'] !== '') {
            $where[]      = '(o.full_name LIKE :s OR o.officer_code LIKE :s OR o.employee_no LIKE :s
                              OR o.phone LIKE :s OR o.email LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        foreach (['status' => 'o.status', 'district_id' => 'o.district_id', 'region_id' => 'o.region_id'] as $key => $column) {
            if (($params['filters'][$key] ?? '') !== '') {
                $where[]      = $column . ' = :' . $key;
                $values[$key] = $params['filters'][$key];
            }
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total     = (int) $this->db->scalar('SELECT COUNT(*) FROM field_officers o' . $whereSql, $values);
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $rows = $this->db->select(
            'SELECT o.id, o.officer_code, o.full_name, o.phone, o.alt_phone, o.email,
                    o.employee_no, o.status, o.created_at, u.username,
                    r.name AS region_name, d.name AS district_name,
                    (SELECT COUNT(*) FROM farmers f
                      WHERE f.officer_id = o.id AND f.deleted_at IS NULL) AS farmer_count,
                    (SELECT COUNT(*) FROM gardens gd
                      WHERE gd.deleted_at IS NULL
                        AND gd.farmer_id IN (SELECT f2.id FROM farmers f2
                                             WHERE f2.officer_id = o.id AND f2.deleted_at IS NULL)) AS garden_count,
                    (SELECT COUNT(*) FROM crop_activities a
                      WHERE a.officer_id = o.id
                        AND (a.status = \'overdue\' OR (a.status IN (\'pending\',\'due\') AND a.due_date < CURDATE()))) AS overdue_count,
                    (SELECT COUNT(*) FROM problem_reports pr
                      WHERE pr.assigned_officer_id = o.id AND pr.status NOT IN (\'resolved\',\'closed\')) AS open_problem_count
             FROM field_officers o
             LEFT JOIN users u ON u.id = o.user_id
             LEFT JOIN regions r ON r.id = o.region_id
             LEFT JOIN districts d ON d.id = o.district_id'
            . $whereSql
            . ' ORDER BY ' . $this->sortClause($params['sort'], $params['direction'])
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('officers/index', [
            'pageTitle' => 'Field Officers',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $params['sort'],
            'direction' => $params['direction'],
            'filters'   => [
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions(self::OFFICER_STATUSES, 'All statuses')],
                ['key' => 'region_id', 'label' => 'Region', 'options' => $this->lookups->regionOptions()],
                ['key' => 'district_id', 'label' => 'District', 'options' => $this->lookups->districtOptions()],
            ],
        ]);
    }

    public function create(): Response
    {
        return $this->render('officers/form', [
            'pageTitle' => 'Add Field Officer',
            'record'    => null,
            'sections'  => $this->sections(),
        ]);
    }

    public function store(): Response
    {
        $this->assertCsrf();

        $sections = $this->sections();
        $fields   = array_merge(...array_column($sections, 'fields'));
        $input    = $this->request->all();

        $validator = Form::validate($fields, $input);
        $validator->unique('employee_no', 'field_officers', 'employee_no', null, 'Employee number');

        if ($validator->fails()) {
            return $this->backWithErrors(url('officers/create'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, [
            'user_id', 'phone', 'alt_phone', 'email', 'employee_no', 'region_id', 'district_id', 'notes',
        ]);

        $photo                = $this->uploadPhoto();
        $values['photo']      = $photo ?? ($input['photo'] ?? null);

        $officerId = $this->db->transaction(function () use ($values): int {
            $values['officer_code'] = Identifier::generate('officer');

            return $this->db->insert('field_officers', $values);
        });

        $officer = $this->findByIdOrFail($officerId);

        Audit::log('create', 'field_officers', 'field_officer', $officerId, $officer['officer_code'], null, $values);

        Flash::success('Field officer ' . $officer['officer_code'] . ' has been added.');

        return $this->redirect(url('officers/' . $officer['officer_code']));
    }

    /**
     * The extension dashboard for one officer: the farmers and gardens they are
     * responsible for, the work due this month, and the problems they carry.
     */
    public function show(string $id): Response
    {
        $officer   = $this->findOrFail($id);
        $officerId = (int) $officer['id'];

        $farmers = $this->db->select(
            'SELECT f.id, f.farmer_code, f.full_name, f.phone, f.status,
                    d.name AS district_name, g.name AS group_name,
                    (SELECT COUNT(*) FROM gardens gd WHERE gd.farmer_id = f.id AND gd.deleted_at IS NULL) AS garden_count,
                    (SELECT COALESCE(SUM(gd.acreage), 0) FROM gardens gd WHERE gd.farmer_id = f.id AND gd.deleted_at IS NULL) AS total_acreage
             FROM farmers f
             LEFT JOIN districts d ON d.id = f.district_id
             LEFT JOIN farmer_groups g ON g.id = f.group_id
             WHERE f.officer_id = :oid AND f.deleted_at IS NULL
             ORDER BY f.full_name
             LIMIT 300',
            ['oid' => $officerId]
        );

        $gardens = $this->db->select(
            'SELECT gd.id, gd.garden_code, gd.name, gd.acreage, gd.status, f.full_name, f.farmer_code
             FROM gardens gd
             INNER JOIN farmers f ON f.id = gd.farmer_id
             WHERE gd.deleted_at IS NULL AND f.deleted_at IS NULL
               AND (f.officer_id = :oid
                    OR gd.id IN (SELECT oa.entity_id FROM officer_assignments oa
                                  WHERE oa.officer_id = :oid2 AND oa.entity_type = \'garden\' AND oa.active = 1))
             ORDER BY f.full_name, gd.name
             LIMIT 300',
            ['oid' => $officerId, 'oid2' => $officerId]
        );

        $monthStart = date('Y-m-01');
        $today      = date('Y-m-d');

        $metrics = [
            'farmers'            => (int) $this->db->scalar(
                'SELECT COUNT(*) FROM farmers WHERE officer_id = :oid AND deleted_at IS NULL',
                ['oid' => $officerId]
            ),
            'gardens'            => (int) $this->db->scalar(
                'SELECT COUNT(*) FROM gardens gd
                 WHERE gd.deleted_at IS NULL
                   AND (gd.farmer_id IN (SELECT f.id FROM farmers f
                                          WHERE f.officer_id = :oid AND f.deleted_at IS NULL)
                        OR gd.id IN (SELECT oa.entity_id FROM officer_assignments oa
                                      WHERE oa.officer_id = :oid2 AND oa.entity_type = \'garden\' AND oa.active = 1))',
                ['oid' => $officerId, 'oid2' => $officerId]
            ),
            'visits_due'         => (int) $this->db->scalar(
                "SELECT COUNT(*) FROM field_visits WHERE officer_id = :oid AND status = 'planned' AND visit_date >= :today",
                ['oid' => $officerId, 'today' => $today]
            ),
            'visits_month'       => (int) $this->db->scalar(
                "SELECT COUNT(*) FROM field_visits WHERE officer_id = :oid AND status = 'completed' AND visit_date >= :month",
                ['oid' => $officerId, 'month' => $monthStart]
            ),
            'inspections_month'  => (int) $this->db->scalar(
                'SELECT COUNT(*) FROM inspections WHERE officer_id = :oid AND inspection_date >= :month',
                ['oid' => $officerId, 'month' => $monthStart]
            ),
            // "Due" and "overdue" are counted from the status the scheduler left
            // behind as well as the status the nightly job flips, so the screen
            // never shows a false zero between two cron runs.
            'activities_due'     => (int) $this->db->scalar(
                "SELECT COUNT(*) FROM crop_activities
                 WHERE officer_id = :oid AND status IN ('pending','due') AND due_date >= :today",
                ['oid' => $officerId, 'today' => $today]
            ),
            'activities_overdue' => (int) $this->db->scalar(
                "SELECT COUNT(*) FROM crop_activities
                 WHERE officer_id = :oid
                   AND (status = 'overdue' OR (status IN ('pending','due') AND due_date < :today))",
                ['oid' => $officerId, 'today' => $today]
            ),
            'problems_reported'  => (int) $this->db->scalar(
                'SELECT COUNT(*) FROM problem_reports WHERE assigned_officer_id = :oid',
                ['oid' => $officerId]
            ),
            'problems_resolved'  => (int) $this->db->scalar(
                "SELECT COUNT(*) FROM problem_reports
                 WHERE assigned_officer_id = :oid AND status IN ('resolved','closed')",
                ['oid' => $officerId]
            ),
            'farmers_visited'    => (int) $this->db->scalar(
                "SELECT COUNT(DISTINCT farmer_id) FROM field_visits
                 WHERE officer_id = :oid AND farmer_id IS NOT NULL AND visit_date >= :month",
                ['oid' => $officerId, 'month' => $monthStart]
            ),
            'gardens_inspected'  => (int) $this->db->scalar(
                'SELECT COUNT(DISTINCT garden_id) FROM inspections
                 WHERE officer_id = :oid AND inspection_date >= :month',
                ['oid' => $officerId, 'month' => $monthStart]
            ),
        ];

        $metrics['problems_open'] = max(0, $metrics['problems_reported'] - $metrics['problems_resolved']);

        $dueActivities = $this->db->select(
            'SELECT a.activity_code, a.due_date, a.status, a.priority, a.assignee_type,
                    ' . Lookups::activityNameSql('a') . ' AS activity_name,
                    c.name AS crop_name, f.full_name, f.farmer_code
             FROM crop_activities a
             LEFT JOIN crops c ON c.id = a.crop_id
             INNER JOIN farmers f ON f.id = a.farmer_id
             WHERE a.officer_id = :oid
               AND (a.status = \'overdue\' OR a.status IN (\'pending\',\'due\'))
             ORDER BY a.due_date ASC
             LIMIT 20',
            ['oid' => $officerId]
        );

        $assignments = $this->db->select(
            'SELECT oa.id, oa.entity_type, oa.entity_id, oa.assigned_at, oa.active, u.username AS assigned_by_name
             FROM officer_assignments oa
             LEFT JOIN users u ON u.id = oa.assigned_by
             WHERE oa.officer_id = :oid AND oa.active = 1
             ORDER BY oa.entity_type, oa.assigned_at DESC
             LIMIT 200',
            ['oid' => $officerId]
        );

        $assignments = $this->withLabels($assignments);

        return $this->render('officers/show', [
            'pageTitle'     => $officer['full_name'],
            'officer'       => $officer,
            'metrics'       => $metrics,
            'farmers'       => $farmers,
            'gardens'       => $gardens,
            'dueActivities' => $dueActivities,
            'assignments'   => $assignments,
            'entityTypes'   => Form::enumOptions(
                "'farmer','garden','group','project','district','sub_county'",
                ''
            ),
            'entityOptions' => $this->entityOptions(),
        ]);
    }

    public function edit(string $id): Response
    {
        $officer = $this->findOrFail($id);

        return $this->render('officers/form', [
            'pageTitle' => 'Edit ' . $officer['officer_code'],
            'record'    => $officer,
            'sections'  => $this->sections(),
        ]);
    }

    public function update(string $id): Response
    {
        $this->assertCsrf();

        $officer = $this->findOrFail($id);
        $input   = $this->request->all();
        $fields  = array_merge(...array_column($this->sections(), 'fields'));

        $validator = Form::validate($fields, $input);
        $validator->unique('employee_no', 'field_officers', 'employee_no', (int) $officer['id'], 'Employee number');

        if ($validator->fails()) {
            return $this->backWithErrors(url('officers/' . $officer['officer_code'] . '/edit'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, [
            'user_id', 'phone', 'alt_phone', 'email', 'employee_no', 'region_id', 'district_id', 'notes',
        ]);

        $photo = $this->uploadPhoto();
        if ($photo !== null) {
            $values['photo'] = $photo;
        }

        $before = $officer;

        $this->db->update('field_officers', $values, 'id = :id', ['id' => $officer['id']]);

        Audit::log('update', 'field_officers', 'field_officer', (int) $officer['id'], $officer['officer_code'], $before, $values);

        Flash::success('Field officer record updated.');

        return $this->redirect(url('officers/' . $officer['officer_code']));
    }

    public function destroy(string $id): Response
    {
        $this->assertCsrf();

        $officer   = $this->findOrFail($id);
        $officerId = (int) $officer['id'];

        $farmers = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM farmers WHERE officer_id = :oid AND deleted_at IS NULL',
            ['oid' => $officerId]
        );

        if ($farmers > 0) {
            Flash::error(
                'This officer still supports ' . $farmers . ' farmer(s). Reassign them to another officer first so '
                . 'their extension records keep a valid officer reference.'
            );

            return $this->redirect(url('officers/' . $officer['officer_code']));
        }

        $this->db->transaction(function () use ($officerId, $officer): void {
            $this->db->update(
                'field_officers',
                ['status' => 'inactive', 'deleted_at' => date('Y-m-d H:i:s')],
                'id = :id',
                ['id' => $officerId]
            );

            $this->db->update(
                'officer_assignments',
                ['active' => 0],
                'officer_id = :oid',
                ['oid' => $officerId]
            );
        });

        Audit::log(
            'delete',
            'field_officers',
            'field_officer',
            $officerId,
            $officer['officer_code'],
            $officer,
            ['status' => 'inactive']
        );

        Flash::success(
            'Field officer ' . $officer['officer_code'] . ' has been deactivated. The identifier is retained and will not be reused.'
        );

        return $this->redirect(url('officers'));
    }

    /**
     * Puts an area or a record under an officer. A duplicate assignment is
     * treated as a no-op rather than an error, because the same area is often
     * submitted twice from the dashboard.
     */
    public function assign(string $id): Response
    {
        $this->assertCsrf();

        $officer   = $this->findOrFail($id);
        $officerId = (int) $officer['id'];

        $entityType = $this->request->string('entity_type');
        $entityId   = $this->request->int('entity_id');

        $allowed = array_merge(array_keys(self::ENTITY_TABLES), self::UNRESTRICTED_ENTITY_TYPES);

        if (!in_array($entityType, $allowed, true)) {
            Flash::error('Choose what this officer is being assigned to.');

            return $this->redirect(url('officers/' . $officer['officer_code']));
        }

        if ($entityId <= 0) {
            Flash::error('Choose the record to assign to this officer.');

            return $this->redirect(url('officers/' . $officer['officer_code']));
        }

        $label = $this->entityLabel($entityType, $entityId);

        if ($label === null) {
            Flash::error('The selected ' . ucfirst(str_replace('_', ' ', $entityType)) . ' could not be found.');

            return $this->redirect(url('officers/' . $officer['officer_code']));
        }

        $existing = $this->db->selectOne(
            'SELECT id, active FROM officer_assignments WHERE officer_id = :oid AND entity_type = :etype AND entity_id = :eid LIMIT 1',
            ['oid' => $officerId, 'etype' => $entityType, 'eid' => $entityId]
        );

        if ($existing !== null && (int) $existing['active'] === 1) {
            Flash::info($label . ' is already assigned to ' . $officer['full_name'] . '.');

            return $this->redirect(url('officers/' . $officer['officer_code']));
        }

        $farmersCovered = $this->db->transaction(function () use ($officerId, $entityType, $entityId, $existing): int {
            if ($existing !== null) {
                $this->db->update(
                    'officer_assignments',
                    ['active' => 1, 'assigned_at' => date('Y-m-d H:i:s'), 'assigned_by' => $this->auth->id()],
                    'id = :id',
                    ['id' => $existing['id']]
                );
            } else {
                $this->db->insert('officer_assignments', [
                    'officer_id'  => $officerId,
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                    'assigned_by' => $this->auth->id(),
                ]);
            }

            return $this->syncFarmers($officerId, $entityType, $entityId);
        });

        Audit::log(
            'assign',
            'field_officers',
            'officer_assignment',
            $existing === null ? null : (int) $existing['id'],
            $officer['officer_code'],
            null,
            [
                'entity_type'      => $entityType,
                'entity_id'        => $entityId,
                'farmers_affected' => $farmersCovered,
            ]
        );

        Flash::success(
            $label . ' assigned to ' . $officer['full_name']
            . ($farmersCovered > 0 ? '. ' . $farmersCovered . ' farmer(s) now report to this officer.' : '.')
        );

        return $this->redirect(url('officers/' . $officer['officer_code']));
    }

    public function unassign(string $id): Response
    {
        $this->assertCsrf();

        $officer   = $this->findOrFail($id);
        $officerId = (int) $officer['id'];

        $entityType = $this->request->string('entity_type');
        $entityId   = $this->request->int('entity_id');

        $allowed = array_merge(array_keys(self::ENTITY_TABLES), self::UNRESTRICTED_ENTITY_TYPES);

        if (!in_array($entityType, $allowed, true) || $entityId <= 0) {
            Flash::error('That assignment could not be identified.');

            return $this->redirect(url('officers/' . $officer['officer_code']));
        }

        $assignment = $this->db->selectOne(
            'SELECT id, active FROM officer_assignments
             WHERE officer_id = :oid AND entity_type = :etype AND entity_id = :eid LIMIT 1',
            ['oid' => $officerId, 'etype' => $entityType, 'eid' => $entityId]
        );

        if ($assignment === null) {
            Flash::warning('That record is no longer assigned to this officer.');

            return $this->redirect(url('officers/' . $officer['officer_code']));
        }

        $label = $this->entityLabel($entityType, $entityId) ?? ('#' . $entityId);

        $released = $this->db->transaction(function () use ($officerId, $entityType, $entityId, $assignment): int {
            $this->db->update('officer_assignments', ['active' => 0], 'id = :id', ['id' => $assignment['id']]);

            if ($entityType === 'farmer') {
                return $this->db->update(
                    'farmers',
                    ['officer_id' => null],
                    'id = :id AND officer_id = :oid',
                    ['id' => $entityId, 'oid' => $officerId]
                );
            }

            return 0;
        });

        Audit::log(
            'unassign',
            'field_officers',
            'officer_assignment',
            (int) $assignment['id'],
            $officer['officer_code'],
            ['entity_type' => $entityType, 'entity_id' => $entityId],
            null
        );

        Flash::success(
            $label . ' is no longer assigned to ' . $officer['full_name']
            . ($released > 0 ? '. ' . $released . ' farmer record(s) cleared.' : '.')
        );

        return $this->redirect(url('officers/' . $officer['officer_code']));
    }

    /* ------------------------------------------------------------------ */

    /**
     * Points the farmers covered by an assignment at this officer. An area
     * assignment fans out to every farmer registered inside it, which is what
     * makes a district handover a single action.
     */
    private function syncFarmers(int $officerId, string $entityType, int $entityId): int
    {
        $column = match ($entityType) {
            'farmer'      => 'id',
            'district'    => 'district_id',
            'sub_county'  => 'sub_county_id',
            default       => null,
        };

        if ($column === null) {
            return 0;
        }

        $column = Database::safeIdentifier($column);

        return $this->db->update(
            'farmers',
            ['officer_id' => $officerId],
            $column . ' = :entity AND deleted_at IS NULL AND (officer_id IS NULL OR officer_id <> :oid)',
            ['entity' => $entityId, 'oid' => $officerId]
        );
    }

    /**
     * Resolves an assignment to a readable label, returning null when the
     * referenced record no longer exists.
     */
    private function entityLabel(string $entityType, int $entityId): ?string
    {
        $sql = match ($entityType) {
            'farmer'     => 'SELECT CONCAT(f.farmer_code, \' - \', f.full_name) FROM farmers f
                             WHERE f.id = :id AND f.deleted_at IS NULL LIMIT 1',
            'garden'     => 'SELECT CONCAT(g.garden_code, \' - \', g.name) FROM gardens g
                             WHERE g.id = :id AND g.deleted_at IS NULL LIMIT 1',
            'group'      => 'SELECT CONCAT(g.group_code, \' - \', g.name) FROM farmer_groups g
                             WHERE g.id = :id AND g.deleted_at IS NULL LIMIT 1',
            'district'   => 'SELECT name FROM districts WHERE id = :id LIMIT 1',
            'sub_county' => 'SELECT name FROM sub_counties WHERE id = :id LIMIT 1',
            default      => null,
        };

        if ($sql === null) {
            return in_array($entityType, self::UNRESTRICTED_ENTITY_TYPES, true) ? $entityType . ' #' . $entityId : null;
        }

        $label = $this->db->scalar($sql, ['id' => $entityId]);

        return $label === null ? null : (string) $label;
    }

    /**
     * Attaches a display label to each assignment row, resolving one query per
     * entity type rather than a join per row.
     *
     * @param array<int,array<string,mixed>> $assignments
     * @return array<int,array<string,mixed>>
     */
    private function withLabels(array $assignments): array
    {
        $idsByType = [];

        foreach ($assignments as $assignment) {
            $idsByType[(string) $assignment['entity_type']][] = (int) $assignment['entity_id'];
        }

        $labels = [];

        foreach ($idsByType as $type => $ids) {
            $ids = array_values(array_unique($ids));

            if (count($ids) > 500) {
                $ids = array_slice($ids, 0, 500);
            }

            $in  = implode(',', array_fill(0, count($ids), '?'));
            $sql = match ($type) {
                'farmer'     => 'SELECT id, CONCAT(farmer_code, \' - \', full_name) AS label FROM farmers WHERE id IN (' . $in . ')',
                'garden'     => 'SELECT id, CONCAT(garden_code, \' - \', name) AS label FROM gardens WHERE id IN (' . $in . ')',
                'group'      => 'SELECT id, CONCAT(group_code, \' - \', name) AS label FROM farmer_groups WHERE id IN (' . $in . ')',
                'district'   => 'SELECT id, name AS label FROM districts WHERE id IN (' . $in . ')',
                'sub_county' => 'SELECT id, name AS label FROM sub_counties WHERE id IN (' . $in . ')',
                default      => null,
            };

            if ($sql === null) {
                continue;
            }

            foreach ($this->db->select($sql, $ids) as $row) {
                $labels[$type][(int) $row['id']] = (string) $row['label'];
            }
        }

        foreach ($assignments as $index => $assignment) {
            $type   = (string) $assignment['entity_type'];
            $key    = (int) $assignment['entity_id'];
            $labels = $labels[$type][$key] ?? null;

            if ($labels === null) {
                $assignments[$index]['entity_label'] = in_array($type, self::UNRESTRICTED_ENTITY_TYPES, true)
                    ? $type . ' #' . $key
                    : ucfirst(str_replace('_', ' ', $type)) . ' #' . $key . ' (no longer available)';
                continue;
            }

            $assignments[$index]['entity_label'] = $labels;
        }

        return $assignments;
    }

    /**
     * Select options for the assignment panel, keyed by entity type.
     *
     * @return array<string,array<int,array{value:string,label:string}>>
     */
    private function entityOptions(): array
    {
        return [
            'farmer'     => $this->lookups->farmerOptions('Select farmer...'),
            'garden'     => $this->lookups->gardenOptions(null, 'Select garden...'),
            'group'      => $this->groupOptions('Select group...'),
            'district'   => $this->lookups->districtOptions('Select district...'),
            'sub_county' => $this->lookups->subCountyOptions('Select sub-county...'),
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
     * @return array<int,array{value:string,label:string}>
     */
    private function userOptions(): array
    {
        return Form::options(
            $this->db->select("SELECT id, username FROM users WHERE status = 'active' AND deleted_at IS NULL ORDER BY username LIMIT 500"),
            'id',
            'username',
            'Not linked to a portal account'
        );
    }

    private function sortClause(string $sort, string $direction): string
    {
        $allowed = ['officer_code', 'full_name', 'employee_no', 'phone', 'status', 'farmer_count', 'garden_count', 'overdue_count', 'created_at'];
        $column  = in_array($sort, $allowed, true) ? $sort : 'created_at';
        $order   = $direction === 'asc' ? 'ASC' : 'DESC';

        if (in_array($column, ['farmer_count', 'garden_count', 'overdue_count'], true)) {
            return '(' . $column . ') ' . $order;
        }

        return 'o.' . $column . ' ' . $order;
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'title'  => 'Officer details',
                'fields' => [
                    ['name' => 'officer_code', 'label' => 'Officer ID', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued automatically when the record is saved.'],
                    ['name' => 'full_name', 'label' => 'Full name', 'type' => Form::TEXT, 'required' => true, 'colspan' => 6],
                    ['name' => 'status', 'label' => 'Status', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions(self::OFFICER_STATUSES, '')],
                    ['name' => 'employee_no', 'label' => 'Employee number', 'type' => Form::TEXT, 'colspan' => 6],
                    ['name' => 'phone', 'label' => 'Phone number', 'type' => Form::TEL, 'colspan' => 6,
                     'help' => 'Used for SMS reminders and advisories.'],
                    ['name' => 'alt_phone', 'label' => 'Alternative phone', 'type' => Form::TEL, 'colspan' => 6],
                    ['name' => 'email', 'label' => 'Email address', 'type' => Form::EMAIL, 'colspan' => 6],
                    ['name' => 'user_id', 'label' => 'Portal account', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->userOptions(), 'table' => 'users',
                     'help' => 'Link the officer to a sign-in account so they can file records from the portal.'],
                    ['name' => 'photo', 'label' => 'Photo', 'type' => Form::FILE, 'colspan' => 12,
                     'accept' => 'image/jpeg,image/png', 'filetype' => 'image'],
                ],
            ],
            [
                'title'  => 'Area of operation',
                'fields' => [
                    ['name' => 'region_id', 'label' => 'Region', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->regionOptions('Select region...'), 'table' => 'regions'],
                    ['name' => 'district_id', 'label' => 'District', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->districtOptions('Select district...'), 'table' => 'districts',
                     'help' => 'The officer is listed against this district even before farmers are assigned.'],
                    ['name' => 'notes', 'label' => 'Notes', 'type' => Form::TEXTAREA, 'colspan' => 12,
                     'help' => 'Qualifications, vehicle or equipment held, and any area constraints.'],
                ],
            ],
        ];
    }

    private function findOrFail(string $id): array
    {
        $id = trim($id);

        if ($id === '') {
            throw new HttpException(404, 'The field officer record could not be found.');
        }

        $isNumeric = ctype_digit($id);

        $officer = $this->db->selectOne(
            'SELECT o.*, u.username, u.email AS user_email, u.status AS user_status,
                    r.name AS region_name, d.name AS district_name
             FROM field_officers o
             LEFT JOIN users u ON u.id = o.user_id
             LEFT JOIN regions r ON r.id = o.region_id
             LEFT JOIN districts d ON d.id = o.district_id
             WHERE o.deleted_at IS NULL
               AND (' . ($isNumeric ? 'o.id = :key' : 'o.officer_code = :key') . ')
             LIMIT 1',
            ['key' => $isNumeric ? (int) $id : $id]
        );

        if ($officer === null) {
            throw new HttpException(404, 'No field officer found with the reference ' . $id . '.');
        }

        return $officer;
    }

    private function findByIdOrFail(int $id): array
    {
        $officer = $this->db->selectOne('SELECT * FROM field_officers WHERE id = :id', ['id' => $id]);

        if ($officer === null) {
            throw new HttpException(404, 'The field officer record could not be found.');
        }

        return $officer;
    }

    private function uploadPhoto(): ?string
    {
        if (!isset($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $result = $this->uploader()->image('photo', self::UPLOAD_FOLDER, $this->maxImageKb());

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

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }
}