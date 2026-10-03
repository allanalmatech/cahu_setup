<?php
declare(strict_types=1);

namespace Http\Controllers\Api\V1;

use Core\Audit;
use Core\Database;
use Core\Identifier;
use Core\Response;
use Services\Forecaster;

/**
 * Offline synchronisation.
 *
 * A field phone queues work while it has no signal and replays it here. Two
 * properties make that safe:
 *
 *  1. Every queued item carries a client-generated `client_uuid`, and every
 *     table it can write to has a UNIQUE index on that column. A replayed item
 *     finds the row the first attempt created and returns it instead of
 *     inserting a duplicate — so no farmer, garden or inspection is ever
 *     double-counted.
 *  2. Each item is applied in its own transaction. One malformed action fails
 *     on its own without discarding the rest of the queue.
 *
 * `pull` returns the delta since the device last synced, so a handset that has
 * been offline for a week catches up in one round trip.
 */
final class SyncApiController extends ApiController
{
    /**
     * POST /api/v1/sync/push
     *
     * Takes `{"queue": [ ... ]}`. Each entry is
     * `{"id": "<client uuid>", "type": "...", "data": { ... }}`, and the `id`
     * is echoed back on that entry's result so the device can tell which of its
     * queued records landed. `data.client_uuid` is the de-duplication key for
     * the tables that carry one; `id` is only the correlation handle.
     *
     * Three statuses come back per item:
     *
     *  - `applied`  the record exists on the server now
     *  - `skipped`  nothing to do: already synchronised, already completed, or
     *               the activity was cancelled. Not an error.
     *  - `failed`   the record was refused, with the reason in `message`.
     *
     * A refusal is reported as `failed` and never as `skipped`, because a
     * client that treats "skipped" as success drops the record: a garden whose
     * parent farmer was not found is a field officer's work that has to
     * survive the trip back to the office, not vanish into a 200 response.
     */
    public function push(): Response
    {
        $payload = $this->request->input('queue');

        if ($payload === null) {
            $payload = $this->request->json()['queue'] ?? null;
        }

        if (!is_array($payload)) {
            return $this->fail('Expected a "queue" array of pending actions.', 400);
        }

        if (count($payload) > 200) {
            return $this->fail('Send at most 200 queued actions per request.', 413);
        }

        $results  = [];
        $applied  = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ($payload as $item) {
            if (!is_array($item)) {
                $failed++;
                $results[] = [
                    'id'      => null,
                    'type'    => null,
                    'status'  => 'failed',
                    'message' => 'Malformed queue item.',
                    'error'   => 'Malformed queue item.',
                ];

                continue;
            }

            $id   = (string) ($item['id'] ?? '');
            $type = (string) ($item['type'] ?? '');
            $data = is_array($item['data'] ?? null) ? $item['data'] : [];

            try {
                $result = $this->db->transaction(function () use ($type, $data): array {
                    return match ($type) {
                        'farmer'             => $this->applyFarmer($data),
                        'garden'             => $this->applyGarden($data),
                        'activity_completion'=> $this->applyActivityCompletion($data),
                        'inspection'         => $this->applyInspection($data),
                        'report_problem'     => $this->applyProblem($data),
                        'spraying'           => $this->applySpraying($data),
                        'harvest'            => $this->applyHarvest($data),
                        default              => ['status' => 'failed', 'reason' => 'Unknown action type.'],
                    };
                });

                $status = (string) ($result['status'] ?? 'skipped');

                // Counted by status rather than by `applied vs anything else`,
                // because a rejected item is not a success. A batch where three
                // records were refused and nine applied has to say so, or the
                // client sees a 200 and concludes everything landed.
                match ($status) {
                    'applied' => $applied++,
                    'failed'  => $failed++,
                    default   => $skipped++,
                };

                $entry = ['id' => $id, 'type' => $type, 'status' => $status] + $result;

                if ($status === 'failed') {
                    // The reason lives under 'reason' for a rejected action and
                    // under 'error' for an exception; both are reported as
                    // 'message' so the client has one field to read.
                    $entry['message'] = (string) ($result['reason'] ?? $result['error'] ?? 'The action could not be applied.');
                }

                $results[] = $entry;
            } catch (\Throwable $exception) {
                $failed++;
                $results[] = [
                    'id'     => $id,
                    'type'   => $type,
                    'status' => 'failed',
                    'error'  => $exception->getMessage(),
                ];
            }
        }

        return $this->ok([
            'message'   => 'Sync push complete.',
            'processed' => count($payload),
            'applied'   => $applied,
            'skipped'   => $skipped,
            'failed'    => $failed,
            'results'   => $results,
        ]);
    }

