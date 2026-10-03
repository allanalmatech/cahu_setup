<?php
declare(strict_types=1);

namespace Http\Controllers\Api\V1;

use Core\Audit;
use Core\Identifier;
use Core\Response;
use Services\Notifier;

/**
 * Problem reporting API.
 *
 * The table keys the problem on `problem_type_id` (a lookup into
 * `problem_types`) and dates it with `report_date` — there is no free-text
 * `problem_type` column and no `reported_at` column. A report raised in the
 * field immediately notifies the officer who has to act on it.
 */
final class ProblemApiController extends ApiController
{
    private Notifier $notifier;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);

        $this->notifier = new Notifier($db);
    }

    /**
     * GET /api/v1/problems
     */
    public function index(): Response
    {
        $params = $this->listParams(50);
        $values = [];
        $scope  = $this->scopeWhere($values, 'pr');

        $where = ['1 = 1'];

        $status = $this->request->string('status');

        if ($status === 'open') {
            $where[] = "pr.status NOT IN ('resolved','closed')";
        } elseif ($status !== '') {
            $where[]         = 'pr.status = :status';
            $values['status'] = $status;
        }

        if ($this->request->int('garden_id') > 0) {
            $where[]    = 'pr.garden_id = :garden';
            $values['garden'] = $this->request->int('garden_id');
        }

        $whereSql = implode(' AND ', $where) . $scope;

        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM problem_reports pr WHERE ' . $whereSql,
            $values
        );

        $rows = $this->db->select(
            'SELECT pr.id, pr.problem_code, pr.severity, pr.description, pr.report_date,
                    pr.status, pr.latitude, pr.longitude, pr.action_taken, pr.resolution,
                    pr.resolved_at,
                    pt.name AS problem_type, pt.code AS problem_type_code, pt.category,
                    g.garden_code, g.name AS garden_name,
                    f.farmer_code, f.full_name AS farmer_name,
                    o.officer_code, o.full_name AS assigned_officer
             FROM problem_reports pr
             INNER JOIN problem_types pt ON pt.id = pr.problem_type_id
             INNER JOIN farmers f ON f.id = pr.farmer_id
             LEFT JOIN gardens g ON g.id = pr.garden_id
             LEFT JOIN field_officers o ON o.id = pr.assigned_officer_id
             WHERE ' . $whereSql . '
             ORDER BY pr.report_date DESC, pr.id DESC
             LIMIT ' . $params['per_page'] . ' OFFSET ' . (($params['page'] - 1) * $params['per_page']),
            $values
        );

        return $this->paginate($rows, $total, $params, 'problems');
    }

    /**
     * POST /api/v1/problems
     */
    public function store(): Response
    {
        $existing = $this->findByClientUuid('problem_reports');

        if ($existing !== null) {
            return $this->ok($existing);
        }

        $uuid        = $this->clientUuid();
        $errors      = [];
        $description = $this->request->string('description');

        if ($description === '') {
            $errors['description'] = 'Describe the problem.';
        }

        $problemTypeId = $this->problemTypeId($errors);

        $severity = $this->request->string('severity', 'medium');

        if (!in_array($severity, ['low', 'medium', 'high', 'critical'], true)) {
            $errors['severity'] = 'Select a valid severity.';
        }

        $gardenCode = $this->request->string('garden_code');
        $garden     = $gardenCode === '' ? null : $this->db->selectOne(
            'SELECT g.id, g.farmer_id, g.name, g.district_id, g.region_id, f.full_name AS farmer_name
             FROM gardens g
             INNER JOIN farmers f ON f.id = g.farmer_id
             WHERE g.garden_code = :code AND g.deleted_at IS NULL LIMIT 1',
            ['code' => $gardenCode]
        );

        if ($gardenCode !== '' && $garden === null) {
            $errors['garden_code'] = 'No garden found with that ID.';
        }

        if ($garden !== null) {
            $this->assertVisible($garden);
        }

        $farmerId = $garden['farmer_id'] ?? $this->auth->farmerId();

        if ($farmerId === null) {
            $errors['garden_code'] = 'Name the garden the problem was found in.';
        }

        if ($errors !== []) {
            return $this->validationFailed($errors);
        }

        if ($uuid !== null && $this->db->scalar(
            'SELECT 1 FROM problem_reports WHERE client_uuid = :uuid LIMIT 1',
            ['uuid' => $uuid]
        ) !== null) {
            return $this->ok($this->db->selectOne('SELECT * FROM problem_reports WHERE client_uuid = :uuid', ['uuid' => $uuid]));
        }

        $reportDate = $this->request->date('report_date') ?? $this->request->date('reported_at') ?? date('Y-m-d');

        $data = [
            'problem_code'       => Identifier::generate('problem', date_create_from_format('Y-m-d', $reportDate) ?: null),
            'problem_type_id'    => $problemTypeId,
            'farmer_id'          => $farmerId,
            'garden_id'          => $garden['id'] ?? null,
            'planting_id'        => $this->request->int('planting_id') ?: null,
            'crop_id'            => $this->request->int('crop_id') ?: null,
            'season_id'          => $this->request->int('season_id') ?: null,
            'region_id'          => $this->request->int('region_id') ?: ($garden['region_id'] ?? null),
            'district_id'        => $this->request->int('district_id') ?: ($garden['district_id'] ?? null),
            'latitude'           => $this->coordinate('latitude'),
            'longitude'          => $this->coordinate('longitude'),
            'report_date'        => $reportDate,
            'severity'           => $severity,
            'description'        => $description,
            'affected_area'      => $this->request->has('affected_area') ? $this->request->float('affected_area') : null,
            'reported_by'        => $this->auth->id(),
            'reported_by_type'   => $this->auth->officerId() !== null ? 'officer' : 'farmer',
            'assigned_officer_id'=> $this->request->int('assigned_officer_id') ?: ($garden === null ? null : $this->officerFor((int) $farmerId)),
            'status'             => 'reported',
            'client_uuid'        => $uuid,
        ];

        $id = $this->db->insert('problem_reports', $data);

        Audit::log('create', 'problems', 'problem_report', $id, $data['problem_code'], null, $data);

        $this->notifyOfficer($id, $data, $severity);

        return $this->created(['id' => $id, 'problem_code' => $data['problem_code'], 'status' => 'reported']);
    }

    /**
     * Accepts either the numeric `problem_type_id` or the `problem_type` code,
     * so a client configured from an older build still resolves.
     *
     * @param array<string,string> $errors
     */
    private function problemTypeId(array &$errors): int
    {
        $id = $this->request->int('problem_type_id');

        if ($id > 0) {
            $exists = $this->db->scalar('SELECT 1 FROM problem_types WHERE id = :id LIMIT 1', ['id' => $id]);

            if ($exists !== null) {
                return $id;
            }

            $errors['problem_type_id'] = 'That problem type is not recognised.';
        }

        $code = $this->request->string('problem_type');

        if ($code !== '') {
            $resolved = $this->db->scalar('SELECT id FROM problem_types WHERE code = :code LIMIT 1', ['code' => $code]);

            if ($resolved !== null) {
                return (int) $resolved;
            }

            $errors['problem_type'] = 'That problem type is not recognised.';
        }

        if ($errors === []) {
            $errors['problem_type_id'] = 'Choose the type of problem.';
        }

        return 0;
    }

    private function officerFor(int $farmerId): ?int
    {
        $id = $this->db->scalar(
            'SELECT officer_id FROM farmers WHERE id = :id AND officer_id IS NOT NULL LIMIT 1',
            ['id' => $farmerId]
        );

        return $id === null ? null : (int) $id;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function notifyOfficer(int $id, array $data, string $severity): void
    {
        $officerId = $data['assigned_officer_id'] ?? null;

        if ($officerId === null) {
            return;
        }

        $typeName = (string) $this->db->scalar(
            'SELECT name FROM problem_types WHERE id = :id',
            ['id' => $data['problem_type_id']]
        );

        $this->notifier->notify([
            'type'        => 'problem_reported',
            'title'       => 'New ' . $severity . ' problem reported',
            'message'     => $typeName . ': ' . mb_substr((string) $data['description'], 0, 160),
            'farmer_id'   => $data['farmer_id'],
            'officer_id'  => (int) $officerId,
            'entity_type' => 'problem_report',
            'entity_id'   => $id,
            'link'        => '/problems/' . $data['problem_code'],
            'channels'    => ['system', 'sms'],
            'dedupe_key'  => 'problem:' . $data['problem_code'],
        ]);
    }

    private function coordinate(string $field): ?float
    {
        if (!$this->request->has($field)) {
            return null;
        }

        $value = $this->request->float($field);

        return $value === 0.0 ? null : $value;
    }
}
