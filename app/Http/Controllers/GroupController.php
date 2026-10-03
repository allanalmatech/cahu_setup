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
use Core\Validator;
use Services\Lookups;

/**
 * Farmer groups: the cooperative, producer and village groupings a farmer
 * belongs to.
 *
 * Membership is single valued by design - a farmer sits in exactly one group -
 * so every write keeps the farmer_group_members register and the denormalised
 * farmers.group_id pointer in step inside one transaction. The group dashboard
 * reads straight from the member register rather than from cached counters, so
 * acreage, production and sales figures always reflect what has actually been
 * recorded.
 */
final class GroupController extends Controller
{
    private const GROUP_TYPES = "'cooperative','producer_group','farmer_association','village_group','other'";

    private const GROUP_STATUSES = "'active','inactive','dissolved'";

    private const ROLES = "'member','chairperson','treasurer','secretary','vice_chairperson'";

    private Lookups $lookups;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups = new Lookups($db);
    }

    public function index(): Response
    {
        $params = $this->listParams();

        $where  = ['g.deleted_at IS NULL'];
        $values = [];

        if ($params['search'] !== '') {
            $where[]      = '(g.name LIKE :s OR g.group_code LIKE :s OR g.registration_no LIKE :s
                              OR g.contact_person LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        foreach (['status' => 'g.status', 'district_id' => 'g.district_id'] as $key => $column) {
            if (($params['filters'][$key] ?? '') !== '') {
                $where[]      = $column . ' = :' . $key;
                $values[$key] = $params['filters'][$key];
            }
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total     = (int) $this->db->scalar('SELECT COUNT(*) FROM farmer_groups g' . $whereSql, $values);
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        // The member set is aggregated once in a derived table rather than
        // repeated as correlated subqueries, so a group register page runs the
        // same amount of work per row no matter how many members it has.
        $memberAggregate = '(SELECT m.group_id AS group_id,
                                   COUNT(*) AS member_count,
                                   COALESCE(SUM(m.gardens), 0) AS garden_count,
                                   COALESCE(SUM(m.acreage), 0) AS total_acreage
                            FROM (
                                SELECT fgm.group_id AS group_id,
                                       (SELECT COUNT(*) FROM gardens gd2
                                         WHERE gd2.farmer_id = fgm.farmer_id AND gd2.deleted_at IS NULL) AS gardens,
                                       (SELECT COALESCE(SUM(gd3.acreage), 0) FROM gardens gd3
                                         WHERE gd3.farmer_id = fgm.farmer_id AND gd3.deleted_at IS NULL) AS acreage
                                FROM farmer_group_members fgm
                                INNER JOIN farmers fm ON fm.id = fgm.farmer_id AND fm.deleted_at IS NULL
                                WHERE fgm.status = \'active\'
                            ) m
                            GROUP BY m.group_id) agg';

        $rows = $this->db->select(
            'SELECT g.id, g.group_code, g.name, g.group_type, g.registration_no, g.status,
                    g.registration_date, g.created_at, d.name AS district_name, sc.name AS sub_county_name,
                    COALESCE(agg.member_count, 0) AS member_count,
                    COALESCE(agg.garden_count, 0) AS garden_count,
                    COALESCE(agg.total_acreage, 0) AS total_acreage
             FROM farmer_groups g
             LEFT JOIN districts d ON d.id = g.district_id
             LEFT JOIN sub_counties sc ON sc.id = g.sub_county_id
             LEFT JOIN ' . $memberAggregate . ' ON agg.group_id = g.id'
            . $whereSql
            . ' ORDER BY ' . $this->sortClause($params['sort'], $params['direction'])
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('groups/index', [
            'pageTitle' => 'Farmer Groups',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $params['sort'],
            'direction' => $params['direction'],
            'filters'   => [
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions(self::GROUP_STATUSES, 'All statuses')],
                ['key' => 'district_id', 'label' => 'District', 'options' => $this->lookups->districtOptions()],
            ],
        ]);
    }

    public function create(): Response
    {
        return $this->render('groups/form', [
            'pageTitle' => 'Register Group',
            'record'    => null,
            'sections'  => $this->sections(),
            'gpsLng'    => '',
        ]);
    }

    public function store(): Response
    {
        $this->assertCsrf();

        $sections = $this->sections();
        $fields   = array_merge(...array_column($sections, 'fields'));
        $input    = $this->request->all();

        $validator = Form::validate($fields, $input);
        $validator->unique('registration_no', 'farmer_groups', 'registration_no', null, 'Registration number');

        if ($validator->fails()) {
            return $this->backWithErrors(url('groups/create'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, [
            'registration_no', 'region_id', 'district_id', 'sub_county_id', 'parish_id', 'village_id',
            'address', 'contact_person', 'phone', 'email', 'latitude', 'longitude',
            'registration_date', 'description',
        ]);

        $id = $this->db->transaction(function () use ($values): int {
            $values['group_code']       = Identifier::generate('group');
            $values['registration_date'] = $values['registration_date'] ?? date('Y-m-d');

            return $this->db->insert('farmer_groups', $values);
        });

        $group = $this->findByIdOrFail($id);

        Audit::log('create', 'farmer_groups', 'farmer_group', $id, $group['group_code'], null, $values);

        Flash::success('Group ' . $group['group_code'] . ' has been registered.');

        return $this->redirect(url('groups/' . $group['group_code']));
    }

    /**
     * The group dashboard: membership, land, crops, production against forecast,
     * activity state, inputs issued, open problems and the value realised.
     */
    public function show(string $id): Response
    {
        $group = $this->findOrFail($id);

        $groupId = (int) $group['id'];

        $members = $this->db->select(
            'SELECT m.id AS membership_id, m.role_in_group, m.joined_date, m.status AS membership_status,
                    f.id, f.farmer_code, f.full_name, f.phone, f.status AS farmer_status,
                    f.village_name, d.name AS district_name,
                    (SELECT COUNT(*) FROM gardens gd WHERE gd.farmer_id = f.id AND gd.deleted_at IS NULL) AS garden_count,
                    (SELECT COALESCE(SUM(gd.acreage), 0) FROM gardens gd WHERE gd.farmer_id = f.id AND gd.deleted_at IS NULL) AS total_acreage
             FROM farmer_group_members m
             INNER JOIN farmers f ON f.id = m.farmer_id
             LEFT JOIN districts d ON d.id = f.district_id
             WHERE m.group_id = :gid AND m.status = \'active\' AND f.deleted_at IS NULL
             ORDER BY f.full_name
             LIMIT 500',
            ['gid' => $groupId]
        );

        $memberFarmerIds = array_map(static fn (array $row): int => (int) $row['id'], $members);

        $stats = [
            'members'     => (int) $this->db->scalar(
                'SELECT COUNT(*) FROM farmer_group_members WHERE group_id = :gid AND status = \'active\'',
                ['gid' => $groupId]
            ),
            'gardens'     => 0,
            'acreage'     => 0.0,
            'officers'    => 0,
            'expected'    => 0.0,
            'actual'      => 0.0,
            'inputs'      => 0.0,
            'input_unit'  => null,
            'sales_value' => 0.0,
            'problems'    => 0,
            'activities'  => ['completed' => 0, 'pending' => 0, 'due' => 0, 'overdue' => 0],
        ];

        if ($memberFarmerIds !== []) {
            $in          = implode(',', array_fill(0, count($memberFarmerIds), '?'));
            $memberStats = [
                'gardens'     => (int) $this->db->scalar('SELECT COUNT(*) FROM gardens gd WHERE gd.deleted_at IS NULL AND gd.farmer_id IN (' . $in . ')', $memberFarmerIds),
                'acreage'     => (float) $this->db->scalar('SELECT COALESCE(SUM(gd.acreage), 0) FROM gardens gd WHERE gd.deleted_at IS NULL AND gd.farmer_id IN (' . $in . ')', $memberFarmerIds),
                'officers'    => (int) $this->db->scalar('SELECT COUNT(DISTINCT f.officer_id) FROM farmers f WHERE f.officer_id IS NOT NULL AND f.id IN (' . $in . ')', $memberFarmerIds),
                'expected'    => (float) $this->db->scalar(
                    'SELECT COALESCE(SUM(hf.expected_production), 0) FROM harvest_forecasts hf
                     INNER JOIN seasons s ON s.id = hf.season_id
                     WHERE s.status = \'open\' AND hf.farmer_id IN (' . $in . ')',
                    $memberFarmerIds
                ),
                'actual'      => (float) $this->db->scalar(
                    'SELECT COALESCE(SUM(hr.quantity_harvested), 0) FROM harvest_records hr
                     INNER JOIN seasons s ON s.id = hr.season_id
                     WHERE s.status = \'open\' AND hr.status <> \'cancelled\' AND hr.farmer_id IN (' . $in . ')',
                    $memberFarmerIds
                ),
                'problems'    => (int) $this->db->scalar(
                    "SELECT COUNT(*) FROM problem_reports pr WHERE pr.status NOT IN ('resolved','closed') AND pr.farmer_id IN (" . $in . ')',
                    $memberFarmerIds
                ),
            ];

            $inputs = $this->db->selectOne(
                'SELECT COALESCE(SUM(ii.quantity), 0) AS quantity, MIN(ii.unit) AS unit
                 FROM input_issues ii WHERE ii.status <> \'cancelled\' AND ii.farmer_id IN (' . $in . ')',
                $memberFarmerIds
            );

            // A sale total is counted once even when a buyer bought several
            // batches from the same group, so the value realised is not
            // inflated by the shape of the sale.
            $sales = $this->db->scalar(
                'SELECT COALESCE(SUM(s.total_value), 0) FROM sales s
                 INNER JOIN (
                     SELECT DISTINCT si.sale_id
                     FROM sale_items si
                     INNER JOIN harvest_batches hb ON hb.id = si.batch_id
                     INNER JOIN harvest_batch_items hbi ON hbi.batch_id = hb.id
                     INNER JOIN harvest_records hr ON hr.id = hbi.harvest_id
                     WHERE hr.farmer_id IN (' . $in . ')
                 ) sold ON sold.sale_id = s.id
                 WHERE s.status <> \'cancelled\'',
                $memberFarmerIds
            );

            foreach ($memberStats as $key => $value) {
                $stats[$key] = $value;
            }

            $stats['inputs']     = (float) ($inputs['quantity'] ?? 0);
            $stats['input_unit'] = $inputs['unit'] ?? null;
            $stats['sales_value'] = (float) $sales;

            $activityRows = $this->db->select(
                'SELECT a.status, COUNT(*) AS total FROM crop_activities a WHERE a.farmer_id IN (' . $in . ')
                 GROUP BY a.status',
                $memberFarmerIds
            );

            foreach ($activityRows as $activityRow) {
                $status = (string) $activityRow['status'];
                if (isset($stats['activities'][$status])) {
                    $stats['activities'][$status] = (int) $activityRow['total'];
                }
            }
        }

        $crops = $memberFarmerIds === [] ? [] : $this->db->select(
            'SELECT c.name AS crop_name, c.category, COUNT(DISTINCT p.id) AS planting_count,
                    COUNT(DISTINCT p.farmer_id) AS farmer_count,
                    COALESCE(SUM(p.acreage_planted), 0) AS acreage
             FROM plantings p
             INNER JOIN crops c ON c.id = p.crop_id
             WHERE p.farmer_id IN (' . $in . ')
             GROUP BY c.id, c.name, c.category
             ORDER BY acreage DESC, c.name
             LIMIT 25',
            $memberFarmerIds
        );

        $openProblems = $memberFarmerIds === [] ? [] : $this->db->select(
            'SELECT pr.problem_code, pr.report_date, pr.severity, pr.status, pr.description,
                    pt.name AS problem_type, f.full_name, f.farmer_code
             FROM problem_reports pr
             INNER JOIN farmers f ON f.id = pr.farmer_id
             LEFT JOIN problem_types pt ON pt.id = pr.problem_type_id
             WHERE pr.status NOT IN (\'resolved\',\'closed\') AND pr.farmer_id IN (' . $in . ')
             ORDER BY FIELD(pr.severity, \'critical\',\'high\',\'medium\',\'low\'), pr.report_date DESC
             LIMIT 20',
            $memberFarmerIds
        );

        $availableFarmers = Form::options(
            $this->db->select(
                'SELECT f.id, f.farmer_code, f.full_name FROM farmers f
                 WHERE f.deleted_at IS NULL AND f.status = \'active\' AND f.group_id IS NULL
                 ORDER BY f.full_name LIMIT 500'
            ),
            'id',
            'full_name',
            'Select farmer...'
        );

        return $this->render('groups/show', [
            'pageTitle'        => $group['name'],
            'group'            => $group,
            'members'          => $members,
            'stats'            => $stats,
            'crops'            => $crops,
            'openProblems'     => $openProblems,
            'availableFarmers' => $availableFarmers,
            'roleOptions'      => Form::enumOptions(self::ROLES, ''),
        ]);
    }

    public function edit(string $id): Response
    {
        $group = $this->findOrFail($id);

        return $this->render('groups/form', [
            'pageTitle' => 'Edit ' . $group['group_code'],
            'record'    => $group,
            'sections'  => $this->sections(),
            'gpsLng'    => (string) ($group['longitude'] ?? ''),
        ]);
    }

    public function update(string $id): Response
    {
        $this->assertCsrf();

        $group   = $this->findOrFail($id);
        $input   = $this->request->all();
        $sections = $this->sections();
        $fields  = array_merge(...array_column($sections, 'fields'));

        $validator = Form::validate($fields, $input);
        $validator->unique('registration_no', 'farmer_groups', 'registration_no', (int) $group['id'], 'Registration number');

        if ($validator->fails()) {
            return $this->backWithErrors(url('groups/' . $group['group_code'] . '/edit'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, [
            'registration_no', 'region_id', 'district_id', 'sub_county_id', 'parish_id', 'village_id',
            'address', 'contact_person', 'phone', 'email', 'latitude', 'longitude',
            'registration_date', 'description',
        ]);

        $before = $group;

        $this->db->update('farmer_groups', $values, 'id = :id', ['id' => $group['id']]);

        Audit::log('update', 'farmer_groups', 'farmer_group', (int) $group['id'], $group['group_code'], $before, $values);

        Flash::success('Group record updated.');

        return $this->redirect(url('groups/' . $group['group_code']));
    }

    public function destroy(string $id): Response
    {
        $this->assertCsrf();

        $group    = $this->findOrFail($id);
        $groupId  = (int) $group['id'];

        $members = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM farmer_group_members WHERE group_id = :id AND status = 'active'",
            ['id' => $groupId]
        );

        if ($members > 0) {
            Flash::error(
                'This group still has ' . $members . ' active member(s). Remove them from the group first so their '
                . 'farm records keep a valid group reference.'
            );

            return $this->redirect(url('groups/' . $group['group_code']));
        }

        $this->db->update(
            'farmer_groups',
            ['status' => 'inactive', 'deleted_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $groupId]
        );

        Audit::log('delete', 'farmer_groups', 'farmer_group', $groupId, $group['group_code'], $group, ['status' => 'inactive']);

        Flash::success(
            'Group ' . $group['group_code'] . ' has been deactivated. Its identifier is retained and will not be reused.'
        );

        return $this->redirect(url('groups'));
    }

    /**
     * Adds a farmer to the group. Membership is single valued, so a farmer who
     * already sits in another group is refused rather than silently moved.
     */
    public function addMember(string $id): Response
    {
        $this->assertCsrf();

        $group   = $this->findOrFail($id);
        $groupId = (int) $group['id'];

        $validator = Validator::make($this->request->all());
        $validator->required('farmer_id', 'Farmer');
        $validator->exists('farmer_id', 'farmers', 'id', 'Farmer');
        $validator->in('role_in_group', array_column(Form::enumOptions(self::ROLES, ''), 'value'), 'Role', false);
        $validator->date('joined_date', 'Joined date', false);

        if ($validator->fails()) {
            Flash::error((string) $validator->firstError());

            return $this->redirect(url('groups/' . $group['group_code']));
        }

        $farmerId   = (int) $this->request->input('farmer_id');
        $role       = $this->request->string('role_in_group');
        $role       = $role === '' ? 'member' : $role;
        $joinedDate = $this->request->date('joined_date') ?? date('Y-m-d');

        $farmer = $this->db->selectOne(
            'SELECT f.id, f.farmer_code, f.full_name, f.group_id, f.deleted_at,
                    g.group_code, g.name AS group_name
             FROM farmers f
             LEFT JOIN farmer_groups g ON g.id = f.group_id
             WHERE f.id = :id LIMIT 1',
            ['id' => $farmerId]
        );

        if ($farmer === null || $farmer['deleted_at'] !== null) {
            Flash::error('The selected farmer could not be found.');

            return $this->redirect(url('groups/' . $group['group_code']));
        }

        $existing = $this->db->selectOne(
            "SELECT id, status FROM farmer_group_members WHERE group_id = :gid AND farmer_id = :fid LIMIT 1",
            ['gid' => $groupId, 'fid' => $farmerId]
        );

        if ($existing !== null && $existing['status'] === 'active') {
            Flash::warning($farmer['full_name'] . ' is already an active member of this group.');

            return $this->redirect(url('groups/' . $group['group_code']));
        }

        if ($farmer['group_id'] !== null && (int) $farmer['group_id'] !== $groupId) {
            Flash::error(
                $farmer['full_name'] . ' already belongs to ' . (string) ($farmer['group_name'] ?: 'another group')
                . '. Remove them from that group before adding them here.'
            );

            return $this->redirect(url('groups/' . $group['group_code']));
        }

        $this->db->transaction(function () use ($groupId, $farmerId, $role, $joinedDate): void {
            if ($existing !== null) {
                $this->db->update(
                    'farmer_group_members',
                    ['role_in_group' => $role, 'joined_date' => $joinedDate, 'status' => 'active'],
                    'id = :id',
                    ['id' => $existing['id']]
                );
            } else {
                $this->db->insert('farmer_group_members', [
                    'group_id'      => $groupId,
                    'farmer_id'     => $farmerId,
                    'role_in_group' => $role,
                    'joined_date'   => $joinedDate,
                    'status'        => 'active',
                ]);
            }

            $this->db->update('farmers', ['group_id' => $groupId], 'id = :id', ['id' => $farmerId]);
        });

        Audit::log(
            'assign',
            'farmer_groups',
            'group_member',
            $existing === null ? null : (int) $existing['id'],
            $farmer['full_name'],
            null,
            ['group_id' => $groupId, 'farmer_id' => $farmerId, 'role_in_group' => $role]
        );

        Flash::success($farmer['full_name'] . ' has been added to the group.');

        return $this->redirect(url('groups/' . $group['group_code']));
    }

    /**
     * Removes a member and clears the farmer's group pointer when it pointed at
     * this group, so the single-valued relationship stays consistent.
     */
    public function removeMember(string $id): Response
    {
        $this->assertCsrf();

        $group     = $this->findOrFail($id);
        $groupId   = (int) $group['id'];
        $farmerId  = (int) $this->request->int('farmer_id');

        if ($farmerId <= 0) {
            Flash::error('No farmer was selected for removal.');

            return $this->redirect(url('groups/' . $group['group_code']));
        }

        $membership = $this->db->selectOne(
            'SELECT m.id AS membership_id, f.full_name, f.farmer_code
             FROM farmer_group_members m
             INNER JOIN farmers f ON f.id = m.farmer_id
             WHERE m.group_id = :gid AND m.farmer_id = :fid LIMIT 1',
            ['gid' => $groupId, 'fid' => $farmerId]
        );

        if ($membership === null) {
            Flash::warning('That farmer is not a member of this group.');

            return $this->redirect(url('groups/' . $group['group_code']));
        }

        $this->db->transaction(function () use ($groupId, $farmerId, $membership): void {
            $this->db->delete(
                'farmer_group_members',
                'group_id = :gid AND farmer_id = :fid',
                ['gid' => $groupId, 'fid' => $farmerId]
            );

            $this->db->update(
                'farmers',
                ['group_id' => null],
                'id = :id AND group_id = :gid',
                ['id' => $farmerId, 'gid' => $groupId]
            );
        });

        Audit::log(
            'unassign',
            'farmer_groups',
            'group_member',
            (int) $membership['membership_id'],
            $membership['full_name'],
            ['group_id' => $groupId, 'farmer_id' => $farmerId],
            null
        );

        Flash::success($membership['full_name'] . ' has been removed from the group.');

        return $this->redirect(url('groups/' . $group['group_code']));
    }

    /* ------------------------------------------------------------------ */

    private function sortClause(string $sort, string $direction): string
    {
        $allowed = ['group_code', 'name', 'group_type', 'registration_date', 'status', 'member_count', 'total_acreage', 'created_at'];
        $column  = in_array($sort, $allowed, true) ? $sort : 'created_at';
        $order   = $direction === 'asc' ? 'ASC' : 'DESC';

        if ($column === 'member_count' || $column === 'total_acreage') {
            return '(' . $column . ') ' . $order;
        }

        return 'g.' . $column . ' ' . $order;
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'title'  => 'Group details',
                'fields' => [
                    ['name' => 'group_code', 'label' => 'Group ID', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued automatically when the record is saved.'],
                    ['name' => 'name', 'label' => 'Group name', 'type' => Form::TEXT, 'required' => true, 'colspan' => 6],
                    ['name' => 'group_type', 'label' => 'Group type', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions(self::GROUP_TYPES, '')],
                    ['name' => 'registration_no', 'label' => 'Registration number', 'type' => Form::TEXT, 'colspan' => 6,
                     'help' => 'The number issued by the registrar, when the group is registered.'],
                    ['name' => 'registration_date', 'label' => 'Registration date', 'type' => Form::DATE, 'colspan' => 6],
                    ['name' => 'status', 'label' => 'Status', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions(self::GROUP_STATUSES, '')],
                    ['name' => 'contact_person', 'label' => 'Contact person', 'type' => Form::TEXT, 'colspan' => 6],
                    ['name' => 'phone', 'label' => 'Phone number', 'type' => Form::TEL, 'colspan' => 6],
                    ['name' => 'email', 'label' => 'Email address', 'type' => Form::EMAIL, 'colspan' => 6],
                    ['name' => 'address', 'label' => 'Address', 'type' => Form::TEXT, 'colspan' => 6],
                ],
            ],
            [
                'title'  => 'Location',
                'fields' => [
                    ['name' => 'region_id', 'label' => 'Region', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->regionOptions('Select region...'), 'table' => 'regions'],
                    ['name' => 'district_id', 'label' => 'District', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->districtOptions('Select district...'), 'table' => 'districts'],
                    ['name' => 'sub_county_id', 'label' => 'Sub-county', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->subCountyOptions('Select sub-county...'), 'table' => 'sub_counties'],
                    ['name' => 'parish_id', 'label' => 'Parish', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->parishOptions('Select parish...'), 'table' => 'parishes'],
                    ['name' => 'village_id', 'label' => 'Village', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->villageOptions('Select village...'), 'table' => 'villages'],
                    ['name' => 'latitude', 'label' => 'GPS location', 'type' => Form::GPS, 'colspan' => 12,
                     'help' => 'Where the group office or meeting point is located.'],
                ],
            ],
            [
                'title'  => 'Notes',
                'fields' => [
                    ['name' => 'description', 'label' => 'Description', 'type' => Form::TEXTAREA, 'colspan' => 12,
                     'help' => 'Scope, objectives and any conditions attached to the group registration.'],
                ],
            ],
        ];
    }

    private function findOrFail(string $id): array
    {
        $id = trim($id);

        if ($id === '') {
            throw new HttpException(404, 'The group record could not be found.');
        }

        $isNumeric = ctype_digit($id);

        $group = $this->db->selectOne(
            'SELECT g.*, r.name AS region_name, d.name AS district_name, sc.name AS sub_county_name,
                    p.name AS parish_name, v.name AS registered_village_name
             FROM farmer_groups g
             LEFT JOIN regions r ON r.id = g.region_id
             LEFT JOIN districts d ON d.id = g.district_id
             LEFT JOIN sub_counties sc ON sc.id = g.sub_county_id
             LEFT JOIN parishes p ON p.id = g.parish_id
             LEFT JOIN villages v ON v.id = g.village_id
             WHERE g.deleted_at IS NULL
               AND (' . ($isNumeric ? 'g.id = :key' : 'g.group_code = :key') . ')
             LIMIT 1',
            ['key' => $isNumeric ? (int) $id : $id]
        );

        if ($group === null) {
            throw new HttpException(404, 'No group found with the reference ' . $id . '.');
        }

        return $group;
    }

    private function findByIdOrFail(int $id): array
    {
        $group = $this->db->selectOne('SELECT * FROM farmer_groups WHERE id = :id', ['id' => $id]);

        if ($group === null) {
            throw new HttpException(404, 'The group record could not be found.');
        }

        return $group;
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }
}