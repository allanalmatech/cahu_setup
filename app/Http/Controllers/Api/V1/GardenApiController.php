<?php
declare(strict_types=1);

namespace Http\Controllers\Api\V1;

use Core\Audit;
use Core\Identifier;
use Core\Response;

/**
 * Garden API.
 *
 * Gardens carry the `farmer_id` column the shared scope clause expects, so the
 * inherited visibility rules apply unchanged. `village_name` and `district_id`
 * are the real location columns; there is no free-text `village` or `district`
 * column on this table.
 */
final class GardenApiController extends ApiController
{
    /**
     * GET /api/v1/gardens
     */
    public function index(): Response
    {
        $params = $this->listParams(50);
        $values = [];
        $scope  = $this->scopeWhere($values, 'g');

        $where  = ['g.deleted_at IS NULL'];
        $total  = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM gardens g WHERE ' . implode(' AND ', $where) . $scope,
            $values
        );

        $rows = $this->db->select(
            'SELECT g.id, g.garden_code, g.name, g.acreage, g.status, g.mapped,
                    g.latitude, g.longitude, g.village_name, g.land_ownership,
                    g.soil_type, g.irrigation_status, g.terrain,
                    d.name AS district_name,
                    f.farmer_code, f.full_name AS farmer_name, f.phone AS farmer_phone,
                    (SELECT c.name FROM plantings p
                       INNER JOIN crops c ON c.id = p.crop_id
                      WHERE p.garden_id = g.id AND p.status = :planted
                      ORDER BY p.planting_date DESC LIMIT 1) AS current_crop
             FROM gardens g
             INNER JOIN farmers f ON f.id = g.farmer_id
             LEFT JOIN districts d ON d.id = g.district_id
             WHERE ' . implode(' AND ', $where) . $scope . '
             ORDER BY f.full_name ASC, g.name ASC
             LIMIT ' . $params['per_page'] . ' OFFSET ' . (($params['page'] - 1) * $params['per_page']),
            $values + ['planted' => 'planted']
        );

