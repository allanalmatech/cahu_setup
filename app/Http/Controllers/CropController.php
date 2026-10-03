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
use Services\Lookups;

/**
 * Crops and the lifecycle templates that drive them.
 *
 * A crop is reference data, but it is the anchor for every production record
 * downstream: plantings, activities and harvests all point back at it. It is
 * therefore never removed once it carries history — it is deactivated instead,
 * which keeps old records readable while hiding it from new selections. The
 * lifecycle templates stored alongside it are pure configuration and are
 * resolved here with exactly the same specificity rules the CropLifecycle
 * service applies when a planting is recorded, so the screen an administrator
 * configures is the schedule a farmer actually gets.
 */
final class CropController extends Controller
{
    private Lookups $lookups;
    private CropLifecycle $lifecycle;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups   = new Lookups($db);
        $this->lifecycle = new CropLifecycle($db);
    }

    public function index(): Response
    {
        $params = $this->listParams();
        $params['filters']['category'] = $this->request->string('category');

        $where  = ['1 = 1'];
        $values = [];

        if ($params['search'] !== '') {
            $where[]      = '(c.crop_code LIKE :s OR c.name LIKE :s OR c.description LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        foreach (['status' => 'c.status', 'category' => 'c.category'] as $key => $column) {
            if (($params['filters'][$key] ?? '') !== '') {
                $where[]      = $column . ' = :' . $key;
                $values[$key] = $params['filters'][$key];
            }
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total     = (int) $this->db->scalar('SELECT COUNT(*) FROM crops c' . $whereSql, $values);
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $rows = $this->db->select(
            'SELECT c.id, c.crop_code, c.name, c.description, c.category, c.lifecycle_days,
                    c.expected_yield_per_acre, c.yield_unit, c.primary_unit, c.is_perishable,
                    c.status, c.created_at,
                    (SELECT COUNT(*) FROM crop_varieties v WHERE v.crop_id = c.id) AS variety_count,
                    (SELECT COALESCE(SUM(p.acreage_planted), 0) FROM plantings p WHERE p.crop_id = c.id) AS planted_acreage,
                    (SELECT COUNT(*) FROM crop_activity_templates t WHERE t.crop_id = c.id AND t.active = 1) AS template_count
             FROM crops c'
            . $whereSql
            . ' ORDER BY ' . $this->sortClause($params['sort'], $params['direction'])
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('crops/index', [
            'pageTitle' => 'Crops',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $params['sort'],
            'direction' => $params['direction'],
            'filters'   => [
                ['key' => 'category', 'label' => 'Category', 'options' => Form::enumOptions("'cereal','legume','beverage','vegetable','fruit','root_tuber','oilseed','fibre','other'", 'All categories')],
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions("'active','inactive'", 'All statuses')],
            ],
        ]);
    }

    public function create(): Response
    {
        return $this->render('crops/form', [
            'pageTitle' => 'Add crop',
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

        if ($validator->fails()) {
            return $this->backWithErrors(url('crops/create'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, [
            'description', 'lifecycle_days', 'expected_yield_per_acre', 'yield_unit', 'primary_unit',
        ]);

        $id = $this->db->transaction(function () use ($values): int {
            $values['crop_code'] = Identifier::generate('crop');

            return $this->db->insert('crops', $values);
        });

        Audit::log('create', 'crops', 'crop', $id, (string) $values['crop_code'], null, $values);

        Flash::success('Crop ' . $values['crop_code'] . ' has been added.');

        return $this->redirect(url('crops/' . $id . '/lifecycle'));
    }

    public function edit(string $id): Response
    {
        $crop = $this->findOrFail($id);

        return $this->render('crops/form', [
            'pageTitle' => 'Edit ' . $crop['crop_code'],
            'record'    => $crop,
            'sections'  => $this->sections(),
        ]);
    }

    public function update(string $id): Response
    {
        $this->assertCsrf();

        $crop     = $this->findOrFail($id);
        $sections = $this->sections();
        $fields   = array_merge(...array_column($sections, 'fields'));
        $input    = $this->request->all();

        $validator = Form::validate($fields, $input);

        if ($validator->fails()) {
            return $this->backWithErrors(url('crops/' . $id . '/edit'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, [
            'description', 'lifecycle_days', 'expected_yield_per_acre', 'yield_unit', 'primary_unit',
        ]);

        $this->db->update('crops', $values, 'id = :id', ['id' => $crop['id']]);

        Audit::log('update', 'crops', 'crop', (int) $crop['id'], (string) $crop['crop_code'], $crop, $values);

        Flash::success('Crop record updated.');

        return $this->redirect(url('crops/' . $crop['id'] . '/lifecycle'));
    }

    /**
     * A crop that already carries production history is deactivated rather than
     * removed: the planting, activity and harvest records that reference it are
     * the traceability evidence, and losing the crop would orphan all of them.
     */
    public function destroy(string $id): Response
    {
        $this->assertCsrf();

        $crop = $this->findOrFail($id);

        $plantings = (int) $this->db->scalar('SELECT COUNT(*) FROM plantings WHERE crop_id = :id', ['id' => $crop['id']]);
        $activities = (int) $this->db->scalar('SELECT COUNT(*) FROM crop_activities WHERE crop_id = :id', ['id' => $crop['id']]);
        $harvests   = (int) $this->db->scalar('SELECT COUNT(*) FROM harvest_records WHERE crop_id = :id', ['id' => $crop['id']]);
        $dependents = $plantings + $activities + $harvests;

        if ($dependents > 0) {
            $this->db->update('crops', ['status' => 'inactive'], 'id = :id', ['id' => $crop['id']]);

            Audit::log('deactivate', 'crops', 'crop', (int) $crop['id'], (string) $crop['crop_code'], $crop, ['status' => 'inactive']);

            $breakdown = [];
            if ($plantings > 0) {
                $breakdown[] = $plantings . ' planting(s)';
            }
            if ($activities > 0) {
                $breakdown[] = $activities . ' activity record(s)';
            }
            if ($harvests > 0) {
                $breakdown[] = $harvests . ' harvest record(s)';
            }

            Flash::warning(
                'This crop is attached to ' . implode(', ', $breakdown) . ', so it has been marked inactive rather '
                . 'than deleted. It is now hidden from new plantings and its code is retired, but the history it '
                . 'carries stays intact for traceability.'
            );

            return $this->redirect(url('crops'));
        }

        $this->db->transaction(function () use ($crop): void {
            $this->db->delete('crop_activity_templates', 'crop_id = :id', ['id' => $crop['id']]);
            $this->db->delete('crops', 'id = :id', ['id' => $crop['id']]);
        });

        Audit::log('delete', 'crops', 'crop', (int) $crop['id'], (string) $crop['crop_code'], $crop);

        Flash::success('Crop ' . $crop['crop_code'] . ' has been deleted, along with its lifecycle templates.');

        return $this->redirect(url('crops'));
    }

    /**
     * The lifecycle that will be scheduled for a planting of this crop.
     *
     * The crop-level timeline is resolved first, then each variety is resolved
     * against the same crop-level set so a variety only contributes the day
     * offsets where it actually overrides the crop or global default. That is
     * the same rule CropLifecycle::resolveTemplates() applies at planting time.
     */
    public function lifecycle(string $id): Response
    {
        $crop = $this->findOrFail($id);

        $candidates = $this->db->select(
            'SELECT t.id, t.crop_id, t.variety_id, t.activity_type, t.description, t.day_offset,
                    t.duration_days, t.assign_role, t.is_mandatory, t.sort_order, v.name AS variety_name
             FROM crop_activity_templates t
             LEFT JOIN crop_varieties v ON v.id = t.variety_id
             WHERE t.active = 1
               AND (t.crop_id = :crop
                    OR (t.crop_id IS NULL AND t.variety_id IS NULL)
                    OR t.variety_id IN (SELECT id FROM crop_varieties WHERE crop_id = :crop))
             ORDER BY t.day_offset ASC, t.sort_order ASC, t.id ASC',
            ['crop' => $crop['id']]
        );

        $defaults = array_values(array_filter(
            $candidates,
            static fn (array $template): bool => $template['variety_id'] === null
        ));

        $rows = [];

        foreach ($this->lifecycle->resolveTemplates($defaults) as $template) {
            $template['_scope']  = $template['crop_id'] === null ? 'global' : 'crop';
            $template['_variety'] = null;
            $rows[] = $template;
        }

        $varieties = $this->db->select(
            'SELECT id, name, variety_code FROM crop_varieties WHERE crop_id = :id ORDER BY name',
            ['id' => $crop['id']]
        );

        foreach ($varieties as $variety) {
            $scoped = array_values(array_filter(
                $candidates,
                static fn (array $template): bool => (int) $template['variety_id'] === (int) $variety['id']
            ));

            if ($scoped === []) {
                continue;
            }

            foreach ($this->lifecycle->resolveTemplates(array_merge($scoped, $defaults)) as $template) {
                if ($template['variety_id'] === null) {
                    continue;
                }

                $template['_scope']  = 'variety';
                $template['_variety'] = (string) $variety['name'];
                $rows[] = $template;
            }
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => [
                (int) $a['day_offset'],
                (string) $a['_scope'],
                (string) $a['_variety'],
                (int) $a['id'],
            ] <=> [
                (int) $b['day_offset'],
                (string) $b['_scope'],
                (string) $b['_variety'],
                (int) $b['id'],
            ]
        );

        return $this->render('crops/lifecycle', [
            'pageTitle'     => 'Lifecycle — ' . $crop['name'],
            'crop'          => $crop,
            'rows'          => $rows,
            'varieties'     => $varieties,
            'fields'        => $this->lifecycleFields((int) $crop['id']),
            'globalCount'   => (int) $this->db->scalar(
                'SELECT COUNT(*) FROM crop_activity_templates WHERE crop_id IS NULL AND variety_id IS NULL AND active = 1'
            ),
        ]);
    }

    /**
     * Adds a crop-scoped or variety-scoped lifecycle template.
     */
    public function storeLifecycle(string $id): Response
    {
        $this->assertCsrf();

        $crop   = $this->findOrFail($id);
        $input  = $this->request->all();
        $fields = $this->lifecycleFields((int) $crop['id']);

        $validator = Form::validate($fields, $input);

        $offsetRaw = $this->request->string('day_offset');
        $offset    = $offsetRaw === '' ? null : filter_var($offsetRaw, FILTER_VALIDATE_INT);

        if ($offsetRaw !== '' && ($offset === false || $offset < -60 || $offset > 365)) {
            $validator->addError('day_offset', 'Day offset must be a whole number of days between -60 and 365.');
        }

        $varietyId = $this->request->int('variety_id');
        if ($varietyId > 0) {
            $owner = $this->db->scalar('SELECT crop_id FROM crop_varieties WHERE id = :id', ['id' => $varietyId]);

            if ($owner === null || (int) $owner !== (int) $crop['id']) {
                $validator->addError('variety_id', 'Select a variety that belongs to this crop.');
            }
        }

        if ($validator->fails()) {
            return $this->backWithErrors(url('crops/' . $crop['id'] . '/lifecycle'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, ['variety_id', 'description']);
        $offset = (int) $offset;

        $duplicate = $this->db->scalar(
            'SELECT 1 FROM crop_activity_templates
             WHERE crop_id <=> :crop AND variety_id <=> :variety
               AND day_offset = :offset AND activity_type = :type
             LIMIT 1',
            [
                'crop'    => $crop['id'],
                'variety' => $varietyId > 0 ? $varietyId : null,
                'offset'  => $offset,
                'type'    => $values['activity_type'],
            ]
        );

        if ($duplicate !== null) {
            return $this->backWithErrors(
                url('crops/' . $crop['id'] . '/lifecycle'),
                [
                    'activity_type' => 'That activity is already scheduled on day ' . $offset
                        . ' for this ' . ($varietyId > 0 ? 'variety' : 'crop') . '. Edit the existing template instead.',
                ],
                $input
            );
        }

        $values['crop_id']       = $crop['id'];
        $values['variety_id']    = $varietyId > 0 ? $varietyId : null;
        $values['day_offset']    = $offset;
        $values['duration_days'] = empty($values['duration_days']) ? 1 : (int) $values['duration_days'];
        $values['sort_order']    = empty($values['sort_order']) ? 0 : (int) $values['sort_order'];
        $values['active']        = 1;

        $templateId = $this->db->insert('crop_activity_templates', $values);

        Audit::log(
            'create',
            'crops',
            'crop_activity_template',
            $templateId,
            (string) $values['activity_type'],
            null,
            $values
        );

        Flash::success('Lifecycle step added. It will be scheduled on every new planting of this crop.');

        return $this->redirect(url('crops/' . $crop['id'] . '/lifecycle'));
    }

    public function destroyLifecycle(string $id): Response
    {
        $this->assertCsrf();

        $template = $this->db->selectOne(
            'SELECT t.*, v.name AS variety_name FROM crop_activity_templates t
             LEFT JOIN crop_varieties v ON v.id = t.variety_id
             WHERE t.id = :id',
            ['id' => $id]
        );

        if ($template === null) {
            throw new HttpException(404, 'That lifecycle template no longer exists.');
        }

        $this->db->delete('crop_activity_templates', 'id = :id', ['id' => $template['id']]);

        Audit::log('delete', 'crops', 'crop_activity_template', (int) $template['id'], (string) $template['activity_type'], $template);

        Flash::success('Lifecycle step removed. Plantings that already have a schedule are not affected.');

        if ($template['crop_id'] === null) {
            return $this->redirect(url('crops'));
        }

        return $this->redirect(url('crops/' . $template['crop_id'] . '/lifecycle'));
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'title'  => 'Crop',
                'fields' => [
                    ['name' => 'crop_code', 'label' => 'Crop code', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued automatically when the record is saved.'],
                    ['name' => 'name', 'label' => 'Crop name', 'type' => Form::TEXT, 'required' => true, 'colspan' => 6,
                     'placeholder' => 'e.g. Maize, Coffee, Groundnut'],
                    ['name' => 'category', 'label' => 'Category', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions("'cereal','legume','beverage','vegetable','fruit','root_tuber','oilseed','fibre','other'", 'Select...')],
                    ['name' => 'is_perishable', 'label' => 'Highly perishable produce', 'type' => Form::CHECKBOX, 'colspan' => 6,
                     'help' => 'Perishable produce is prioritised for collection and cold chain handling.'],
                    ['name' => 'status', 'label' => 'Status', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions("'active','inactive'", '')],
                ],
            ],
            [
                'title'  => 'Agronomy',
                'fields' => [
                    ['name' => 'lifecycle_days', 'label' => 'Lifecycle (days)', 'type' => Form::NUMBER,
                     'min' => 1, 'max' => 1000, 'colspan' => 6,
                     'help' => 'Days from planting to harvest. Used to predict the harvest date when none is entered.'],
                    ['name' => 'expected_yield_per_acre', 'label' => 'Expected yield per acre', 'type' => Form::NUMBER,
                     'min' => 0, 'step' => 0.001, 'colspan' => 6],
                    ['name' => 'yield_unit', 'label' => 'Yield unit', 'type' => Form::TEXT, 'colspan' => 6,
                     'placeholder' => 'kg, tonnes, crates...'],
                    ['name' => 'primary_unit', 'label' => 'Primary trade unit', 'type' => Form::TEXT, 'colspan' => 6,
                     'placeholder' => 'kg, bags, crates...'],
                    ['name' => 'description', 'label' => 'Description', 'type' => Form::TEXTAREA, 'colspan' => 12],
                ],
            ],
        ];
    }

    /**
     * Fields for the "add a lifecycle step" form on the lifecycle screen.
     *
     * @return array<int,array<string,mixed>>
     */
    private function lifecycleFields(int $cropId): array
    {
        return [
            ['name' => 'variety_id', 'label' => 'Variety', 'type' => Form::SELECT, 'colspan' => 6,
             'options' => Form::options(
                 $this->db->select('SELECT id, name FROM crop_varieties WHERE crop_id = :crop ORDER BY name', ['crop' => $cropId]),
                 'id',
                 'name',
                 'All varieties of this crop'
             ),
             'help' => 'Leave as "all varieties" to apply the step to every planting of this crop.'],
            ['name' => 'activity_type', 'label' => 'Activity', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
             'options' => Form::options($this->lookups->activityTypes(), 'code', 'name', 'Select activity...')],
            ['name' => 'day_offset', 'label' => 'Day offset', 'type' => Form::NUMBER, 'required' => true,
             'min' => -60, 'max' => 365, 'step' => 1, 'colspan' => 6,
             'help' => 'Days after planting. Use a negative number for pre-planting preparation, e.g. -14 for land clearing.'],
            ['name' => 'duration_days', 'label' => 'Duration (days)', 'type' => Form::NUMBER,
             'min' => 1, 'max' => 365, 'step' => 1, 'default' => 1, 'colspan' => 6],
            ['name' => 'assign_role', 'label' => 'Assigned to', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
             'options' => Form::enumOptions("'farmer','officer','both'", '')],
            ['name' => 'sort_order', 'label' => 'Sort order', 'type' => Form::NUMBER,
             'min' => 0, 'max' => 9999, 'step' => 1, 'colspan' => 6,
             'help' => 'Ties are broken by sort order, then by day offset.'],
            ['name' => 'is_mandatory', 'label' => 'Mandatory step', 'type' => Form::CHECKBOX, 'colspan' => 6,
             'help' => 'Mandatory steps are reported as missing when they are not completed.'],
            ['name' => 'description', 'label' => 'Instructions', 'type' => Form::TEXTAREA, 'colspan' => 12, 'rows' => 2,
             'maxlength' => 255],
        ];
    }

    private function sortClause(string $sort, string $direction): string
    {
        $allowed = ['crop_code', 'name', 'category', 'status', 'lifecycle_days', 'created_at'];
        $column  = in_array($sort, $allowed, true) ? $sort : 'name';
        $order   = $direction === 'asc' ? 'ASC' : 'DESC';

        return $sort === '' ? 'c.name ASC' : 'c.' . $column . ' ' . $order;
    }

    private function findOrFail(string $id): array
    {
        $crop = $this->db->selectOne('SELECT * FROM crops WHERE id = :id', ['id' => $id]);

        if ($crop === null) {
            throw new HttpException(404, 'No crop found with the ID ' . $id . '.');
        }

        return $crop;
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }
}
