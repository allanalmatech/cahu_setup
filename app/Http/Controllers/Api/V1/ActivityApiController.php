<?php
declare(strict_types=1);

namespace Http\Controllers\Api\V1;

use Core\Audit;
use Core\Identifier;
use Core\Response;

/**
 * Crop activity / task API.
 *
 * This is the endpoint the farmer app leans on hardest: it lists the work a
 * farmer or officer owes, and lets them complete it with a GPS fix and a photo
 * even when the phone has been offline. Completion is idempotent — a queued
 * action replayed by the sync engine never double-counts.
 */
final class ActivityApiController extends ApiController
{
    /**
     * GET /api/v1/activities
     */
    public function index(): Response
    {
        $params = $this->listParams(50);
        $values = [];
        $scope  = $this->scopeWhere($values, 'a');

        $where = ['1 = 1'];

        $status = $this->request->string('status');

        if ($status !== '') {
            $where[]      = 'a.status = :status';
            $values['status'] = $status;
        }

        // "open" is what the app's task list asks for: everything still owed.
        if ($status === 'open') {
            $where = ["a.status IN ('pending','due','overdue')"];
            unset($values['status']);
        }

        if ($this->request->string('farmer_code') !== '') {
            $where[]      = 'f.farmer_code = :farmer_code';
            $values['farmer_code'] = $this->request->string('farmer_code');
        }

        if ($this->request->int('garden_id') > 0) {
            $where[]    = 'a.garden_id = :garden';
            $values['garden'] = $this->request->int('garden_id');
        }

        if ($this->request->int('season_id') > 0) {
            $where[]    = 'a.season_id = :season';
            $values['season'] = $this->request->int('season_id');
        }

        $whereSql = implode(' AND ', $where) . $scope;

        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM crop_activities a
             INNER JOIN farmers f ON f.id = a.farmer_id
             WHERE ' . $whereSql,
            $values
        );

        $rows = $this->db->select(
            'SELECT a.id, a.activity_code, a.activity_type, a.description,
                    a.planned_date, a.due_date, a.status, a.priority, a.assignee_type,
                    a.completed_at, a.completion_remarks,
                    g.id AS garden_id, g.garden_code, g.name AS garden_name, g.latitude, g.longitude,
                    f.farmer_code, f.full_name AS farmer_name,
                    c.name AS crop_name, v.name AS variety_name, s.season_code,
                    o.officer_code, o.full_name AS officer_name,
                    p.planting_code
             FROM crop_activities a
             INNER JOIN farmers f ON f.id = a.farmer_id
             INNER JOIN gardens g ON g.id = a.garden_id
             INNER JOIN crops c ON c.id = a.crop_id
             LEFT JOIN crop_varieties v ON v.id = a.variety_id
             INNER JOIN seasons s ON s.id = a.season_id
             LEFT JOIN field_officers o ON o.id = a.officer_id
             LEFT JOIN plantings p ON p.id = a.planting_id
             WHERE ' . $whereSql . '
             ORDER BY a.due_date ASC, a.id ASC
             LIMIT ' . $params['per_page'] . ' OFFSET ' . (($params['page'] - 1) * $params['per_page']),
            $values
        );