    /**
     * GET /api/v1/sync/pull
     *
     * Returns everything changed after `since`, so the device can replace its
     * local cache without downloading the whole register.
     */
    public function pull(): Response
    {
        $since = $this->request->date('since');

        if ($since === null) {
            $since = $this->request->string('updated_at');
            $since = $since === '' ? null : date('Y-m-d', strtotime($since) ?: time());
        }

        if ($since === null) {
            return $this->fail('Provide a "since" date (YYYY-MM-DD) to pull changes.', 400);
        }

        $values = ['since' => $since];
        $scope  = $this->scopeWhere($values, 'f');

        $farmers = $this->db->select(
            'SELECT f.id, f.farmer_code, f.full_name, f.phone, f.status, f.village_name,
                    f.latitude, f.longitude, f.updated_at,
                    (SELECT COUNT(*) FROM gardens g
                      WHERE g.farmer_id = f.id AND g.deleted_at IS NULL) AS garden_count
             FROM farmers f
             WHERE f.updated_at >= :since' . $scope . '
             ORDER BY f.updated_at ASC LIMIT 500',
            $values
        );

        $gardenValues = ['since' => $since];
        $gardenScope  = $this->scopeWhere($gardenValues, 'g');

        $gardens = $this->db->select(
            'SELECT g.id, g.garden_code, g.name, g.acreage, g.status, g.mapped,
                    g.latitude, g.longitude, g.village_name, g.updated_at,
                    f.farmer_code
             FROM gardens g
             INNER JOIN farmers f ON f.id = g.farmer_id
             WHERE g.deleted_at IS NULL AND g.updated_at >= :since' . $gardenScope . '
             ORDER BY g.updated_at ASC LIMIT 500',
            $gardenValues
        );

        $activityValues = ['since' => $since];
        $activityScope  = $this->scopeWhere($activityValues, 'a');

        $activities = $this->db->select(
            'SELECT a.id, a.activity_code, a.activity_type, a.planned_date, a.due_date,
                    a.status, a.priority, a.completed_at, a.client_uuid, a.updated_at,
                    g.garden_code, c.name AS crop_name
             FROM crop_activities a
             INNER JOIN gardens g ON g.id = a.garden_id
             INNER JOIN crops c ON c.id = a.crop_id
             WHERE a.updated_at >= :since' . $activityScope . '
             ORDER BY a.updated_at ASC LIMIT 500',
            $activityValues
        );

        return $this->ok([
            'since' => $since,
            'now'   => date('Y-m-d H:i:s'),
            'data'  => [
                'farmers'    => $farmers,
                'gardens'    => $gardens,
                'activities' => $activities,
            ],
            'meta' => [
                'farmers'    => count($farmers),
                'gardens'    => count($gardens),
                'activities' => count($activities),
                'truncated'  => count($farmers) >= 500 || count($gardens) >= 500 || count($activities) >= 500,
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function applyFarmer(array $data): array
    {
        $uuid = $this->queuedUuid($data);

        if ($uuid !== null) {
            $existing = $this->db->selectOne(
                'SELECT id, farmer_code FROM farmers WHERE client_uuid = :uuid LIMIT 1',
                ['uuid' => $uuid]
            );

            if ($existing !== null) {
                return ['status' => 'skipped', 'reason' => 'Already synchronised.', 'id' => (int) $existing['id'], 'farmer_code' => $existing['farmer_code']];
            }
        }

        $name = trim((string) ($data['full_name'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));

        if ($name === '' || $phone === '') {
            return ['status' => 'failed', 'reason' => 'A full name and phone number are required.'];
        }

        $code = Identifier::generate('farmer');

        $id = $this->db->insert('farmers', [
            'farmer_code'       => $code,
            'full_name'         => $name,
            'phone'             => $phone,
            'alt_phone'         => $this->text($data, 'alt_phone'),
            'gender'            => $this->text($data, 'gender'),
            'national_id'       => $this->text($data, 'national_id'),
            'village_name'      => $this->text($data, 'village_name'),
            'latitude'          => $this->coordinate($data, 'latitude'),
            'longitude'         => $this->coordinate($data, 'longitude'),
            'farmer_type'       => $this->text($data, 'farmer_type') ?? 'smallholder',
            'group_id'          => $this->int($data, 'group_id'),
            'officer_id'        => $this->auth->officerId(),
            'registration_date' => $this->date($data, 'registration_date') ?? date('Y-m-d'),
            'status'            => 'active',
            'created_by'        => $this->auth->id(),
            'client_uuid'       => $uuid,
        ]);

        Audit::log('sync_create', 'farmers', 'farmer', $id, $code, null, ['source' => 'offline']);

        return ['status' => 'applied', 'id' => $id, 'farmer_code' => $code];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function applyGarden(array $data): array
    {
        $uuid = $this->queuedUuid($data);

        if ($uuid !== null) {
            $existing = $this->db->selectOne(
                'SELECT id, garden_code FROM gardens WHERE client_uuid = :uuid LIMIT 1',
                ['uuid' => $uuid]
            );

            if ($existing !== null) {
                return ['status' => 'skipped', 'reason' => 'Already synchronised.', 'id' => (int) $existing['id'], 'garden_code' => $existing['garden_code']];
            }
        }

        $farmerCode = trim((string) ($data['farmer_code'] ?? ''));
        $farmer     = $farmerCode === '' ? null : $this->db->selectOne(
            'SELECT id FROM farmers WHERE farmer_code = :code AND deleted_at IS NULL LIMIT 1',
            ['code' => $farmerCode]
        );

        if ($farmer === null) {
            return ['status' => 'failed', 'reason' => 'The parent farmer could not be found.'];
        }

        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            return ['status' => 'failed', 'reason' => 'A garden name is required.'];
        }

        $code = Identifier::generate('garden');

        $id = $this->db->insert('gardens', [
            'garden_code'   => $code,
            'farmer_id'     => (int) $farmer['id'],
            'name'          => $name,
            'acreage'       => isset($data['acreage']) && is_numeric($data['acreage']) ? (float) $data['acreage'] : 0,
            'latitude'      => $this->coordinate($data, 'latitude'),
            'longitude'     => $this->coordinate($data, 'longitude'),
            'village_name'  => $this->text($data, 'village_name'),
            'land_ownership'=> $this->text($data, 'land_ownership') ?? 'owned',
            'soil_type'     => $this->text($data, 'soil_type'),
            'irrigation_status' => $this->text($data, 'irrigation_status') ?? 'rainfed',
            'terrain'       => $this->text($data, 'terrain'),
            'status'        => 'active',
            'created_by'    => $this->auth->id(),
            'client_uuid'   => $uuid,
        ]);

        Audit::log('sync_create', 'gardens', 'garden', $id, $code, null, ['source' => 'offline']);

        return ['status' => 'applied', 'id' => $id, 'garden_code' => $code];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function applyActivityCompletion(array $data): array
    {
        $code = trim((string) ($data['activity_code'] ?? ''));

        if ($code === '') {
            return ['status' => 'failed', 'reason' => 'An activity code is required.'];
        }

        $activity = $this->db->selectOne(
            'SELECT id, status FROM crop_activities WHERE activity_code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($activity === null) {
            return ['status' => 'failed', 'reason' => 'No activity found with that code.'];
        }

        if ($activity['status'] === 'completed') {
            return ['status' => 'skipped', 'reason' => 'Already completed.'];
        }

        if ($activity['status'] === 'cancelled') {
            return ['status' => 'skipped', 'reason' => 'The activity has been cancelled.'];
        }

        // The completion timestamp may be the offline capture time, which is
        // earlier than now. completed_at is DATETIME; report_date-style DATE
        // columns do not exist on this table.
        $completedAt = $this->date($data, 'completed_at') ?? date('Y-m-d');
        $completedAt .= ' ' . ($this->text($data, 'completed_time') ?? date('H:i:s'));

        $latitude  = $this->coordinate($data, 'latitude');
        $longitude = $this->coordinate($data, 'longitude');

        $this->db->update('crop_activities', [
            'status'             => 'completed',
            'completed_at'       => $completedAt,
            'completed_by'       => $this->auth->id(),
            'completion_remarks' => $this->text($data, 'remarks'),
        ], 'id = :id', ['id' => $activity['id']]);

        $this->db->insert('activity_completions', [
            'activity_id'  => $activity['id'],
            'completed_by' => $this->auth->id(),
            'completed_at' => $completedAt,
            'latitude'     => $latitude,
            'longitude'    => $longitude,
            'remarks'      => $this->text($data, 'remarks'),
        ]);

        return ['status' => 'applied', 'id' => (int) $activity['id'], 'activity_code' => $code];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function applyInspection(array $data): array
    {
        $uuid = $this->queuedUuid($data);

        if ($uuid !== null) {
            $existing = $this->db->selectOne(
                'SELECT id, inspection_code FROM inspections WHERE client_uuid = :uuid LIMIT 1',
                ['uuid' => $uuid]
            );

            if ($existing !== null) {
                return ['status' => 'skipped', 'reason' => 'Already synchronised.', 'id' => (int) $existing['id'], 'inspection_code' => $existing['inspection_code']];
            }
        }

        $garden = $this->resolveGarden($data);

        if ($garden === null) {
            return ['status' => 'failed', 'reason' => 'No garden found for that code.'];
        }

        $inspectionDate = $this->date($data, 'inspected_at') ?? $this->date($data, 'inspection_date') ?? date('Y-m-d');
        $plantingId     = $this->int($data, 'planting_id');

        $planting = $plantingId !== null ? $this->db->selectOne(
            'SELECT id, crop_id, season_id, variety_id FROM plantings WHERE id = :id LIMIT 1',
            ['id' => $plantingId]
        ) : null;

        $code = Identifier::generate('inspection', date_create_from_format('Y-m-d', $inspectionDate) ?: null);

        $id = $this->db->insert('inspections', [
            'inspection_code'          => $code,
            'farmer_id'                => (int) $garden['farmer_id'],
            'garden_id'                => (int) $garden['id'],
            'planting_id'              => $planting['id'] ?? null,
            'crop_id'                  => $planting['crop_id'] ?? $this->int($data, 'crop_id'),
            'season_id'                => $planting['season_id'] ?? $this->int($data, 'season_id'),
            'officer_id'               => $this->auth->officerId(),
            'inspection_date'          => $inspectionDate,
            'latitude'                 => $this->coordinate($data, 'latitude'),
            'longitude'                => $this->coordinate($data, 'longitude'),
            'crop_condition'           => $this->text($data, 'crop_condition'),
            'pest_status'              => $this->text($data, 'pest_status'),
            'disease_status'           => $this->text($data, 'disease_status'),
            'weed_status'              => $this->text($data, 'weed_status'),
            'growth_stage'             => $this->text($data, 'growth_stage'),
            'estimated_yield_per_acre' => isset($data['estimated_yield_per_acre']) && is_numeric($data['estimated_yield_per_acre'])
                ? (float) $data['estimated_yield_per_acre']
                : null,
            'recommendations'          => $this->text($data, 'recommendations'),
            'remarks'                  => $this->text($data, 'remarks'),
            'status'                   => 'submitted',
            'created_by'               => $this->auth->id(),
            'client_uuid'              => $uuid,
        ]);

        Audit::log('sync_create', 'inspections', 'inspection', $id, $code, null, ['source' => 'offline']);

        $revision = null;

        if ($planting !== null) {
            $revision = (new Forecaster($this->db))->reviseFromInspection((int) $planting['id']);
        }

        return ['status' => 'applied', 'id' => $id, 'inspection_code' => $code, 'forecast_revision' => $revision];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function applyProblem(array $data): array
    {
        $uuid = $this->queuedUuid($data);

        if ($uuid !== null) {
            $existing = $this->db->selectOne(
                'SELECT id, problem_code FROM problem_reports WHERE client_uuid = :uuid LIMIT 1',
                ['uuid' => $uuid]
            );

            if ($existing !== null) {
                return ['status' => 'skipped', 'reason' => 'Already synchronised.', 'id' => (int) $existing['id'], 'problem_code' => $existing['problem_code']];
            }
        }

        $typeId = $this->resolveProblemType($data);

        if ($typeId === null) {
            return ['status' => 'failed', 'reason' => 'The problem type is not recognised.'];
        }

        $description = trim((string) ($data['description'] ?? ''));

        if ($description === '') {
            return ['status' => 'failed', 'reason' => 'A description is required.'];
        }

        $garden  = $this->resolveGarden($data);
        $farmerId = (int) ($garden['farmer_id'] ?? $this->auth->farmerId() ?? 0);

        if ($farmerId === 0) {
            return ['status' => 'failed', 'reason' => 'No farmer could be resolved for this report.'];
        }

        // The column is report_date, not reported_at.
        $reportDate = $this->date($data, 'reported_at') ?? $this->date($data, 'report_date') ?? date('Y-m-d');
        $code = Identifier::generate('problem', date_create_from_format('Y-m-d', $reportDate) ?: null);

        $id = $this->db->insert('problem_reports', [
            'problem_code'       => $code,
            'problem_type_id'    => $typeId,
            'farmer_id'          => $farmerId,
            'garden_id'          => $garden['id'] ?? null,
            'crop_id'            => $this->int($data, 'crop_id'),
            'season_id'          => $this->int($data, 'season_id'),
            'region_id'          => $garden['region_id'] ?? null,
            'district_id'        => $garden['district_id'] ?? null,
            'latitude'           => $this->coordinate($data, 'latitude'),
            'longitude'          => $this->coordinate($data, 'longitude'),
            'report_date'        => $reportDate,
            'severity'           => in_array((string) ($data['severity'] ?? ''), ['low', 'medium', 'high', 'critical'], true)
                ? (string) $data['severity']
                : 'medium',
            'description'        => $description,
            'reported_by'        => $this->auth->id(),
            'reported_by_type'   => $this->auth->officerId() !== null ? 'officer' : 'farmer',
            'status'             => 'reported',
            'client_uuid'        => $uuid,
        ]);

        Audit::log('sync_create', 'problems', 'problem_report', $id, $code, null, ['source' => 'offline']);

        return ['status' => 'applied', 'id' => $id, 'problem_code' => $code];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function applySpraying(array $data): array
    {
        $uuid = $this->queuedUuid($data);

        if ($uuid !== null) {
            $existing = $this->db->selectOne(
                'SELECT id, spray_code FROM spraying_records WHERE client_uuid = :uuid LIMIT 1',
                ['uuid' => $uuid]
            );

            if ($existing !== null) {
                return ['status' => 'skipped', 'reason' => 'Already synchronised.', 'id' => (int) $existing['id'], 'spray_code' => $existing['spray_code']];
            }
        }

        $garden = $this->resolveGarden($data);

        if ($garden === null) {
            return ['status' => 'failed', 'reason' => 'No garden found for that application.'];
        }

        $product = trim((string) ($data['product_name'] ?? ''));

        if ($product === '') {
            return ['status' => 'failed', 'reason' => 'A product name is required.'];
        }

        $applied = $this->date($data, 'application_date') ?? $this->date($data, 'applied_at') ?? date('Y-m-d');
        $code = Identifier::generate('spray', date_create_from_format('Y-m-d', $applied) ?: null);

        $id = $this->db->insert('spraying_records', [
            'spray_code'             => $code,
            'farmer_id'              => (int) $garden['farmer_id'],
            'garden_id'              => (int) $garden['id'],
            'crop_id'                => $this->int($data, 'crop_id'),
            'variety_id'             => $this->int($data, 'variety_id'),
            'season_id'              => $this->int($data, 'season_id'),
            'input_id'               => $this->int($data, 'input_id'),
            'product_name'           => $product,
            'active_ingredient'      => $this->text($data, 'active_ingredient'),
            'quantity_used'          => isset($data['quantity_used']) && is_numeric($data['quantity_used']) ? (float) $data['quantity_used'] : 0,
            'unit'                   => $this->text($data, 'unit') ?? 'L',
            'dosage'                 => $this->text($data, 'dosage'),
            'application_date'       => $applied,
            'next_application_date'  => $this->date($data, 'next_application_date'),
            'pest_disease_targeted'  => $this->text($data, 'pest_disease_targeted'),
            'application_method'     => $this->text($data, 'application_method'),
            'applicator_name'        => $this->text($data, 'applicator_name'),
            'officer_id'             => $this->auth->officerId(),
            'weather_conditions'     => $this->text($data, 'weather_conditions'),
            'remarks'                => $this->text($data, 'remarks'),
            'created_by'             => $this->auth->id(),
            'client_uuid'            => $uuid,
        ]);

        Audit::log('sync_create', 'spraying', 'spraying_record', $id, $code, null, ['source' => 'offline']);

        return ['status' => 'applied', 'id' => $id, 'spray_code' => $code];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function applyHarvest(array $data): array
    {
        $uuid = $this->queuedUuid($data);

        if ($uuid !== null) {
            $existing = $this->db->selectOne(
                'SELECT id, harvest_code FROM harvest_records WHERE client_uuid = :uuid LIMIT 1',
                ['uuid' => $uuid]
            );

            if ($existing !== null) {
                return ['status' => 'skipped', 'reason' => 'Already synchronised.', 'id' => (int) $existing['id'], 'harvest_code' => $existing['harvest_code']];
            }
        }

        $garden = $this->resolveGarden($data);

        if ($garden === null) {
            return ['status' => 'failed', 'reason' => 'No garden found for that harvest.'];
        }

        $quantity = isset($data['quantity_harvested']) && is_numeric($data['quantity_harvested'])
            ? (float) $data['quantity_harvested']
            : 0.0;

        if ($quantity <= 0) {
            return ['status' => 'failed', 'reason' => 'The quantity harvested must be greater than zero.'];
        }

        $plantingId = $this->int($data, 'planting_id');

        $planting = $plantingId !== null ? $this->db->selectOne(
            'SELECT id, crop_id, season_id, variety_id, officer_id FROM plantings WHERE id = :id LIMIT 1',
            ['id' => $plantingId]
        ) : null;

        $cropId = (int) ($planting['crop_id'] ?? $this->int($data, 'crop_id') ?? 0);
        $seasonId = (int) ($planting['season_id'] ?? $this->int($data, 'season_id') ?? 0);

        if ($cropId === 0 || $seasonId === 0) {
            return ['status' => 'failed', 'reason' => 'The harvest could not be tied to a crop and season.'];
        }

        $harvested = $this->date($data, 'harvest_date') ?? date('Y-m-d');
        $code = Identifier::generate('harvest', date_create_from_format('Y-m-d', $harvested) ?: null);

        $id = $this->db->insert('harvest_records', [
            'harvest_code'       => $code,
            'planting_id'        => $planting['id'] ?? null,
            'farmer_id'          => (int) $garden['farmer_id'],
            'garden_id'          => (int) $garden['id'],
            'crop_id'            => $cropId,
            'variety_id'         => $planting['variety_id'] ?? null,
            'season_id'          => $seasonId,
            'harvest_date'       => $harvested,
            'acreage_harvested'  => isset($data['acreage_harvested']) && is_numeric($data['acreage_harvested']) ? (float) $data['acreage_harvested'] : 0,
            'quantity_harvested' => $quantity,
            'unit'               => $this->text($data, 'unit') ?? 'KG',
            'quality'            => $this->text($data, 'quality'),
            'grade'              => $this->text($data, 'grade'),
            'moisture_content'   => isset($data['moisture_content']) && is_numeric($data['moisture_content']) ? (float) $data['moisture_content'] : null,
            'losses'             => isset($data['losses']) && is_numeric($data['losses']) ? (float) $data['losses'] : null,
            'storage_location'   => $this->text($data, 'storage_location'),
            'officer_id'         => $this->auth->officerId() ?? ($planting['officer_id'] ?? null),
            'status'             => 'recorded',
            'notes'              => $this->text($data, 'notes'),
            'created_by'         => $this->auth->id(),
            'client_uuid'        => $uuid,
        ]);

        if ($planting !== null) {
            (new Forecaster($this->db))->recordActual((int) $planting['id']);
        }

        Audit::log('sync_create', 'harvest', 'harvest', $id, $code, null, ['source' => 'offline']);

        return ['status' => 'applied', 'id' => $id, 'harvest_code' => $code];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    private function resolveGarden(array $data): ?array
    {
        $code = trim((string) ($data['garden_code'] ?? ''));

        if ($code !== '') {
            return $this->db->selectOne(
                'SELECT id, farmer_id, region_id, district_id FROM gardens
                 WHERE garden_code = :code AND deleted_at IS NULL LIMIT 1',
                ['code' => $code]
            );
        }

        $id = isset($data['garden_id']) && is_numeric($data['garden_id']) ? (int) $data['garden_id'] : 0;

        if ($id > 0) {
            return $this->db->selectOne(
                'SELECT id, farmer_id, region_id, district_id FROM gardens
                 WHERE id = :id AND deleted_at IS NULL LIMIT 1',
                ['id' => $id]
            );
        }

        return null;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function resolveProblemType(array $data): ?int
    {
        $id = isset($data['problem_type_id']) && is_numeric($data['problem_type_id'])
            ? (int) $data['problem_type_id']
            : 0;

        if ($id > 0) {
            $resolved = Database::instance()->scalar('SELECT id FROM problem_types WHERE id = :id LIMIT 1', ['id' => $id]);

            if ($resolved !== null) {
                return (int) $resolved;
            }
        }

        $code = trim((string) ($data['problem_type'] ?? ''));

        if ($code !== '') {
            $resolved = Database::instance()->scalar('SELECT id FROM problem_types WHERE code = :code LIMIT 1', ['code' => $code]);

            if ($resolved !== null) {
                return (int) $resolved;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function queuedUuid(array $data): ?string
    {
        $uuid = strtolower(trim((string) ($data['client_uuid'] ?? '')));

        if ($uuid === '') {
            return null;
        }

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid) === 1 ? $uuid : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function text(array $data, string $key): ?string
    {
        $value = trim((string) ($data[$key] ?? ''));

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function int(array $data, string $key): ?int
    {
        return isset($data[$key]) && is_numeric($data[$key]) ? (int) $data[$key] : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function date(array $data, string $key): ?string
    {
        $value = trim((string) ($data[$key] ?? ''));

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function coordinate(array $data, string $key): ?float
    {
        if (!isset($data[$key]) || !is_numeric($data[$key])) {
            return null;
        }

        $value = (float) $data[$key];

        return $value === 0.0 ? null : $value;
    }
}