        return $this->paginate($rows, $total, $params, 'gardens');
    }

    /**
     * GET /api/v1/gardens/{code}
     */
    public function show(string $code): Response
    {
        $garden = $this->findByCodeOrFail($code);

        $garden['plantings'] = $this->db->select(
            'SELECT p.id, p.planting_code, p.planting_date, p.acreage_planted,
                    p.expected_harvest_date, p.growth_stage, p.status,
                    c.name AS crop_name, v.name AS variety_name, s.season_code
             FROM plantings p
             INNER JOIN crops c ON c.id = p.crop_id
             LEFT JOIN crop_varieties v ON v.id = p.variety_id
             INNER JOIN seasons s ON s.id = p.season_id
             WHERE p.garden_id = :garden
             ORDER BY p.planting_date DESC LIMIT 25',
            ['garden' => $garden['id']]
        );

        $garden['boundary'] = $this->db->selectOne(
            'SELECT geojson, area_acres, mapped_at FROM garden_boundaries WHERE garden_id = :garden LIMIT 1',
            ['garden' => $garden['id']]
        );

        $garden['history'] = $this->db->select(
            'SELECT event_type, from_value, to_value, reason, created_at
             FROM garden_history WHERE garden_id = :garden ORDER BY created_at DESC LIMIT 25',
            ['garden' => $garden['id']]
        );

        return $this->ok($garden);
    }

    /**
     * POST /api/v1/gardens
     */
    public function store(): Response
    {
        $existing = $this->findByClientUuid('gardens');

        if ($existing !== null) {
            return $this->ok($existing);
        }

        $errors    = [];
        $farmerRef = $this->request->string('farmer_code');
        $gardenId  = $this->request->int('garden_id');
        $farmerId  = $farmerRef === '' ? null : $this->resolveFarmer($farmerRef);

        if ($farmerRef === '' && $gardenId === 0) {
            $errors['farmer_code'] = 'Name the farmer this garden belongs to.';
        }

        if ($farmerRef !== '' && $farmerId === null) {
            $errors['farmer_code'] = 'No farmer found with that ID.';
        }

        $name = $this->request->string('name');

        if ($name === '') {
            $errors['name'] = 'The garden name is required.';
        }

        $acreage = $this->request->float('acreage');

        if ($acreage < 0) {
            $errors['acreage'] = 'The acreage may not be negative.';
        }

        if ($this->db->scalar(
            'SELECT 1 FROM gardens WHERE farmer_id = :farmer AND name = :name AND deleted_at IS NULL LIMIT 1',
            ['farmer' => $farmerId ?? 0, 'name' => $name]
        ) !== null) {
            $errors['name'] = 'This farmer already has a garden with that name.';
        }

        if ($errors !== []) {
            return $this->validationFailed($errors);
        }

        $data = $this->gardenValues();
        $data['farmer_id']   = $farmerId;
        $data['garden_code'] = Identifier::generate('garden');
        $data['created_by']  = $this->auth->id();

        $id = $this->db->insert('gardens', $data);

        Audit::log('create', 'gardens', 'garden', $id, $data['garden_code'], null, $data);

        return $this->created($this->findOrFail($id));
    }

    /**
     * POST /api/v1/gardens/{code}
     */
    public function update(string $code): Response
    {
        $garden = $this->findByCodeOrFail($code);

        $name = $this->request->string('name');

        if ($name !== '' && $this->db->scalar(
            'SELECT 1 FROM gardens WHERE farmer_id = :farmer AND name = :name AND id <> :id AND deleted_at IS NULL LIMIT 1',
            ['farmer' => $garden['farmer_id'], 'name' => $name, 'id' => $garden['id']]
        ) !== null) {
            return $this->validationFailed(['name' => 'This farmer already has a garden with that name.']);
        }

        $data = $this->gardenValues();

        if ($data === []) {
            return $this->ok($garden);
        }

        // Changing a plot's identity or measurement is a traceability event, so
        // it is written to the append-only garden_history log as well.
        $this->db->transaction(function () use ($garden, $data): void {
            foreach ($data as $field => $value) {
                $previous = $garden[$field] ?? null;

                if ((string) $previous === (string) ($value ?? '')) {
                    continue;
                }

                $this->db->insert('garden_history', [
                    'garden_id'  => $garden['id'],
                    'farmer_id'  => $garden['farmer_id'],
                    'event_type' => 'updated',
                    'from_value' => $previous === null ? null : substr((string) $previous, 0, 255),
                    'to_value'   => $value === null ? null : substr((string) $value, 0, 255),
                    'reason'     => 'Updated from the API',
                    'changed_by' => $this->auth->id(),
                    'created_by' => $this->auth->id(),
                ]);
            }

            $this->db->update('gardens', $data, 'id = :id', ['id' => $garden['id']]);
        });

        Audit::log('update', 'gardens', 'garden', (int) $garden['id'], $garden['garden_code'], $garden, $data);

        return $this->ok($this->findOrFail((int) $garden['id']));
    }

    /**
     * The submitted garden fields, skipping any the client did not send so a
     * partial update never blanks a column.
     *
     * @return array<string,mixed>
     */
    private function gardenValues(): array
    {
        $data = [];

        $text = [
            'name', 'village_name', 'address', 'notes',
            'land_ownership', 'soil_type', 'irrigation_status', 'water_source',
            'terrain', 'status',
        ];

        foreach ($text as $field) {
            if ($this->request->has($field)) {
                $data[$field] = $this->request->string($field) ?: null;
            }
        }

        if ($this->request->has('acreage')) {
            $data['acreage'] = $this->request->float('acreage');
        }

        foreach (['altitude'] as $field) {
            if ($this->request->has($field)) {
                $data[$field] = $this->request->float($field) ?: null;
            }
        }

        foreach (['region_id', 'district_id', 'sub_county_id', 'parish_id', 'village_id'] as $field) {
            if ($this->request->has($field)) {
                $data[$field] = $this->request->int($field) ?: null;
            }
        }

        foreach (['latitude', 'longitude'] as $field) {
            if ($this->request->has($field)) {
                $value = $this->request->float($field);
                $data[$field] = $value === 0.0 ? null : $value;
            }
        }

        // A garden only counts as mapped once it actually has coordinates.
        if (isset($data['latitude']) || isset($data['longitude'])) {
            $data['mapped'] = (isset($data['latitude']) && isset($data['longitude'])) ? 1 : 0;
        }

        return $data;
    }

    private function resolveFarmer(string $code): ?int
    {
        $id = $this->db->scalar(
            'SELECT id FROM farmers WHERE farmer_code = :code AND deleted_at IS NULL LIMIT 1',
            ['code' => $code]
        );

        return $id === null ? null : (int) $id;
    }

    private function findByCodeOrFail(string $code): array
    {
        $row = $this->db->selectOne(
            'SELECT g.*, f.farmer_code, f.full_name AS farmer_name, f.phone AS farmer_phone,
                    d.name AS district_name
             FROM gardens g
             INNER JOIN farmers f ON f.id = g.farmer_id
             LEFT JOIN districts d ON d.id = g.district_id
             WHERE g.garden_code = :code AND g.deleted_at IS NULL LIMIT 1',
            ['code' => $code]
        );

        if ($row === null) {
            throw new \Core\HttpException(404, 'Record not found.');
        }

        $this->assertVisible($row);

        return $row;
    }

    private function findOrFail(int $id): array
    {
        $row = $this->db->selectOne(
            'SELECT g.*, f.farmer_code, f.full_name AS farmer_name, d.name AS district_name
             FROM gardens g
             INNER JOIN farmers f ON f.id = g.farmer_id
             LEFT JOIN districts d ON d.id = g.district_id
             WHERE g.id = :id LIMIT 1',
            ['id' => $id]
        );

        return $row ?? ['id' => $id];
    }
}
