<?php
declare(strict_types=1);

namespace Http\Controllers\Api\V1;

use Core\Audit;
use Core\Identifier;
use Core\Response;
use Services\Forecaster;

/**
 * Inspection API.
 *
 * `problem_reports` and `inspections` are both reachable from a phone that may
 * be offline, so both accept a client_uuid and are safe to retry. Recording an
 * inspection also revises that planting's forecast, which is what turns a field
 * observation into a production number.
 */
final class InspectionApiController extends ApiController
{
    private Forecaster $forecaster;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);

        $this->forecaster = new Forecaster($db);
    }

    /**
     * GET /api/v1/inspections
     */
    public function index(): Response
    {
        $params = $this->listParams(50);
        $values = [];
        $scope  = $this->scopeWhere($values, 'i');

        $where = ['1 = 1'];

        if ($this->request->int('garden_id') > 0) {
            $where[]    = 'i.garden_id = :garden';
            $values['garden'] = $this->request->int('garden_id');
        }

        $from = $this->request->date('from');

        if ($from !== null) {
            $where[]      = 'i.inspection_date >= :from';
            $values['from'] = $from;
        }

        $to = $this->request->date('to');

        if ($to !== null) {
            $where[]    = 'i.inspection_date <= :to';
            $values['to'] = $to;
        }

        $whereSql = implode(' AND ', $where) . $scope;

        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM inspections i WHERE ' . $whereSql,
            $values
        );

        $rows = $this->db->select(
            'SELECT i.id, i.inspection_code, i.inspection_date, i.crop_condition,
                    i.pest_status, i.disease_status, i.weed_status, i.soil_condition,
                    i.weather_condition, i.growth_stage, i.recommendations, i.remarks,
                    i.latitude, i.longitude, i.status,
                    g.garden_code, g.name AS garden_name,
                    f.farmer_code, f.full_name AS farmer_name,
                    c.name AS crop_name, o.officer_code, o.full_name AS officer_name
             FROM inspections i
             INNER JOIN farmers f ON f.id = i.farmer_id
             INNER JOIN gardens g ON g.id = i.garden_id
             LEFT JOIN crops c ON c.id = i.crop_id
             LEFT JOIN field_officers o ON o.id = i.officer_id
             WHERE ' . $whereSql . '
             ORDER BY i.inspection_date DESC, i.id DESC
             LIMIT ' . $params['per_page'] . ' OFFSET ' . (($params['page'] - 1) * $params['per_page']),
            $values
        );

        return $this->paginate($rows, $total, $params, 'inspections');
    }

    /**
     * POST /api/v1/inspections
     */
    public function store(): Response
    {
        $existing = $this->findByClientUuid('inspections');

        if ($existing !== null) {
            return $this->ok($existing);
        }

        $uuid       = $this->clientUuid();
        $gardenCode = $this->request->string('garden_code');
        $errors     = [];

        if ($gardenCode === '') {
            $errors['garden_code'] = 'Name the garden you inspected.';
        }

        $garden = $gardenCode === '' ? null : $this->db->selectOne(
            'SELECT id, farmer_id, name FROM gardens WHERE garden_code = :code AND deleted_at IS NULL LIMIT 1',
            ['code' => $gardenCode]
        );

        if ($gardenCode !== '' && $garden === null) {
            $errors['garden_code'] = 'No garden found with that ID.';
        }

        if ($garden !== null) {
            $this->assertVisible($garden);
        }

        $enums = [
            'crop_condition'     => ['excellent', 'good', 'fair', 'poor', 'critical'],
            'pest_status'        => ['none', 'low', 'moderate', 'high', 'severe'],
            'disease_status'     => ['none', 'low', 'moderate', 'high', 'severe'],
            'weed_status'        => ['none', 'low', 'moderate', 'high', 'severe'],
            'soil_condition'     => ['excellent', 'good', 'fair', 'poor'],
            'weather_condition'  => ['sunny', 'cloudy', 'rainy', 'stormy', 'dry', 'foggy'],
        ];

        foreach ($enums as $field => $allowed) {
            $value = $this->request->string($field);

            if ($value !== '' && !in_array($value, $allowed, true)) {
                $errors[$field] = 'Select a valid ' . str_replace('_', ' ', $field) . '.';
            }
        }

        $inspectionDate = $this->request->date('inspected_at') ?? $this->request->date('inspection_date') ?? date('Y-m-d');
        $plantingId     = $this->request->int('planting_id');

        $planting = $plantingId > 0 ? $this->db->selectOne(
            'SELECT id, farmer_id, garden_id, crop_id, variety_id, season_id FROM plantings WHERE id = :id LIMIT 1',
            ['id' => $plantingId]
        ) : null;

        if ($plantingId > 0 && $planting === null) {
            $errors['planting_id'] = 'No planting found with that ID.';
        }

        if ($errors !== []) {
            return $this->validationFailed($errors);
        }

        if ($uuid !== null && $this->db->scalar(
            'SELECT 1 FROM inspections WHERE client_uuid = :uuid LIMIT 1',
            ['uuid' => $uuid]
        ) !== null) {
            return $this->ok($this->db->selectOne('SELECT * FROM inspections WHERE client_uuid = :uuid', ['uuid' => $uuid]));
        }

        $latitude  = $this->request->has('latitude') ? $this->request->float('latitude') : null;
        $longitude = $this->request->has('longitude') ? $this->request->float('longitude') : null;

        $id = $this->db->transaction(function () use ($garden, $planting, $inspectionDate, $latitude, $longitude, $uuid): int {
            $inspectionId = $this->db->insert('inspections', [
                'inspection_code'           => Identifier::generate('inspection', date_create_from_format('Y-m-d', $inspectionDate) ?: null),
                'visit_id'                  => $this->request->int('visit_id') ?: null,
                'farmer_id'                 => $garden['farmer_id'],
                'garden_id'                 => $garden['id'],
                'planting_id'               => $planting['id'] ?? null,
                'crop_id'                   => $planting['crop_id'] ?? ($this->request->int('crop_id') ?: null),
                'season_id'                 => $planting['season_id'] ?? ($this->request->int('season_id') ?: null),
                'officer_id'                => $this->auth->officerId(),
                'inspection_date'           => $inspectionDate,
                'latitude'                  => $latitude === 0.0 ? null : $latitude,
                'longitude'                 => $longitude === 0.0 ? null : $longitude,
                'crop_condition'            => $this->request->string('crop_condition') ?: null,
                'pest_status'               => $this->request->string('pest_status') ?: null,
                'disease_status'            => $this->request->string('disease_status') ?: null,
                'weed_status'               => $this->request->string('weed_status') ?: null,
                'soil_condition'            => $this->request->string('soil_condition') ?: null,
                'weather_condition'         => $this->request->string('weather_condition') ?: null,
                'growth_stage'              => $this->request->string('growth_stage') ?: null,
                'field_coverage'            => $this->request->has('field_coverage') ? $this->request->float('field_coverage') : null,
                'estimated_yield_per_acre'  => $this->request->has('estimated_yield_per_acre') ? $this->request->float('estimated_yield_per_acre') : null,
                'recommendations'           => $this->request->string('recommendations') ?: null,
                'remarks'                   => $this->request->string('remarks') ?: null,
                'status'                    => 'submitted',
                'created_by'                => $this->auth->id(),
                'client_uuid'               => $uuid,
            ]);

            $this->writeFindings($inspectionId);

            return $inspectionId;
        });

        Audit::log('create', 'inspections', 'inspection', $id, null, null, ['garden_id' => $garden['id']]);

        // The field observation is the evidence the platform holds about crop
        // condition, so it is the thing that moves the production forecast.
        $revision = null;

        if ($planting !== null) {
            $revision = $this->forecaster->reviseFromInspection((int) $planting['id']);
        }

        return $this->created([
            'inspection'     => $this->findOrFail($id),
            'forecast_revision' => $revision,
        ]);
    }

    /**
     * Individual findings, when the officer reported named pests or diseases
     * rather than only an overall status.
     *
     * @param array<int,array<string,string>> $findings
     */
    private function writeFindings(int $inspectionId): void
    {
        $findings = $this->request->input('findings');

        if (!is_array($findings)) {
            return;
        }

        $types = ['pest', 'disease', 'weed', 'nutrient', 'water', 'other'];
        $severities = ['none', 'low', 'moderate', 'high', 'severe'];

        foreach (array_slice($findings, 0, 20) as $finding) {
            if (!is_array($finding)) {
                continue;
            }

            $name = trim((string) ($finding['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $type = (string) ($finding['finding_type'] ?? 'other');

            $this->db->insert('inspection_findings', [
                'inspection_id' => $inspectionId,
                'finding_type'  => in_array($type, $types, true) ? $type : 'other',
                'name'          => mb_substr($name, 0, 150),
                'severity'      => in_array((string) ($finding['severity'] ?? ''), $severities, true)
                    ? (string) $finding['severity']
                    : 'low',
                'affected_area' => isset($finding['affected_area']) && is_numeric($finding['affected_area'])
                    ? (float) $finding['affected_area']
                    : null,
                'notes'         => trim((string) ($finding['notes'] ?? '')) ?: null,
            ]);
        }
    }

    private function findOrFail(int $id): array
    {
        $row = $this->db->selectOne(
            'SELECT i.*, g.garden_code, f.farmer_code, c.name AS crop_name
             FROM inspections i
             INNER JOIN gardens g ON g.id = i.garden_id
             INNER JOIN farmers f ON f.id = i.farmer_id
             LEFT JOIN crops c ON c.id = i.crop_id
             WHERE i.id = :id LIMIT 1',
            ['id' => $id]
        );

        return $row ?? ['id' => $id];
    }
}
