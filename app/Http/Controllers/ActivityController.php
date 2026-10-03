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
 * Crop activities: the daily work of the crop cycle.
 *
 * Most activities are generated from the crop lifecycle when a planting is
 * recorded. This controller exists for the three cases a template cannot cover:
 * an extra task added by hand, recording the completion of a generated task,
 * and cancelling a task that no longer applies.
 */
final class ActivityController extends Controller
{
    private Lookups $lookups;
    private Notifier $notifier;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups  = new Lookups($db);
        $this->notifier = new Notifier($db);
    }

    public function index(): Response
    {
        $params = $this->listParams();

        $where  = ['1 = 1'];
        $values = [];

        if ($params['search'] !== '') {
            // One placeholder per column, all bound to the same term. The
            // connection uses native prepared statements, where PDO maps each
            // placeholder to exactly one bound value, so a single :s used three
            // times asks for a parameter that was never supplied and the query
            // fails with HY093.
            $term = '%' . $params['search'] . '%';

            $where[]      = '(a.activity_code LIKE :s1 OR ' . Lookups::activityNameSql('a') . ' LIKE :s2 OR f.full_name LIKE :s3)';
            $values['s1'] = $term;
            $values['s2'] = $term;
            $values['s3'] = $term;
        }

        foreach (['status' => 'a.status', 'crop_id' => 'a.crop_id', 'season_id' => 'a.season_id', 'officer_id' => 'a.officer_id'] as $key => $column) {
            if (($params['filters'][$key] ?? '') !== '') {
                $where[]      = $column . ' = :' . $key;
                $values[$key] = $params['filters'][$key];
            }
        }

        $scope = $this->scopeWhere($values);

        $whereSql = ' WHERE ' . implode(' AND ', $where) . $scope;

        $total     = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM crop_activities a
             INNER JOIN farmers f ON f.id = a.farmer_id' . $whereSql,
            $values
        );
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $sortable = ['planned_date', 'due_date', 'status', 'priority', 'activity_code'];
        $column   = in_array($params['sort'], $sortable, true) ? $params['sort'] : 'due_date';
        $order    = $params['direction'] === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->db->select(
            'SELECT a.*, ' . Lookups::activityNameSql('a') . ' AS activity_name,
                    f.full_name, f.farmer_code, c.name AS crop_name, g.garden_code, g.name AS garden_name,
                    o.full_name AS officer_name,
                    (a.due_date < CURDATE() AND a.status IN (\'pending\', \'due\')) AS is_late
             FROM crop_activities a
             INNER JOIN farmers f ON f.id = a.farmer_id
             LEFT JOIN crops c ON c.id = a.crop_id
             LEFT JOIN gardens g ON g.id = a.garden_id
             LEFT JOIN field_officers o ON o.id = a.officer_id'
            . $whereSql
            . ' ORDER BY a.' . $column . ' ' . $order
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('activities/index', [
            'pageTitle' => 'Crop activities',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $params['sort'],
            'direction' => $params['direction'],
            'summary'   => $this->summary(),
            'filters'   => [
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions("'pending','due','completed','overdue','cancelled'", 'All statuses')],
                ['key' => 'crop_id', 'label' => 'Crop', 'options' => $this->lookups->cropOptions('All crops')],
                ['key' => 'season_id', 'label' => 'Season', 'options' => $this->lookups->seasonOptions('All seasons')],
                ['key' => 'officer_id', 'label' => 'Officer', 'options' => $this->lookups->officerOptions('All officers')],
            ],
        ]);
    }

    /**
     * Month view of the schedule, driven by the same data as the register.
     */
    public function calendar(): Response
    {
        $month = $this->request->string('month', date('Y-m'));
        $month = preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month : date('Y-m');

        return $this->render('activities/calendar', [
            'pageTitle' => 'Activity calendar',
            'month'     => $month,
            'label'     => date('F Y', strtotime($month . '-01')),
            'previous'  => date('Y-m', strtotime($month . '-01 -1 month')),
            'next'      => date('Y-m', strtotime($month . '-01 +1 month')),
        ]);
    }

    /**
     * Events for the calendar, in the shape the calendar script expects.
     */
    public function calendarData(): Response
    {
        $month = $this->request->string('month', date('Y-m'));
        $month = preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month : date('Y-m');

        $values = [];
        $scope  = $this->scopeWhere($values);

        $rows = $this->db->select(
            'SELECT a.activity_code, a.activity_type, a.status, a.priority, a.planned_date, a.due_date,
                    a.completed_at, ' . Lookups::activityNameSql('a') . ' AS activity_name,
                    f.full_name, g.garden_code
             FROM crop_activities a
             INNER JOIN farmers f ON f.id = a.farmer_id
             LEFT JOIN gardens g ON g.id = a.garden_id
             WHERE a.status <> \'cancelled\'
               AND a.due_date >= :from AND a.planned_date <= :to'
            . $scope . '
             ORDER BY a.due_date ASC LIMIT 500',
            $values + ['from' => $month . '-01', 'to' => date('Y-m-t', strtotime($month . '-01'))]
        );

        $events = array_map(
            static fn (array $row): array => [
                'id'       => $row['activity_code'],
                'title'    => (string) $row['activity_name'],
                'start'    => (string) $row['due_date'],
                'planned'  => (string) $row['planned_date'],
                'status'   => (string) $row['status'],
                'priority' => (string) $row['priority'],
                'farmer'   => (string) $row['full_name'],
                'garden'   => (string) ($row['garden_code'] ?? ''),
                'url'      => url('activities/' . $row['activity_code']),
                'class'    => 'cahu-cal__event--' . $row['status'],
            ],
            $rows
        );

        return $this->json(['events' => $events]);
    }

    public function create(): Response
    {
        $plantingId = $this->request->int('planting_id');

        $record = null;

        if ($plantingId > 0) {
            $planting = $this->db->selectOne(
                'SELECT p.id, p.farmer_id, p.garden_id, p.crop_id, p.variety_id, p.season_id, p.planting_code
                 FROM plantings p WHERE p.id = :id',
                ['id' => $plantingId]
            );

            if ($planting !== null) {
                $record = [
                    'planting_id' => $planting['id'],
                    'farmer_id'   => $planting['farmer_id'],
                    'garden_id'   => $planting['garden_id'],
                    'crop_id'     => $planting['crop_id'],
                    'variety_id'  => $planting['variety_id'],
                    'season_id'   => $planting['season_id'],
                ];
            }
        }

        return $this->render('activities/form', [
            'pageTitle' => 'Add activity',
            'record'    => $record,
            'sections'  => $this->sections(),
            'types'     => $this->lookups->activityTypes(),
        ]);
    }

    public function store(): Response
    {
        $this->assertCsrf();

        $input   = $this->request->all();
        $fields  = array_merge(...array_column($this->sections(), 'fields'));

        $validator = Form::validate($fields, $input);
        $validator->date('planned_date', 'Planned date', true);
        $validator->dateAfter('due_date', 'planned_date', 'Due date', 'planned date');

        if ($validator->fails()) {
            return $this->backWithErrors(url('activities/create'), $validator->errors(), $input);
        }

        $garden = $this->db->selectOne(
            'SELECT farmer_id FROM gardens WHERE id = :id',
            ['id' => $input['garden_id'] ?? 0]
        );

        if ($garden === null) {
            return $this->backWithErrors(
                url('activities/create'),
                ['garden_id' => 'Select a garden.'],
                $input
            );
        }

        $values = Form::extract($fields, $input, ['variety_id', 'planting_id', 'officer_id', 'description']);

        // The farmer is never taken from the form: it is a property of the plot.
        $values['farmer_id'] = (int) $garden['farmer_id'];
        $values['status']    = $this->initialStatus((string) $values['due_date']);

        $this->db->transaction(function () use ($values): void {
            $values['activity_code'] = Identifier::generate('activity');

            $id = $this->db->insert('crop_activities', $values);

            Audit::log('create', 'activities', 'crop_activity', $id, $values['activity_code'], null, $values);

            $this->notifier->notify([
                'type'        => 'activity_assigned',
                'title'       => 'New activity assigned',
                'message'     => sprintf(
                    '%s is planned for %s.',
                    ucwords(str_replace('_', ' ', (string) $values['activity_type'])),
                    format_date((string) $values['due_date'])
                ),
                'farmer_id'   => $values['farmer_id'],
                'officer_id'  => $values['officer_id'] ?? null,
                'link'        => url('activities/' . $values['activity_code']),
                'entity_type' => 'crop_activity',
                'entity_id'   => $id,
                'dedupe_key'  => 'activity_assigned:' . $values['activity_code'],
            ]);
        });

        Flash::success('Activity ' . $values['activity_code'] . ' added.');

        return $this->redirect(url('activities/' . $values['activity_code']));
    }

    public function show(string $code): Response
    {
        $activity = $this->findByCodeOrFail($code);
        $this->assertScope($activity);

        return $this->render('activities/show', [
            'pageTitle' => $activity['activity_name'] ?? ucwords(str_replace('_', ' ', (string) $activity['activity_type'])),
            'activity'  => $activity,
            'related'   => $this->relatedRecords($activity),
        ]);
    }

    public function edit(string $code): Response
    {
        $activity = $this->findByCodeOrFail($code);
        $this->assertScope($activity);

        return $this->render('activities/form', [
            'pageTitle' => 'Edit ' . $activity['activity_code'],
            'record'    => $activity,
            'sections'  => $this->sections(),
            'types'     => $this->lookups->activityTypes(),
        ]);
    }

    public function update(string $code): Response
    {
        $this->assertCsrf();

        $activity = $this->findByCodeOrFail($code);
        $this->assertScope($activity);

        $input   = $this->request->all();
        $fields  = array_merge(...array_column($this->sections(), 'fields'));

        $validator = Form::validate($fields, $input);
        $validator->date('planned_date', 'Planned date', true);
        $validator->dateAfter('due_date', 'planned_date', 'Due date', 'planned date');

        if ($validator->fails()) {
            return $this->backWithErrors(url('activities/' . $code . '/edit'), $validator->errors(), $input);
        }

        $values  = Form::extract($fields, $input, ['variety_id', 'planting_id', 'officer_id', 'description']);
        $before  = $activity;

        $garden  = $this->db->selectOne('SELECT farmer_id FROM gardens WHERE id = :id', ['id' => $values['garden_id'] ?? 0]);

        if ($garden === null) {
            return $this->backWithErrors(url('activities/' . $code . '/edit'), ['garden_id' => 'Select a garden.'], $input);
        }

        $values['farmer_id'] = (int) $garden['farmer_id'];

        // Status is derived, not typed: pushing the date out must put the task
        // back in the queue, while a completed task stays completed.
        if ($activity['status'] !== 'completed') {
            $values['status'] = $this->initialStatus((string) $values['due_date']);
        }

        $this->db->update('crop_activities', $values, 'id = :id', ['id' => $activity['id']]);

        Audit::log('update', 'activities', 'crop_activity', (int) $activity['id'], $code, $before, $values);

        Flash::success('Activity updated.');

        return $this->redirect(url('activities/' . $code));
    }

    /**
     * Records that the work was done.
     *
     * `activities.view` is enough to complete a task because a farmer completing
     * their own activity is the normal case; the scope check below is what
     * stops them completing someone else's.
     */
    public function complete(string $code): Response
    {
        $this->assertCsrf();

        $activity = $this->findByCodeOrFail($code);
        $this->assertScope($activity);

        if ($activity['status'] === 'completed') {
            Flash::info('That activity was already completed on ' . format_datetime((string) $activity['completed_at']) . '.');

            return $this->redirect(url('activities/' . $code));
        }

        $date = $this->request->date('completion_date') ?? date('Y-m-d');

        if ($date > date('Y-m-d')) {
            Flash::error('A completion date cannot be in the future.');

            return $this->redirect(url('activities/' . $code));
        }

        if ($date < (string) $activity['planned_date']) {
            Flash::error('The completion date is before the activity was planned. Check the date.');

            return $this->redirect(url('activities/' . $code));
        }

        $remarks = $this->request->string('completion_remarks');

        $update = [
            'status'             => 'completed',
            'completed_at'       => $date . ' 00:00:00',
            'completed_by'       => $this->auth->id(),
            'completion_remarks' => $remarks !== '' ? $remarks : null,
        ];

        $evidence = $this->storeEvidence($activity);

        if ($evidence !== null) {
            $update['evidence'] = $evidence;
        }

        // Where the work was done matters as evidence, so completion is written
        // twice: the activity row carries the schedule state, and the dedicated
        // completions row keeps the geotag, photo and remark that cannot change
        // once a batch is traced back here.
        $this->db->transaction(function () use ($activity, $update, $date, $remarks, $evidence): void {
            $this->db->update('crop_activities', $update, 'id = :id', ['id' => $activity['id']]);

            $this->db->insert('activity_completions', [
                'activity_id' => $activity['id'],
                'completed_by' => $this->auth->id(),
                'completed_at' => $date . ' 00:00:00',
                'latitude'     => $this->request->float('latitude') ?: null,
                'longitude'    => $this->request->float('longitude') ?: null,
                'remarks'      => $remarks !== '' ? $remarks : null,
                'photo'        => $evidence,
            ]);

            Audit::log(
                'complete',
                'activities',
                'crop_activity',
                (int) $activity['id'],
                (string) $activity['activity_code'],
                $activity,
                $update
            );
        });

        // A completed weeding or spraying task usually changes the crop's growth
        // stage, which is what the planting record shows.
        $this->syncGrowthStage($activity);

        Flash::success('Activity marked complete.');

        return $this->redirect(url('activities/' . $code));
    }

    /**
     * Cancels a task that no longer applies. The row is kept so the schedule
     * still shows that something was called off and why.
     */
    public function cancel(string $code): Response
    {
        $this->assertCsrf();

        $activity = $this->findByCodeOrFail($code);
        $this->assertScope($activity);

        if ($activity['status'] === 'completed') {
            Flash::error('A completed activity cannot be cancelled, because it forms part of the farm history.');

            return $this->redirect(url('activities/' . $code));
        }

        $reason = $this->request->string('completion_remarks') ?: 'Cancelled by ' . ($this->auth->user()['full_name'] ?? 'a user') . '.';

        $this->db->update(
            'crop_activities',
            ['status' => 'cancelled', 'completion_remarks' => $reason],
            'id = :id',
            ['id' => $activity['id']]
        );

        Audit::log('cancel', 'activities', 'crop_activity', (int) $activity['id'], $code, $activity, ['reason' => $reason]);

        Flash::success('Activity cancelled.');

        return $this->redirect(url('activities/' . $code));
    }

    public function destroy(string $code): Response
    {
        $this->assertCsrf();

        $activity = $this->findByCodeOrFail($code);
        $this->assertScope($activity);

        if ($activity['status'] === 'completed') {
            Flash::error(
                'A completed activity cannot be deleted. Cancel it instead so the history still explains what happened.'
            );

            return $this->redirect(url('activities/' . $code));
        }

        $this->db->delete('crop_activities', 'id = :id', ['id' => $activity['id']]);

        Audit::log('delete', 'activities', 'crop_activity', (int) $activity['id'], $code, $activity);

        Flash::success('Activity deleted.');

        return $this->redirect(url('activities'));
    }

    /**
     * Counts per status for the tiles above the register.
     *
     * @return array<string,int>
     */
    private function summary(): array
    {
        $values = [];
        $scope  = $this->scopeWhere($values);

        $rows = $this->db->select(
            // The scope clause is written as " AND ...", so it needs a WHERE of
            // its own here. Appending it to the JOIN condition would happen to
            // work for an INNER JOIN, but would silently change meaning if the
            // join type ever changed.
            'SELECT a.status, COUNT(*) AS total FROM crop_activities a
             INNER JOIN farmers f ON f.id = a.farmer_id
             WHERE 1 = 1' . $scope . '
             GROUP BY a.status',
            $values
        );

        $summary = ['pending' => 0, 'due' => 0, 'overdue' => 0, 'completed' => 0, 'cancelled' => 0];

        foreach ($rows as $row) {
            $summary[(string) $row['status']] = (int) $row['total'];
        }

        // "Overdue" is a date, not a status: a task stays 'due' until something
        // sweeps it, so it is counted separately here.
        $summary['overdue'] = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM crop_activities a
             INNER JOIN farmers f ON f.id = a.farmer_id
             WHERE a.status IN (\'pending\', \'due\') AND a.due_date < CURDATE()' . $scope,
            $values
        );

        return $summary;
    }

    /**
     * Spraying, inspection, input and harvest records that came out of this
     * task, so the activity page can show what the work produced.
     *
     * Only input_usage carries an activity_id; the other records hang off the
     * planting the task belongs to, which is what makes the whole chain
     * reconstructable later.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function relatedRecords(array $activity): array
    {
        $plantingId = $activity['planting_id'];

        $related = [
            'usage' => $this->db->select(
                'SELECT u.usage_code, u.application_date, u.quantity_used, u.unit, u.purpose, i.name AS input_name
                 FROM input_usage u
                 INNER JOIN inputs i ON i.id = u.input_id
                 WHERE u.activity_id = :id ORDER BY u.application_date DESC',
                ['id' => $activity['id']]
            ),
        ];

        if ($plantingId === null) {
            return $related + ['sprays' => [], 'inspections' => [], 'harvests' => []];
        }

        $related['sprays'] = $this->db->select(
            'SELECT spray_code, application_date, product_name, quantity_used, unit,
                    active_ingredient, pest_disease_targeted
             FROM spraying_records WHERE planting_id = :id ORDER BY application_date DESC',
            ['id' => $plantingId]
        );

        $related['inspections'] = $this->db->select(
            'SELECT inspection_code, inspection_date, crop_condition, pest_status, disease_status, recommendations
             FROM inspections WHERE planting_id = :id ORDER BY inspection_date DESC',
            ['id' => $plantingId]
        );

        $related['harvests'] = $this->db->select(
            'SELECT harvest_code, harvest_date, quantity_harvested, unit, quality, grade
             FROM harvest_records WHERE planting_id = :id ORDER BY harvest_date DESC',
            ['id' => $plantingId]
        );

        return $related;
    }

    /**
     * Keeps the planting's growth stage in step with the work just completed.
     */
    private function syncGrowthStage(array $activity): void
    {
        if ($activity['planting_id'] === null) {
            return;
        }

        $stage = match ((string) $activity['activity_type']) {
            'first_weeding', 'weeding', 'second_weeding' => 'vegetative',
            'top dressing', 'fertilizer_application', 'fertilisation' => 'vegetative',
            'flowering_check' => 'flowering',
            'pest_control', 'spraying' => 'fruiting',
            'pre_harvest_check' => 'maturity',
            default => null,
        };

        if ($stage === null) {
            return;
        }

        $this->db->update('plantings', ['growth_stage' => $stage], 'id = :id', ['id' => $activity['planting_id']]);
    }

    private function storeEvidence(array $activity): ?string
    {
        if (!isset($_FILES['evidence'])
            || ($_FILES['evidence']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $upload = (new \Core\Uploader(
            (string) $this->config('uploads.path'),
            (string) $this->config('uploads.url')
        ))->image('evidence', 'activities', (int) $this->config('uploads.max_image_kb', 4096));

        if ($upload['success'] !== true) {
            Flash::warning('The photo could not be saved, but the activity was still marked complete.');

            return null;
        }

        return (string) $upload['path'];
    }

    /**
     * A task is due once its window has opened and overdue once the due date has
     * passed, so the register does not need a sweep job to stay truthful.
     */
    private function initialStatus(string $dueDate): string
    {
        $today = date('Y-m-d');

        if ($dueDate < $today) {
            return 'overdue';
        }

        return $dueDate === $today ? 'due' : 'pending';
    }

    /**
     * Restricts the register and the calendar to the signed-in user's portfolio.
     *
     * @param array<string,mixed> $values
     */
    private function scopeWhere(array &$values): string
    {
        $farmerId  = $this->auth->farmerId();
        $officerId = $this->auth->officerId();

        if ($farmerId !== null) {
            $values['scope_farmer'] = $farmerId;

            return ' AND a.farmer_id = :scope_farmer';
        }

        if ($officerId !== null) {
            $values['scope_officer'] = $officerId;

            return ' AND a.farmer_id IN (SELECT id FROM farmers WHERE officer_id = :scope_officer)';
        }

        return '';
    }

    /**
     * Throws unless the signed-in user is allowed to touch this activity.
     */
    private function assertScope(array $activity): void
    {
        $farmerId  = $this->auth->farmerId();
        $officerId = $this->auth->officerId();

        if ($farmerId !== null && (int) $activity['farmer_id'] !== $farmerId) {
            throw new HttpException(404, 'That activity could not be found.');
        }

        if ($officerId !== null && (int) $activity['officer_id'] !== $officerId) {
            $assigned = $this->db->scalar(
                'SELECT 1 FROM farmers WHERE id = :id AND officer_id = :officer LIMIT 1',
                ['id' => $activity['farmer_id'], 'officer' => $officerId]
            );

            if ($assigned === null) {
                throw new HttpException(404, 'That activity could not be found.');
            }
        }
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'title'  => 'Activity',
                'fields' => [
                    ['name' => 'activity_code', 'label' => 'Activity ID', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued automatically.'],
                    ['name' => 'activity_type', 'label' => 'Activity type', 'type' => Form::SELECT, 'required' => true,
                     'colspan' => 6, 'options' => $this->activityTypeOptions(),
                     'help' => 'Generated tasks use the code from the crop lifecycle template.'],
                    ['name' => 'priority', 'label' => 'Priority', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions("'low','normal','high','critical'", '')],
                    ['name' => 'description', 'label' => 'What must be done', 'type' => Form::TEXTAREA, 'colspan' => 12,
                     'help' => 'Written in plain language so a farmer reading it on a phone knows what to do.'],
                ],
            ],
            [
                'title'  => 'Where and when',
                'fields' => [
                    ['name' => 'planting_id', 'label' => 'Planting', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->plantingOptions()],
                    ['name' => 'garden_id', 'label' => 'Garden', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => $this->lookups->gardenOptions(), 'table' => 'gardens'],
                    ['name' => 'crop_id', 'label' => 'Crop', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->cropOptions('Not specified')],
                    ['name' => 'variety_id', 'label' => 'Variety', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->varietyOptions('Not specified')],
                    ['name' => 'season_id', 'label' => 'Season', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => $this->lookups->seasonOptions()],
                    ['name' => 'planned_date', 'label' => 'Planned date', 'type' => Form::DATE, 'required' => true,
                     'colspan' => 6, 'default' => date('Y-m-d')],
                    ['name' => 'due_date', 'label' => 'Due date', 'type' => Form::DATE, 'required' => true,
                     'colspan' => 6, 'default' => date('Y-m-d')],
                    ['name' => 'assignee_type', 'label' => 'Assigned to', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions("'farmer','officer','both'", '')],
                    ['name' => 'officer_id', 'label' => 'Officer', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->officerOptions(), 'table' => 'field_officers'],
                ],
            ],
        ];
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function activityTypeOptions(): array
    {
        $options = Form::enumOptions(
            "'land_preparation','seed_sowing','first_weeding','weeding','second_weeding','thinning',"
            . "'fertilizer_application','top_dressing','irrigation','pest_control','spraying','pruning',"
            . "'staking','flowering_check','pre_harvest_check','harvest',''",
            'Select activity...'
        );

        foreach ($this->lookups->activityTypes() as $type) {
            $options[] = ['value' => (string) $type['code'], 'label' => (string) $type['name']];
        }

        return $options;
    }

    /**
     * @return array<int,array{value:int,label:string}>
     */
    private function plantingOptions(): array
    {
        $rows = $this->db->select(
            'SELECT p.id, p.planting_code, c.name AS crop_name, f.full_name
             FROM plantings p
             INNER JOIN crops c ON c.id = p.crop_id
             INNER JOIN farmers f ON f.id = p.farmer_id
             WHERE p.status <> \'terminated\'
             ORDER BY p.planting_date DESC LIMIT 300'
        );

        return array_map(
            static fn (array $row): array => [
                'value' => (int) $row['id'],
                'label' => sprintf('%s — %s (%s)', $row['crop_name'], $row['full_name'], $row['planting_code']),
            ],
            $rows
        );
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }

    private function findByCodeOrFail(string $code): array
    {
        $activity = $this->db->selectOne(
            'SELECT a.*, ' . Lookups::activityNameSql('a') . ' AS activity_name,
                    f.full_name, f.farmer_code, f.phone, f.officer_id AS farmer_officer_id,
                    c.name AS crop_name, v.name AS variety_name,
                    g.garden_code, g.name AS garden_name,
                    p.planting_code, o.full_name AS officer_name, u.full_name AS completed_by_name
             FROM crop_activities a
             INNER JOIN farmers f ON f.id = a.farmer_id
             INNER JOIN gardens g ON g.id = a.garden_id
             LEFT JOIN crops c ON c.id = a.crop_id
             LEFT JOIN crop_varieties v ON v.id = a.variety_id
             LEFT JOIN plantings p ON p.id = a.planting_id
             LEFT JOIN field_officers o ON o.id = a.officer_id
             LEFT JOIN users u ON u.id = a.completed_by
             WHERE a.activity_code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($activity === null) {
            throw new HttpException(404, 'No activity found with the ID ' . $code . '.');
        }

        return $activity;
    }
}