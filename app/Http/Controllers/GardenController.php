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

/**
 * Gardens: the physical plots that carry every downstream record. A garden
 * belongs to exactly one farmer, and its acreage is the denominator used for
 * yield and participation figures, so the totals here are always recalculated
 * from the gardens themselves rather than cached counters.
 */
final class GardenController extends Controller
{
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
            $where[]      = '(g.garden_code LIKE :s OR g.name LIKE :s OR f.full_name LIKE :s OR f.farmer_code LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        if (($params['filters']['status'] ?? '') !== '') {
            $where[]       = 'g.status = :status';
            $values['status'] = $params['filters']['status'];
        }

        if (($params['filters']['district_id'] ?? '') !== '') {
            $where[]            = 'g.district_id = :district_id';
            $values['district_id'] = $params['filters']['district_id'];
        }

        if (($params['filters']['farmer_id'] ?? '') !== '') {
            $where[]           = 'g.farmer_id = :farmer_id';
            $values['farmer_id'] = $params['filters']['farmer_id'];
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total     = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM gardens g INNER JOIN farmers f ON f.id = g.farmer_id' . $whereSql,
            $values
        );
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $sortable = ['garden_code', 'name', 'acreage', 'status', 'created_at'];
        $column   = in_array($params['sort'], $sortable, true) ? $params['sort'] : 'created_at';
        $order    = $params['direction'] === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->db->select(
            'SELECT g.*, f.full_name, f.farmer_code, d.name AS district_name,
                    (SELECT COUNT(*) FROM plantings p WHERE p.garden_id = g.id) AS planting_count,
                    (SELECT MAX(p.planting_date) FROM plantings p WHERE p.garden_id = g.id) AS last_planting
             FROM gardens g
             INNER JOIN farmers f ON f.id = g.farmer_id
             LEFT JOIN districts d ON d.id = g.district_id'
            . $whereSql
            . ' ORDER BY g.' . $column . ' ' . $order
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('gardens/index', [
            'pageTitle' => 'Gardens',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $params['sort'],
            'direction' => $params['direction'],
            'filters'   => [
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions("'active','fallow','abandoned'", 'All statuses')],
                ['key' => 'district_id', 'label' => 'District', 'options' => $this->lookups->districtOptions()],
            ],
        ]);
    }

    public function create(): Response
    {
        $farmerId = $this->request->int('farmer_id');

        return $this->render('gardens/form', [
            'pageTitle' => 'Add garden',
            'record'    => $farmerId > 0 ? ['farmer_id' => $farmerId] : null,
            'sections'  => $this->sections(),
            'gpsLng'    => '',
        ]);
    }

    public function store(): Response
    {
        $this->assertCsrf();

        $input = $this->request->all();
        $fields = array_merge(...array_column($this->sections(), 'fields'));

        $validator = Form::validate($fields, $input);
        $validator->numeric('acreage', 'Acreage', true);
        $validator->minValue('acreage', 0.01, 'Acreage');

        if ($validator->fails()) {
            return $this->backWithErrors(url('gardens/create'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, [
            'altitude', 'village_id', 'village_name', 'water_source', 'terrain', 'notes', 'status',
        ]);

        $values['garden_code'] = Identifier::generate('garden');
        $values['created_by']  = $this->auth->id();

        $id     = $this->db->insert('gardens', $values);
        $garden = $this->findByCodeOrFail($values['garden_code']);

        Audit::log('create', 'gardens', 'garden', $id, $garden['garden_code'], null, $values);

        Flash::success('Garden ' . $garden['garden_code'] . ' has been added.');

        return $this->redirect(url('gardens/' . $garden['garden_code']));
    }

    public function show(string $code): Response
    {
        $garden = $this->findByCodeOrFail($code);

        $plantings = $this->db->select(
            'SELECT p.*, c.name AS crop_name, v.name AS variety_name, s.season_code
             FROM plantings p
             INNER JOIN crops c ON c.id = p.crop_id
             LEFT JOIN crop_varieties v ON v.id = p.variety_id
             LEFT JOIN seasons s ON s.id = p.season_id
             WHERE p.garden_id = :id ORDER BY p.planting_date DESC',
            ['id' => $garden['id']]
        );

        $activities = $this->db->select(
            'SELECT a.*, ' . Lookups::activityNameSql('a') . ' AS activity_name
             FROM crop_activities a
             WHERE a.garden_id = :id
             ORDER BY a.due_date DESC LIMIT 25',
            ['id' => $garden['id']]
        );

        $inspections = $this->db->select(
            'SELECT i.inspection_code, i.inspection_date, i.crop_condition, i.pest_status,
                    i.disease_status, i.weed_status, i.recommendations, o.full_name AS officer_name
             FROM inspections i
             LEFT JOIN field_officers o ON o.id = i.officer_id
             WHERE i.garden_id = :id ORDER BY i.inspection_date DESC LIMIT 10',
            ['id' => $garden['id']]
        );

        $harvests = $this->db->select(
            'SELECT hr.harvest_code, hr.harvest_date, hr.quantity_harvested, hr.unit, hr.quality, c.name AS crop_name
             FROM harvest_records hr
             LEFT JOIN crops c ON c.id = hr.crop_id
             WHERE hr.garden_id = :id ORDER BY hr.harvest_date DESC LIMIT 10',
            ['id' => $garden['id']]
        );

        $history = $this->db->select(
            'SELECT h.*, u.username
             FROM garden_history h
             LEFT JOIN users u ON u.id = h.created_by
             WHERE h.garden_id = :id ORDER BY h.id DESC LIMIT 20',
            ['id' => $garden['id']]
        );

        $problems = $this->db->select(
            'SELECT pr.problem_code, pr.report_date, pr.severity, pr.status, pt.name AS problem_type
             FROM problem_reports pr
             LEFT JOIN problem_types pt ON pt.id = pr.problem_type_id
             WHERE pr.garden_id = :id ORDER BY pr.report_date DESC LIMIT 10',
            ['id' => $garden['id']]
        );

        return $this->render('gardens/show', [
            'pageTitle'   => $garden['name'],
            'garden'      => $garden,
            'plantings'   => $plantings,
            'activities'  => $activities,
            'inspections' => $inspections,
            'harvests'    => $harvests,
            'problems'    => $problems,
            'history'     => $history,
        ]);
    }

    public function edit(string $code): Response
    {
        $garden = $this->findByCodeOrFail($code);

        return $this->render('gardens/form', [
            'pageTitle' => 'Edit ' . $garden['garden_code'],
            'record'    => $garden,
            'sections'  => $this->sections(),
            'gpsLng'    => (string) ($garden['longitude'] ?? ''),
        ]);
    }

    public function update(string $code): Response
    {
        $this->assertCsrf();

        $garden = $this->findByCodeOrFail($code);
        $input  = $this->request->all();
        $fields = array_merge(...array_column($this->sections(), 'fields'));

        $validator = Form::validate($fields, $input);
        $validator->numeric('acreage', 'Acreage', true);
        $validator->minValue('acreage', 0.01, 'Acreage');

        if ($validator->fails()) {
            return $this->backWithErrors(url('gardens/' . $code . '/edit'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, [
            'altitude', 'village_id', 'village_name', 'water_source', 'terrain', 'notes', 'status',
        ]);

        $before = $garden;

        $this->db->update('gardens', $values, 'id = :id', ['id' => $garden['id']]);

        // Acreage and status changes are part of the garden's legal and
        // agronomic history, so they are recorded even when the rest is not.
        foreach (['acreage', 'status', 'land_ownership'] as $tracked) {
            if (array_key_exists($tracked, $values) && (string) $values[$tracked] !== (string) $before[$tracked]) {
                $this->db->insert('garden_history', [
                    'garden_id'    => $garden['id'],
                    'farmer_id'    => $garden['farmer_id'],
                    'event_type'   => $tracked === 'acreage' ? 'acreage_change' : 'status_change',
                    'from_value'   => (string) $before[$tracked],
                    'to_value'     => (string) $values[$tracked],
                    'reason'       => $this->request->string('reason') ?: null,
                    'changed_by'   => $this->auth->id(),
                    'created_by'   => $this->auth->id(),
                ]);
            }
        }

        Audit::log('update', 'gardens', 'garden', (int) $garden['id'], $garden['garden_code'], $before, $values);

        Flash::success('Garden record updated.');

        return $this->redirect(url('gardens/' . $garden['garden_code']));
    }

    public function destroy(string $code): Response
    {
        $this->assertCsrf();

        $garden = $this->findByCodeOrFail($code);

        $plantings = (int) $this->db->scalar('SELECT COUNT(*) FROM plantings WHERE garden_id = :id', ['id' => $garden['id']]);

        if ($plantings > 0) {
            Flash::error(
                'This garden has ' . $plantings . ' planting record(s) attached. Mark it as fallow or abandoned instead so '
                . 'its history stays intact for traceability.'
            );

            return $this->redirect(url('gardens/' . $garden['garden_code']));
        }

        // Soft delete: the code and its history are never reused.
        $this->db->update('gardens', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $garden['id']]);

        Audit::log('delete', 'gardens', 'garden', (int) $garden['id'], $garden['garden_code'], $garden);

        Flash::success('Garden ' . $garden['garden_code'] . ' has been removed. Its code is retired and will not be reused.');

        return $this->redirect(url('gardens'));
    }

    /**
     * Full change history for a plot, used when a farmer disputes what was
     * recorded against it.
     */
    public function history(string $code): Response
    {
        $garden = $this->findByCodeOrFail($code);

        $history = $this->db->select(
            'SELECT h.*, u.username
             FROM garden_history h
             LEFT JOIN users u ON u.id = h.created_by
             WHERE h.garden_id = :id ORDER BY h.id DESC',
            ['id' => $garden['id']]
        );

        return $this->render('gardens/history', [
            'pageTitle' => 'History - ' . $garden['garden_code'],
            'garden'    => $garden,
            'history'   => $history,
        ]);
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'title'  => 'Garden',
                'fields' => [
                    ['name' => 'garden_code', 'label' => 'Garden ID', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued automatically when the garden is saved.'],
                    ['name' => 'farmer_id', 'label' => 'Farmer', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => $this->lookups->farmerOptions(), 'table' => 'farmers'],
                    ['name' => 'name', 'label' => 'Garden name', 'type' => Form::TEXT, 'required' => true, 'colspan' => 6,
                     'placeholder' => 'e.g. Home plot, Hill side, River bank'],
                    ['name' => 'acreage', 'label' => 'Acreage', 'type' => Form::NUMBER, 'required' => true, 'colspan' => 6,
                     'min' => 0.01, 'step' => 0.01],
                    ['name' => 'status', 'label' => 'Status', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions("'active','fallow','abandoned'", '')],
                ],
            ],
            [
                'title'  => 'Location',
                'fields' => [
                    ['name' => 'region_id', 'label' => 'Region', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::options($this->lookups->regions(), 'id', 'name', 'Select region...'), 'table' => 'regions'],
                    ['name' => 'district_id', 'label' => 'District', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->districtOptions('Select district...'), 'table' => 'districts'],
                    ['name' => 'sub_county_id', 'label' => 'Sub-county', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->subCountyOptions('Select sub-county...'), 'table' => 'sub_counties'],
                    ['name' => 'parish_id', 'label' => 'Parish', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->parishOptions('Select parish...'), 'table' => 'parishes'],
                    ['name' => 'village_id', 'label' => 'Village', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->villageOptions('Select village...'), 'table' => 'villages'],
                    ['name' => 'village_name', 'label' => 'Site description', 'type' => Form::TEXT, 'colspan' => 6],
                    ['name' => 'latitude', 'label' => 'GPS location', 'type' => Form::GPS, 'colspan' => 12,
                     'help' => 'Capture the plot corner on this device, or type the coordinates.'],
                    ['name' => 'altitude', 'label' => 'Altitude (m)', 'type' => Form::NUMBER, 'colspan' => 6, 'step' => 0.1],
                ],
            ],
            [
                'title'  => 'Land and soil',
                'fields' => [
                    ['name' => 'land_ownership', 'label' => 'Land ownership', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions("'owned','rented','leased','communal','inherited'", '')],
                    ['name' => 'soil_type', 'label' => 'Soil type', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions("'clay','loam','sandy','silt','peat','red','black'", 'Not known')],
                    ['name' => 'irrigation_status', 'label' => 'Irrigation', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions("'none','rainfed','borehole','irrigation_canal','tank','river'", '')],
                    ['name' => 'water_source', 'label' => 'Water source', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions("'none','borehole','stream','well','rain','municipal'", 'Not applicable')],
                    ['name' => 'terrain', 'label' => 'Terrain', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions("'flat','gentle_slope','steep_slope','valley','hilltop'", 'Not recorded')],
                    ['name' => 'notes', 'label' => 'Notes', 'type' => Form::TEXTAREA, 'colspan' => 12],
                ],
            ],
        ];
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }

    private function findByCodeOrFail(string $code): array
    {
        $garden = $this->db->selectOne(
            'SELECT g.*, f.full_name, f.farmer_code, f.phone,
                    r.name AS region_name, d.name AS district_name, sc.name AS sub_county_name,
                    pa.name AS parish_name, v.name AS registered_village_name
             FROM gardens g
             INNER JOIN farmers f ON f.id = g.farmer_id
             LEFT JOIN regions r ON r.id = g.region_id
             LEFT JOIN districts d ON d.id = g.district_id
             LEFT JOIN sub_counties sc ON sc.id = g.sub_county_id
             LEFT JOIN parishes pa ON pa.id = g.parish_id
             LEFT JOIN villages v ON v.id = g.village_id
             WHERE g.garden_code = :code AND g.deleted_at IS NULL LIMIT 1',
            ['code' => $code]
        );

        if ($garden === null) {
            throw new HttpException(404, 'No garden found with the ID ' . $code . '.');
        }

        return $garden;
    }
}
