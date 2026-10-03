<?php
declare(strict_types=1);

namespace Http\Controllers\Api\V1;

use Core\Audit;
use Core\Identifier;
use Core\Response;
use Services\Lookups;

/**
 * Farmer API.
 *
 * Every response is scoped: a farmer account only ever sees its own record, a
 * field officer only sees the farmers assigned to them, and a manager sees the
 * whole register. Identifiers are minted server-side through the shared counter
 * table, and a retry carrying the same client_uuid returns the record that
 * already exists instead of creating a duplicate.
 */
final class FarmerApiController extends ApiController
{
    private Lookups $lookups;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);

        $this->lookups = new Lookups($db);
    }

    /**
     * GET /api/v1/farmers
     */
    public function index(): Response
    {
        $params   = $this->listParams(50);
        $where    = ['f.deleted_at IS NULL'];
        $values   = [];
        $scope    = $this->farmerScope($values);

        if ($params['search'] !== '') {
            $where[]      = '(f.full_name LIKE :s OR f.farmer_code LIKE :s OR f.phone LIKE :s OR f.national_id LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        if (($params['filters']['status'] ?? '') !== '') {
            $where[]      = 'f.status = :status';
            $values['status'] = $params['filters']['status'];
        }

        if (($params['filters']['district_id'] ?? '') !== '') {
            $where[]          = 'f.district_id = :district';
            $values['district'] = $params['filters']['district_id'];
        }

        if (($params['filters']['group_id'] ?? '') !== '') {
            $where[]      = 'f.group_id = :group';
            $values['group'] = $params['filters']['group_id'];
        }

        $whereSql = $scope . ' AND ' . implode(' AND ', $where);

        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM farmers f WHERE ' . $whereSql,
            $values
        );

        $rows = $this->db->select(
            'SELECT f.id, f.farmer_code, f.full_name, f.phone, f.alt_phone, f.gender,
                    f.status, f.farmer_type, f.registration_date, f.village_name,
                    f.latitude, f.longitude,
                    d.name AS district_name, g.name AS group_name,
                    o.full_name AS officer_name,
                    (SELECT COUNT(*) FROM gardens gd
                      WHERE gd.farmer_id = f.id AND gd.deleted_at IS NULL) AS garden_count,
                    (SELECT COALESCE(SUM(gd.acreage), 0) FROM gardens gd
                      WHERE gd.farmer_id = f.id AND gd.deleted_at IS NULL) AS total_acreage,
                    (SELECT COUNT(*) FROM crop_activities a
                      WHERE a.farmer_id = f.id AND a.status = :overdue) AS overdue_activities
             FROM farmers f
             LEFT JOIN districts d ON d.id = f.district_id
             LEFT JOIN farmer_groups g ON g.id = f.group_id
             LEFT JOIN field_officers o ON o.id = f.officer_id
             WHERE ' . $whereSql . '
             ORDER BY f.full_name ASC
             LIMIT ' . $params['per_page'] . ' OFFSET ' . (($params['page'] - 1) * $params['per_page']),
            $values + ['overdue' => 'overdue']
        );

        return $this->paginate($rows, $total, $params, 'farmers');
    }

    /**
     * POST /api/v1/farmers
     */
    public function store(): Response
    {
        $existing = $this->findByClientUuid('farmers');

        if ($existing !== null) {
            return $this->ok($existing);
        }

        $errors = [];

        $fullName = $this->request->string('full_name');
        $phone    = $this->request->string('phone');

        if ($fullName === '') {
            $errors['full_name'] = 'The full name is required.';
        }

        if ($phone === '') {
            $errors['phone'] = 'A phone number is required.';
        } elseif (strlen(preg_replace('/[^0-9+]/', '', $phone) ?? '') < 7) {
            $errors['phone'] = 'Enter a valid phone number.';
        }

        $email = $this->request->string('email');

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }

        $gender = $this->request->string('gender');

        if ($gender !== '' && !in_array($gender, ['male', 'female', 'other'], true)) {
            $errors['gender'] = 'Select a valid gender.';
        }

        $nationalId = $this->request->string('national_id');

        if ($nationalId !== '' && $this->db->scalar(
            'SELECT 1 FROM farmers WHERE national_id = :nid LIMIT 1',
            ['nid' => $nationalId]
        ) !== null) {
            $errors['national_id'] = 'That national ID number is already registered.';
        }

        if ($phone !== '' && $this->db->scalar(
            'SELECT 1 FROM farmers WHERE phone = :phone LIMIT 1',
            ['phone' => $phone]
        ) !== null) {
            $errors['phone'] = 'That phone number is already registered.';
        }

        if ($errors !== []) {
            return $this->validationFailed($errors);
        }

        $uuid = $this->clientUuid();

        if ($uuid !== null && $this->db->scalar(
            'SELECT 1 FROM farmers WHERE client_uuid = :uuid LIMIT 1',
            ['uuid' => $uuid]
        ) !== null) {
            return $this->ok($this->db->selectOne('SELECT * FROM farmers WHERE client_uuid = :uuid', ['uuid' => $uuid]));
        }

        $data = [
            'farmer_code'       => Identifier::generate('farmer'),
            'full_name'         => $fullName,
            'phone'             => $phone,
            'alt_phone'         => $this->request->string('alt_phone') ?: null,
            'email'             => $email ?: null,
            'gender'            => $gender ?: null,
            'date_of_birth'     => $this->request->date('date_of_birth'),
            'national_id'       => $nationalId ?: null,
            'village_name'      => $this->request->string('village_name') ?: null,
            'address'           => $this->request->string('address') ?: null,
            'latitude'          => $this->coordinate('latitude'),
            'longitude'         => $this->coordinate('longitude'),
            'region_id'         => $this->request->int('region_id') ?: null,
            'district_id'       => $this->request->int('district_id') ?: null,
            'sub_county_id'     => $this->request->int('sub_county_id') ?: null,
            'parish_id'         => $this->request->int('parish_id') ?: null,
            'village_id'        => $this->request->int('village_id') ?: null,
            'farmer_type'       => $this->request->string('farmer_type', 'smallholder'),
            'group_id'          => $this->request->int('group_id') ?: null,
            'officer_id'        => $this->request->int('officer_id') ?: null,
            'registration_date' => $this->request->date('registration_date') ?? date('Y-m-d'),
            'status'            => 'active',
            'created_by'        => $this->auth->id(),
            'client_uuid'       => $uuid,
        ];

        $id = $this->db->insert('farmers', $data);

        Audit::log('create', 'farmers', 'farmer', $id, $data['farmer_code'], null, $data);

        return $this->created($this->findOrFail($id));
    }

    /**
     * GET /api/v1/farmers/{code}
     */
    public function show(string $code): Response
    {
        $farmer = $this->findByCodeOrFail($code);

        $farmer['gardens'] = $this->db->select(
            'SELECT id, garden_code, name, acreage, status, latitude, longitude
             FROM gardens WHERE farmer_id = :farmer AND deleted_at IS NULL ORDER BY name',
            ['farmer' => $farmer['id']]
        );

        $farmer['plantings'] = $this->db->select(
            'SELECT p.id, p.planting_code, p.planting_date, p.acreage_planted, p.growth_stage,
                    p.status, c.name AS crop_name, v.name AS variety_name, s.season_code
             FROM plantings p
             INNER JOIN crops c ON c.id = p.crop_id
             LEFT JOIN crop_varieties v ON v.id = p.variety_id
             INNER JOIN seasons s ON s.id = p.season_id
             WHERE p.farmer_id = :farmer
             ORDER BY p.planting_date DESC LIMIT 50',
            ['farmer' => $farmer['id']]
        );

        $farmer['activity_summary'] = [
            'completed' => (int) $this->db->scalar("SELECT COUNT(*) FROM crop_activities WHERE farmer_id = :f AND status = 'completed'", ['f' => $farmer['id']]),
            'pending'   => (int) $this->db->scalar("SELECT COUNT(*) FROM crop_activities WHERE farmer_id = :f AND status = 'pending'", ['f' => $farmer['id']]),
            'due'       => (int) $this->db->scalar("SELECT COUNT(*) FROM crop_activities WHERE farmer_id = :f AND status = 'due'", ['f' => $farmer['id']]),
            'overdue'   => (int) $this->db->scalar(
                "SELECT COUNT(*) FROM crop_activities
                 WHERE farmer_id = :f AND (status = 'overdue' OR (status IN ('pending','due') AND due_date < CURDATE()))",
                ['f' => $farmer['id']]
            ),
        ];

        return $this->ok($farmer);
    }

    /**
     * POST /api/v1/farmers/{code}
     */
    public function update(string $code): Response
    {
        $farmer = $this->findByCodeOrFail($code);

        $errors = [];
        $phone  = $this->request->string('phone');
        $nid    = $this->request->string('national_id');
        $email  = $this->request->string('email');

        if ($phone !== '' && $this->db->scalar(
            'SELECT 1 FROM farmers WHERE phone = :phone AND id <> :id LIMIT 1',
            ['phone' => $phone, 'id' => $farmer['id']]
        ) !== null) {
            $errors['phone'] = 'That phone number is already registered.';
        }

        if ($nid !== '' && $this->db->scalar(
            'SELECT 1 FROM farmers WHERE national_id = :nid AND id <> :id LIMIT 1',
            ['nid' => $nid, 'id' => $farmer['id']]
        ) !== null) {
            $errors['national_id'] = 'That national ID number is already registered.';
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }

        if ($errors !== []) {
            return $this->validationFailed($errors);
        }

        // farmer_code, uuid and client_uuid are never writable from a client,
        // so a crafted payload cannot rewrite an issued identifier.
        $data = array_filter([
            'full_name'     => $this->request->string('full_name'),
            'phone'         => $phone,
            'alt_phone'     => $this->request->string('alt_phone'),
            'email'         => $email,
            'gender'        => $this->request->string('gender'),
            'national_id'   => $nid,
            'village_name'  => $this->request->string('village_name'),
            'address'       => $this->request->string('address'),
            'district_id'   => $this->request->int('district_id') ?: null,
            'sub_county_id' => $this->request->int('sub_county_id') ?: null,
            'parish_id'     => $this->request->int('parish_id') ?: null,
            'village_id'    => $this->request->int('village_id') ?: null,
            'farmer_type'   => $this->request->string('farmer_type'),
            'group_id'      => $this->request->int('group_id') ?: null,
            'officer_id'    => $this->request->int('officer_id') ?: null,
        ], static fn ($value): bool => $value !== null);

        if ($this->request->has('latitude')) {
            $data['latitude'] = $this->coordinate('latitude');
        }

        if ($this->request->has('longitude')) {
            $data['longitude'] = $this->coordinate('longitude');
        }

        if ($this->request->has('status')) {
            $data['status'] = $this->request->string('status');
        }

        if ($data !== []) {
            $this->db->update('farmers', $data, 'id = :id', ['id' => $farmer['id']]);

            Audit::log('update', 'farmers', 'farmer', (int) $farmer['id'], $farmer['farmer_code'], $farmer, $data);
        }

        return $this->ok($this->findOrFail((int) $farmer['id']));
    }

    /**
     * GET /api/v1/farmers/{code}/gardens
     */
    public function gardens(string $code): Response
    {
        $farmer = $this->findByCodeOrFail($code);

        $rows = $this->db->select(
            'SELECT g.id, g.garden_code, g.name, g.acreage, g.status, g.mapped,
                    g.latitude, g.longitude, g.village_name,
                    (SELECT c.name FROM plantings p
                       INNER JOIN crops c ON c.id = p.crop_id
                      WHERE p.garden_id = g.id AND p.status = :planted
                      ORDER BY p.planting_date DESC LIMIT 1) AS current_crop
             FROM gardens g
             WHERE g.farmer_id = :farmer AND g.deleted_at IS NULL
             ORDER BY g.name',
            ['farmer' => $farmer['id'], 'planted' => 'planted']
        );

        return $this->ok(['gardens' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    /**
     * The farmers table keys on `id`, not `farmer_id`, so the shared
     * farmer_id-based scope clause does not apply here.
     *
     * @param array<string,mixed> $values
     */
    private function farmerScope(array &$values): string
    {
        $farmerId  = $this->auth->farmerId();
        $officerId = $this->auth->officerId();

        if ($farmerId !== null) {
            $values['scope_farmer'] = $farmerId;

            return 'f.id = :scope_farmer';
        }

        if ($officerId !== null) {
            $values['scope_officer'] = $officerId;

            return 'f.officer_id = :scope_officer';
        }

        return '1 = 1';
    }

    private function findByCodeOrFail(string $code): array
    {
        $row = $this->db->selectOne(
            'SELECT f.*, d.name AS district_name, sc.name AS sub_county_name,
                    g.name AS group_name, o.officer_code, o.full_name AS officer_name
             FROM farmers f
             LEFT JOIN districts d ON d.id = f.district_id
             LEFT JOIN sub_counties sc ON sc.id = f.sub_county_id
             LEFT JOIN farmer_groups g ON g.id = f.group_id
             LEFT JOIN field_officers o ON o.id = f.officer_id
             WHERE f.farmer_code = :code AND f.deleted_at IS NULL LIMIT 1',
            ['code' => $code]
        );

        if ($row === null) {
            return $this->notFound('No farmer found with that ID.');
        }

        $this->assertVisible($row);

        return $row;
    }

    private function findOrFail(int $id): array
    {
        $row = $this->db->selectOne(
            'SELECT f.*, d.name AS district_name, g.name AS group_name, o.full_name AS officer_name
             FROM farmers f
             LEFT JOIN districts d ON d.id = f.district_id
             LEFT JOIN farmer_groups g ON g.id = f.group_id
             LEFT JOIN field_officers o ON o.id = f.officer_id
             WHERE f.id = :id LIMIT 1',
            ['id' => $id]
        );

        return $row ?? ['id' => $id];
    }

    /**
     * A coordinate of 0 is a valid geographic value but is indistinguishable
     * from "not supplied" here, so it is normalised to null by the caller.
     */
    private function coordinate(string $field): ?float
    {
        if (!$this->request->has($field)) {
            return null;
        }

        $value = $this->request->float($field);

        return $value === 0.0 ? null : $value;
    }
}