        return $this->paginate($rows, $total, $params, 'activities');
    }

    /**
     * POST /api/v1/activities
     *
     * Ad-hoc activity creation, for work that is not on the planting's
     * generated schedule (an emergency spray, a spot weeding).
     */
    public function store(): Response
    {
        $existing = $this->findByClientUuid('crop_activities');

        if ($existing !== null) {
            return $this->ok($existing);
        }

        $uuid      = $this->clientUuid();
        $gardenRef = $this->request->string('garden_code');
        $errors    = [];

        $garden = $gardenRef === '' ? null : $this->db->selectOne(
            'SELECT id, farmer_id, name FROM gardens WHERE garden_code = :code AND deleted_at IS NULL LIMIT 1',
            ['code' => $gardenRef]
        );

        if ($gardenRef !== '' && $garden === null) {
            $errors['garden_code'] = 'No garden found with that ID.';
        }

        if ($garden !== null) {
            $this->assertVisible($garden);
        }

        $plantingId = $this->request->int('planting_id');
        $planting   = $plantingId > 0 ? $this->db->selectOne(
            'SELECT id, farmer_id, garden_id, crop_id, variety_id, season_id, officer_id
             FROM plantings WHERE id = :id LIMIT 1',
            ['id' => $plantingId]
        ) : null;

        if ($plantingId > 0 && $planting === null) {
            $errors['planting_id'] = 'No planting found with that ID.';
        }

        if ($planting !== null) {
            $garden = $garden ?? $this->db->selectOne(
                'SELECT id, farmer_id, name FROM gardens WHERE id = :id LIMIT 1',
                ['id' => $planting['garden_id']]
            );
        }

        if ($garden === null) {
            $errors['garden_code'] = 'A garden is required.';
        }

        $activityType = $this->request->string('activity_type');

        if ($activityType === '') {
            $errors['activity_type'] = 'Choose the type of activity.';
        }

        $planned = $this->request->date('planned_date') ?? date('Y-m-d');
        $due     = $this->request->date('due_date') ?? $planned;

        if (strtotime($due) < strtotime($planned)) {
            $errors['due_date'] = 'The due date may not be earlier than the planned date.';
        }

        $cropId = $planting !== null
            ? (int) $planting['crop_id']
            : $this->request->int('crop_id');

        $seasonId = $planting !== null
            ? (int) $planting['season_id']
            : $this->request->int('season_id');

        if ($cropId <= 0) {
            $errors['crop_id'] = 'A crop is required.';
        }

        if ($seasonId <= 0) {
            $errors['season_id'] = 'A season is required.';
        }

        if ($errors !== []) {
            return $this->validationFailed($errors);
        }

        if ($uuid !== null && $this->db->scalar(
            'SELECT 1 FROM crop_activities WHERE client_uuid = :uuid LIMIT 1',
            ['uuid' => $uuid]
        ) !== null) {
            return $this->ok($this->db->selectOne('SELECT * FROM crop_activities WHERE client_uuid = :uuid', ['uuid' => $uuid]));
        }

        $data = [
            'activity_code' => Identifier::generate('activity', date_create_from_format('Y-m-d', $planned) ?: null),
            'planting_id'   => $plantingId > 0 ? $plantingId : null,
            'farmer_id'     => $garden['farmer_id'],
            'garden_id'     => $garden['id'],
            'crop_id'       => $cropId,
            'variety_id'    => $planting !== null ? $planting['variety_id'] : ($this->request->int('variety_id') ?: null),
            'season_id'     => $seasonId,
            'activity_type' => $activityType,
            'description'   => $this->request->string('description') ?: null,
            'planned_date'  => $planned,
            'due_date'      => $due,
            'assignee_type' => $this->request->string('assignee_type', 'farmer'),
            'officer_id'    => $this->auth->officerId(),
            'priority'      => $this->request->string('priority', 'normal'),
            'status'        => 'pending',
            'client_uuid'   => $uuid,
        ];

        $id = $this->db->insert('crop_activities', $data);

        Audit::log('create', 'activities', 'activity', $id, $data['activity_code'], null, $data);

        return $this->created($this->findOrFail($id));
    }

    /**
     * POST /api/v1/activities/{code}/complete
     *
     * Completion is safe to retry: an already-completed activity returns the
     * existing record with `already_completed` set, so the app can clear its
     * queue entry without worrying about a double-counted completion.
     */
    public function complete(string $code): Response
    {
        $activity = $this->db->selectOne(
            'SELECT a.*, g.garden_code FROM crop_activities a
             INNER JOIN gardens g ON g.id = a.garden_id
             WHERE a.activity_code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($activity === null) {
            return $this->notFound('Activity not found.');
        }

        $this->assertVisible($activity);

        if ($activity['status'] === 'completed') {
            return $this->ok($activity + ['already_completed' => true]);
        }

        if ($activity['status'] === 'cancelled') {
            return $this->fail('This activity has been cancelled.', 409);
        }

        $completedAt = $this->request->input('completed_at');
        $completedAt = is_string($completedAt) && strtotime($completedAt) !== false
            ? date('Y-m-d H:i:s', strtotime($completedAt))
            : date('Y-m-d H:i:s');

        $latitude  = $this->request->has('latitude') ? $this->request->float('latitude') : null;
        $longitude = $this->request->has('longitude') ? $this->request->float('longitude') : null;

        $photo = $this->uploadEvidence();

        $this->db->transaction(function () use ($activity, $completedAt, $latitude, $longitude, $photo): void {
            $this->db->update('crop_activities', [
                'status'             => 'completed',
                'completed_at'       => $completedAt,
                'completed_by'       => $this->auth->id(),
                'completion_remarks' => $this->request->string('remarks') ?: null,
            ], 'id = :id', ['id' => $activity['id']]);

            $this->db->insert('activity_completions', [
                'activity_id'  => $activity['id'],
                'completed_by' => $this->auth->id(),
                'completed_at' => $completedAt,
                'latitude'     => $latitude === 0.0 ? null : $latitude,
                'longitude'    => $longitude === 0.0 ? null : $longitude,
                'remarks'      => $this->request->string('remarks') ?: null,
                'photo'        => $photo,
            ]);
        });

        Audit::log(
            'complete',
            'activities',
            'activity',
            (int) $activity['id'],
            (string) $activity['activity_code'],
            ['status' => $activity['status']],
            ['status' => 'completed', 'completed_at' => $completedAt]
        );

        return $this->ok($this->findOrFail((int) $activity['id']));
    }

    private function uploadEvidence(): ?string
    {
        $file = $this->request->file('photo');

        if ($file === null) {
            return null;
        }

        $uploader = new \Core\Uploader(
            (string) $this->config('uploads.path'),
            (string) $this->config('uploads.url')
        );

        $result = $uploader->image('photo', 'activities', (int) $this->config('uploads.max_image_kb', 4096));

        return $result['success'] === true ? $result['path'] : null;
    }

    private function findOrFail(int $id): array
    {
        $row = $this->db->selectOne(
            'SELECT a.*, g.garden_code, g.name AS garden_name, c.name AS crop_name,
                    s.season_code, f.farmer_code
             FROM crop_activities a
             INNER JOIN gardens g ON g.id = a.garden_id
             INNER JOIN crops c ON c.id = a.crop_id
             INNER JOIN seasons s ON s.id = a.season_id
             INNER JOIN farmers f ON f.id = a.farmer_id
             WHERE a.id = :id LIMIT 1',
            ['id' => $id]
        );

        return $row ?? ['id' => $id];
    }
}
