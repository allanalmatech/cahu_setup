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
use Services\CropLifecycle;
use Services\Forecaster;
use Services\Lookups;

/**
 * Plantings: the point where a crop is committed to a plot. Recording a
 * planting also generates the activity schedule and the yield forecast, so all
 * three happen in one transaction and cannot drift apart.
 */
final class PlantingController extends Controller
{
    private Lookups $lookups;
    private CropLifecycle $lifecycle;
    private Forecaster $forecaster;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups    = new Lookups($db);
        $this->lifecycle  = new CropLifecycle($db);
        $this->forecaster = new Forecaster($db);
    }

    public function index(): Response
    {
        $params = $this->listParams();

        $where  = ['1 = 1'];
        $values = [];

        if ($params['search'] !== '') {
            $where[]      = '(p.planting_code LIKE :s OR c.name LIKE :s OR f.full_name LIKE :s OR f.farmer_code LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        foreach (['crop_id' => 'p.crop_id', 'season_id' => 'p.season_id', 'status' => 'p.status'] as $key => $column) {
            if (($params['filters'][$key] ?? '') !== '') {
                $where[]          = $column . ' = :' . $key;
                $values[$key]     = $params['filters'][$key];
            }
        }

        if (($params['filters']['farmer_id'] ?? '') !== '') {
            $where[]           = 'p.farmer_id = :farmer_id';
            $values['farmer_id'] = $params['filters']['farmer_id'];
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total     = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM plantings p
             INNER JOIN crops c ON c.id = p.crop_id
             INNER JOIN farmers f ON f.id = p.farmer_id' . $whereSql,
            $values
        );
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $sortable = ['planting_code', 'planting_date', 'acreage_planted', 'status', 'expected_harvest_date'];
        $column   = in_array($params['sort'], $sortable, true) ? $params['sort'] : 'planting_date';
        $order    = $params['direction'] === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->db->select(
            'SELECT p.*, c.name AS crop_name, v.name AS variety_name, s.season_code, s.name AS season_name,
                    f.full_name, f.farmer_code, g.garden_code, g.name AS garden_name,
                    (SELECT COUNT(*) FROM crop_activities a WHERE a.planting_id = p.id) AS activity_count,
                    (SELECT COUNT(*) FROM crop_activities a WHERE a.planting_id = p.id AND a.status = \'completed\') AS activity_done,
                    (SELECT COALESCE(SUM(hr.quantity_harvested), 0) FROM harvest_records hr WHERE hr.planting_id = p.id) AS harvested
             FROM plantings p
             INNER JOIN crops c ON c.id = p.crop_id
             LEFT JOIN crop_varieties v ON v.id = p.variety_id
             LEFT JOIN seasons s ON s.id = p.season_id
             INNER JOIN farmers f ON f.id = p.farmer_id
             LEFT JOIN gardens g ON g.id = p.garden_id'
            . $whereSql
            . ' ORDER BY p.' . $column . ' ' . $order
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('plantings/index', [
            'pageTitle' => 'Crops and plantings',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $params['sort'],
            'direction' => $params['direction'],
            'filters'   => [
                ['key' => 'crop_id', 'label' => 'Crop', 'options' => Form::options($this->lookups->crops(), 'id', 'name', 'All crops')],
                ['key' => 'season_id', 'label' => 'Season', 'options' => $this->lookups->seasonOptions('All seasons')],
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions("'planned','planted','harvested','failed','terminated'", 'All statuses')],
            ],
        ]);
    }

    public function create(): Response
    {
        $gardenId = $this->request->int('garden_id');
        $record   = $gardenId > 0 ? ['garden_id' => $gardenId] : null;

        if ($record !== null) {
            $garden = $this->db->selectOne('SELECT farmer_id FROM gardens WHERE id = :id', ['id' => $gardenId]);
            if ($garden !== null) {
                $record['farmer_id'] = (int) $garden['farmer_id'];
            }
        }

        return $this->render('plantings/form', [
            'pageTitle' => 'Record planting',
            'record'    => $record,
            'sections'  => $this->sections(),
            'gpsLng'    => '',
        ]);
    }

    public function store(): Response
    {
        $this->assertCsrf();

        $input  = $this->request->all();
        $fields = array_merge(...array_column($this->sections(), 'fields'));

        $validator = Form::validate($fields, $input);
        $validator->dateAfter('expected_harvest_date', 'planting_date', 'Expected harvest date', 'planting date');
        $validator->numeric('acreage_planted', 'Area planted', true);
        $validator->minValue('acreage_planted', 0.01, 'Area planted');

        if ($validator->fails()) {
            return $this->backWithErrors(url('plantings/create'), $validator->errors(), $input);
        }

        $garden = $this->db->selectOne('SELECT * FROM gardens WHERE id = :id', ['id' => $input['garden_id'] ?? 0]);

        if ($garden === null) {
            Flash::error('That garden could not be found.');

            return $this->backWithErrors(url('plantings/create'), ['garden_id' => 'Select a garden.'], $input);
        }

        // Planting more than the whole plot is almost always a typo, so it is
        // refused rather than silently accepted.
        if ((float) $input['acreage_planted'] > (float) $garden['acreage']) {
            return $this->backWithErrors(
                url('plantings/create'),
                ['acreage_planted' => sprintf(
                    'You cannot plant more than the garden\'s %.2f acres.',
                    (float) $garden['acreage']
                )],
                $input
            );
        }

        $values = Form::extract($fields, $input, ['variety_id', 'expected_harvest_date', 'notes', 'officer_id']);

        $values['farmer_id']   = (int) $garden['farmer_id'];
        $values['planting_date'] = $values['planting_date'] ?? date('Y-m-d');

        if (empty($values['expected_harvest_date'])) {
            $values['expected_harvest_date'] = $this->lifecycle->expectedHarvestDate(
                (int) $values['crop_id'],
                isset($values['variety_id']) ? (int) $values['variety_id'] : null,
                (string) $values['planting_date']
            );
        }

        $result = $this->db->transaction(function () use ($values): array {
            $values['planting_code'] = Identifier::generate('planting');
            $values['created_by']    = $this->auth->id();
            $values['status']        = 'planted';

            $plantingId = $this->db->insert('plantings', $values);

            $activities = $this->lifecycle->generateSchedule($plantingId);
            $this->forecaster->writeForecast($plantingId);

            return ['id' => $plantingId, 'code' => $values['planting_code'], 'activities' => $activities];
        });

        Audit::log('create', 'plantings', 'planting', $result['id'], $result['code'], null, $values);

        $message = 'Planting ' . $result['code'] . ' recorded. ';

        if ($result['activities'] > 0) {
            $message .= $result['activities'] . ' scheduled activity record(s) were generated from the crop lifecycle.';
        } else {
            $message .= 'No lifecycle template is configured for this crop yet, so no activities were scheduled.';
        }

        Flash::success($message);

        return $this->redirect(url('plantings/' . $result['code']));
    }

    public function show(string $code): Response
    {
        $planting = $this->findByCodeOrFail($code);

        $activities = $this->db->select(
            'SELECT a.*, ' . Lookups::activityNameSql('a') . ' AS activity_name,
                    o.full_name AS officer_name
             FROM crop_activities a
             LEFT JOIN field_officers o ON o.id = a.officer_id
             WHERE a.planting_id = :id ORDER BY a.due_date ASC',
            ['id' => $planting['id']]
        );

        // A harvest joins to its batch through harvest_batch_items, so the batch code
// is resolved here rather than stored on the harvest row.
        $harvests = $this->db->select(
            'SELECT hr.*, b.id AS batch_id, b.batch_code
             FROM harvest_records hr
             LEFT JOIN harvest_batch_items hbi ON hbi.harvest_id = hr.id
             LEFT JOIN harvest_batches b ON b.id = hbi.batch_id
             WHERE hr.planting_id = :id ORDER BY hr.harvest_date DESC',
            ['id' => $planting['id']]
        );

        $inputs = $this->db->select(
            'SELECT iu.usage_code, iu.quantity_used, iu.unit, iu.application_date, iu.purpose,
                    i.name AS input_name, ic.name AS category_name
             FROM input_usage iu
             INNER JOIN inputs i ON i.id = iu.input_id
             INNER JOIN input_categories ic ON ic.id = i.category_id
             WHERE iu.farmer_id = :farmer AND iu.garden_id = :garden
             ORDER BY iu.application_date DESC LIMIT 25',
            ['farmer' => $planting['farmer_id'], 'garden' => $planting['garden_id']]
        );

        $inspections = $this->db->select(
            'SELECT inspection_code, inspection_date, crop_condition, pest_status, disease_status,
                    weed_status, estimated_yield_per_acre, recommendations
             FROM inspections WHERE planting_id = :id ORDER BY inspection_date DESC LIMIT 10',
            ['id' => $planting['id']]
        );

        $forecast = $this->db->selectOne(
            'SELECT * FROM harvest_forecasts WHERE planting_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $planting['id']]
        );

        $harvested = (float) array_sum(array_map('floatval', array_column($harvests, 'quantity_harvested')));

        return $this->render('plantings/show', [
            'pageTitle'  => $planting['crop_name'] . ' — ' . $planting['planting_code'],
            'planting'   => $planting,
            'activities' => $activities,
            'harvests'   => $harvests,
            'inputs'     => $inputs,
            'inspections' => $inspections,
            'forecast'   => $forecast,
            'harvested'  => $harvested,
        ]);
    }

    public function edit(string $code): Response
    {
        $planting = $this->findByCodeOrFail($code);

        return $this->render('plantings/form', [
            'pageTitle' => 'Edit ' . $planting['planting_code'],
            'record'    => $planting,
            'sections'  => $this->sections(),
            'gpsLng'    => '',
        ]);
    }

    public function update(string $code): Response
    {
        $this->assertCsrf();

        $planting = $this->findByCodeOrFail($code);
        $input    = $this->request->all();
        $fields   = array_merge(...array_column($this->sections(), 'fields'));

        $validator = Form::validate($fields, $input);
        $validator->dateAfter('expected_harvest_date', 'planting_date', 'Expected harvest date', 'planting date');
        $validator->numeric('acreage_planted', 'Area planted', true);
        $validator->minValue('acreage_planted', 0.01, 'Area planted');

        if ($validator->fails()) {
            return $this->backWithErrors(url('plantings/' . $code . '/edit'), $validator->errors(), $input);
        }

        $values   = Form::extract($fields, $input, ['variety_id', 'expected_harvest_date', 'notes', 'officer_id']);
        $before   = $planting;

        // Changing the crop or the planting date invalidates the generated
        // schedule, so it is rebuilt — but only while nothing has been recorded
        // against it, because completed work must not be thrown away.
        $scheduleInvalidated = (string) $values['crop_id'] !== (string) $planting['crop_id']
            || (string) ($values['planting_date'] ?? '') !== (string) $planting['planting_date'];

        $this->db->transaction(function () use ($values, $planting, $scheduleInvalidated): void {
            $this->db->update('plantings', $values, 'id = :id', ['id' => $planting['id']]);

            if (!$scheduleInvalidated) {
                return;
            }

            $recorded = (int) $this->db->scalar(
                "SELECT COUNT(*) FROM crop_activities WHERE planting_id = :id AND status <> 'pending'",
                ['id' => $planting['id']]
            );

            if ($recorded > 0) {
                $this->db->update(
                    'plantings',
                    ['schedule_generated' => 0],
                    'id = :id',
                    ['id' => $planting['id']]
                );

                Flash::warning(
                    'The crop or planting date changed, but ' . $recorded . ' activity record(s) already have work '
                    . 'recorded against them. Review the schedule on the planting record and add any missing activities by hand.'
                );

                return;
            }

            $this->lifecycle->discardSchedule((int) $planting['id']);
            $created = $this->lifecycle->generateSchedule((int) $planting['id']);
            $this->forecaster->writeForecast((int) $planting['id']);

            Flash::info('The activity schedule was rebuilt: ' . $created . ' task(s) generated.');
        });

        Audit::log('update', 'plantings', 'planting', (int) $planting['id'], $planting['planting_code'], $before, $values);

        Flash::success('Planting record updated.');

        return $this->redirect(url('plantings/' . $planting['planting_code']));
    }

    public function destroy(string $code): Response
    {
        $this->assertCsrf();

        $planting = $this->findByCodeOrFail($code);

        $harvests = (int) $this->db->scalar('SELECT COUNT(*) FROM harvest_records WHERE planting_id = :id', ['id' => $planting['id']]);

        if ($harvests > 0) {
            Flash::error(
                'This planting has ' . $harvests . ' harvest record(s) attached and forms part of a traceability '
                . 'chain. Mark it as failed instead of deleting it.'
            );

            return $this->redirect(url('plantings/' . $planting['planting_code']));
        }

        // plantings.status has no 'cancelled' value; a planting that is called
        // off before producing anything is 'terminated'.
        $this->db->transaction(function () use ($planting): void {
            $this->lifecycle->discardSchedule((int) $planting['id']);
            $this->db->update('plantings', ['status' => 'terminated'], 'id = :id', ['id' => $planting['id']]);
        });

        Audit::log('terminate', 'plantings', 'planting', (int) $planting['id'], $planting['planting_code'], $planting);

        Flash::success('Planting ' . $planting['planting_code'] . ' has been terminated. The record is kept for history.');

        return $this->redirect(url('plantings'));
    }

    /**
     * The activity schedule generated for this planting, as a standalone page
     * so an officer can print it or work through it without the rest of the
     * planting record.
     */
    public function schedule(string $code): Response
    {
        $planting = $this->findByCodeOrFail($code);

        $activities = $this->db->select(
            'SELECT a.*, ' . Lookups::activityNameSql('a') . ' AS activity_name,
                    o.full_name AS officer_name
             FROM crop_activities a
             LEFT JOIN field_officers o ON o.id = a.officer_id
             WHERE a.planting_id = :id ORDER BY a.due_date ASC',
            ['id' => $planting['id']]
        );

        return $this->render('plantings/schedule', [
            'pageTitle'  => 'Schedule — ' . $planting['crop_name'],
            'planting'   => $planting,
            'activities' => $activities,
        ]);
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'title'  => 'What is being planted',
                'fields' => [
                    ['name' => 'planting_code', 'label' => 'Planting ID', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued automatically when the planting is saved.'],
                    ['name' => 'garden_id', 'label' => 'Garden', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => $this->lookups->gardenOptions(), 'table' => 'gardens',
                     'help' => 'The farmer is taken from the garden, so a planting can never be filed against the wrong person.'],
                    ['name' => 'crop_id', 'label' => 'Crop', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => $this->lookups->cropOptions(), 'table' => 'crops'],
                    ['name' => 'variety_id', 'label' => 'Variety', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->varietyOptions('No specific variety'), 'table' => 'crop_varieties'],
                    ['name' => 'season_id', 'label' => 'Season', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => $this->lookups->seasonOptions()],
                    ['name' => 'planting_date', 'label' => 'Planting date', 'type' => Form::DATE, 'required' => true, 'colspan' => 6,
                     'default' => date('Y-m-d')],
                    ['name' => 'acreage_planted', 'label' => 'Area planted (acres)', 'type' => Form::NUMBER,
                     'required' => true, 'min' => 0.01, 'step' => 0.01, 'colspan' => 6],
                    ['name' => 'expected_harvest_date', 'label' => 'Expected harvest', 'type' => Form::DATE, 'colspan' => 6,
                     'help' => 'Calculated from the crop or variety maturity period when left empty.'],
                ],
            ],
            [
                'title'  => 'Seed and method',
                'fields' => [
                    ['name' => 'seed_quantity', 'label' => 'Seed / planting material used', 'type' => Form::NUMBER,
                     'min' => 0, 'step' => 0.01, 'colspan' => 6],
                    ['name' => 'seed_unit', 'label' => 'Unit', 'type' => Form::TEXT, 'colspan' => 6,
                     'placeholder' => 'kg, bags, seedlings...'],
                    ['name' => 'planting_method', 'label' => 'Planting method', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions("'row','broadcast','transplanting','seedling','other'", '')],
                    ['name' => 'growth_stage', 'label' => 'Growth stage', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions("'germination','vegetative','flowering','fruiting','maturity','harvested','failed'", 'Not assessed yet')],
                    ['name' => 'expected_yield', 'label' => 'Expected yield', 'type' => Form::NUMBER, 'min' => 0, 'step' => 0.01, 'colspan' => 6,
                     'help' => 'Leave empty to use the yield per acre held on the crop record.'],
                    ['name' => 'yield_unit', 'label' => 'Yield unit', 'type' => Form::TEXT, 'colspan' => 6,
                     'placeholder' => 'kg, tonnes, crates...'],
                    ['name' => 'officer_id', 'label' => 'Supervising officer', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->officerOptions(), 'table' => 'field_officers'],
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
        $planting = $this->db->selectOne(
            'SELECT p.*, c.name AS crop_name, c.unit AS crop_unit, v.name AS variety_name,
                    s.season_code, s.name AS season_name,
                    f.full_name, f.farmer_code, f.phone,
                    g.garden_code, g.name AS garden_name, g.acreage AS garden_acreage,
                    o.full_name AS officer_name
             FROM plantings p
             INNER JOIN crops c ON c.id = p.crop_id
             LEFT JOIN crop_varieties v ON v.id = p.variety_id
             LEFT JOIN seasons s ON s.id = p.season_id
             INNER JOIN farmers f ON f.id = p.farmer_id
             LEFT JOIN gardens g ON g.id = p.garden_id
             LEFT JOIN field_officers o ON o.id = p.officer_id
             WHERE p.planting_code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($planting === null) {
            throw new HttpException(404, 'No planting found with the ID ' . $code . '.');
        }

        return $planting;
    }
}
