<?php
declare(strict_types=1);

namespace Http\Controllers\Api\V1;

use Core\Audit;
use Core\Identifier;
use Core\Response;
use Services\Forecaster;

/**
 * Harvest API.
 *
 * A harvest is what closes the loop on a planting, so recording one also folds
 * the actual tonnage back into that planting's forecast. Without that step the
 * variance figures on the command centre would stay at zero forever.
 */
final class HarvestApiController extends ApiController
{
    private Forecaster $forecaster;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);

        $this->forecaster = new Forecaster($db);
    }

    /**
     * GET /api/v1/harvests
     */
    public function index(): Response
    {
        $params = $this->listParams(50);
        $values = [];
        $scope  = $this->scopeWhere($values, 'h');

        $where = ['1 = 1'];

        $status = $this->request->string('status');

        if ($status !== '') {
            $where[]         = 'h.status = :status';
            $values['status'] = $status;
        }

        $from = $this->request->date('from');

        if ($from !== null) {
            $where[]      = 'h.harvest_date >= :from';
            $values['from'] = $from;
        }

        $to = $this->request->date('to');

        if ($to !== null) {
            $where[]    = 'h.harvest_date <= :to';
            $values['to'] = $to;
        }

        $whereSql = implode(' AND ', $where) . $scope;

        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM harvest_records h WHERE ' . $whereSql,
            $values
        );

        $rows = $this->db->select(
            'SELECT h.id, h.harvest_code, h.harvest_date, h.acreage_harvested,
                    h.quantity_harvested, h.unit, h.quality, h.grade, h.moisture_content,
                    h.losses, h.storage_location, h.status,
                    g.garden_code, g.name AS garden_name,
                    f.farmer_code, f.full_name AS farmer_name,
                    c.name AS crop_name, v.name AS variety_name, s.season_code,
                    (SELECT bt.batch_code FROM harvest_batch_items hbi
                       INNER JOIN harvest_batches bt ON bt.id = hbi.batch_id
                      WHERE hbi.harvest_id = h.id LIMIT 1) AS batch_code
             FROM harvest_records h
             INNER JOIN gardens g ON g.id = h.garden_id
             INNER JOIN farmers f ON f.id = h.farmer_id
             INNER JOIN crops c ON c.id = h.crop_id
             LEFT JOIN crop_varieties v ON v.id = h.variety_id
             INNER JOIN seasons s ON s.id = h.season_id
             WHERE ' . $whereSql . '
             ORDER BY h.harvest_date DESC, h.id DESC
             LIMIT ' . $params['per_page'] . ' OFFSET ' . (($params['page'] - 1) * $params['per_page']),
            $values
        );

        return $this->paginate($rows, $total, $params, 'harvests');
    }

    /**
     * POST /api/v1/harvests
     */
    public function store(): Response
    {
        $existing = $this->findByClientUuid('harvest_records');

        if ($existing !== null) {
            return $this->ok($existing);
        }

        $uuid       = $this->clientUuid();
        $gardenRef  = $this->request->string('garden_code');
        $plantingId = $this->request->int('planting_id');
        $errors     = [];

        $planting = $plantingId > 0 ? $this->db->selectOne(
            'SELECT id, farmer_id, garden_id, crop_id, variety_id, season_id, officer_id
             FROM plantings WHERE id = :id LIMIT 1',
            ['id' => $plantingId]
        ) : null;

        if ($plantingId > 0 && $planting === null) {
            $errors['planting_id'] = 'No planting found with that ID.';
        }

        $garden = $gardenRef === '' ? null : $this->db->selectOne(
            'SELECT id, farmer_id, name FROM gardens WHERE garden_code = :code AND deleted_at IS NULL LIMIT 1',
            ['code' => $gardenRef]
        );

        if ($gardenRef !== '' && $garden === null) {
            $errors['garden_code'] = 'No garden found with that ID.';
        }

        $garden = $garden ?? ($planting !== null ? $this->db->selectOne(
            'SELECT id, farmer_id, name FROM gardens WHERE id = :id LIMIT 1',
            ['id' => $planting['garden_id']]
        ) : null);

        if ($garden === null) {
            $errors['garden_code'] = 'A garden is required.';
        }

        if ($garden !== null) {
            $this->assertVisible($garden);
        }

        if ($planting === null && $garden !== null) {
            $planting = $this->db->selectOne(
                'SELECT id, farmer_id, garden_id, crop_id, variety_id, season_id, officer_id
                 FROM plantings
                 WHERE garden_id = :garden AND status IN (:planted, :harvested)
                 ORDER BY planting_date DESC LIMIT 1',
                ['garden' => $garden['id'], 'planted' => 'planted', 'harvested' => 'harvested']
            );
        }

        $cropId = $planting !== null ? (int) $planting['crop_id'] : $this->request->int('crop_id');
        $seasonId = $planting !== null ? (int) $planting['season_id'] : $this->request->int('season_id');

        if ($cropId <= 0) {
            $errors['crop_id'] = 'A crop is required.';
        }

        if ($seasonId <= 0) {
            $errors['season_id'] = 'A season is required.';
        }

        $quantity = $this->request->float('quantity_harvested');

        if ($quantity <= 0) {
            $errors['quantity_harvested'] = 'Enter the quantity harvested.';
        }

        $harvestDate = $this->request->date('harvest_date') ?? date('Y-m-d');

        $unit = $this->request->string('unit');

        if ($unit === '') {
            $unit = $planting !== null
                ? (string) ($this->db->scalar(
                    'SELECT COALESCE(yield_unit, primary_unit, :fallback) FROM crops WHERE id = :crop',
                    ['crop' => $cropId, 'fallback' => 'KG']
                ) ?? 'KG')
                : 'KG';
        }

        $quality = $this->request->string('quality');

        if ($quality !== '' && !in_array($quality, ['premium', 'grade1', 'grade2', 'grade3', 'reject'], true)) {
            $errors['quality'] = 'Select a valid quality grade.';
        }

        if ($errors !== []) {
            return $this->validationFailed($errors);
        }

        if ($uuid !== null && $this->db->scalar(
            'SELECT 1 FROM harvest_records WHERE client_uuid = :uuid LIMIT 1',
            ['uuid' => $uuid]
        ) !== null) {
            return $this->ok($this->db->selectOne('SELECT * FROM harvest_records WHERE client_uuid = :uuid', ['uuid' => $uuid]));
        }

        $data = [
            'harvest_code'      => Identifier::generate('harvest', date_create_from_format('Y-m-d', $harvestDate) ?: null),
            'planting_id'       => $plantingId > 0 ? $plantingId : ($planting['id'] ?? null),
            'farmer_id'         => $garden['farmer_id'],
            'garden_id'         => $garden['id'],
            'crop_id'           => $cropId,
            'variety_id'        => $planting['variety_id'] ?? ($this->request->int('variety_id') ?: null),
            'season_id'         => $seasonId,
            'harvest_date'      => $harvestDate,
            'acreage_harvested' => $this->request->float('acreage_harvested'),
            'quantity_harvested'=> $quantity,
            'unit'              => $unit,
            'quality'           => $quality ?: null,
            'grade'             => $this->request->string('grade') ?: null,
            'moisture_content'  => $this->request->has('moisture_content') ? $this->request->float('moisture_content') : null,
            'losses'            => $this->request->has('losses') ? $this->request->float('losses') : null,
            'storage_location'  => $this->request->string('storage_location') ?: null,
            'officer_id'        => $this->auth->officerId() ?? ($planting['officer_id'] ?? null),
            'status'            => 'recorded',
            'notes'             => $this->request->string('notes') ?: null,
            'created_by'        => $this->auth->id(),
            'client_uuid'       => $uuid,
        ];

        $id = $this->db->transaction(function () use ($data): int {
            $harvestId = $this->db->insert('harvest_records', $data);

            $plantingId = $data['planting_id'];

            if ($plantingId !== null) {
                $this->forecaster->recordActual((int) $plantingId);
            }

            return $harvestId;
        });

        Audit::log('create', 'harvest', 'harvest', $id, $data['harvest_code'], null, $data);

        return $this->created($this->findOrFail($id));
    }

    private function findOrFail(int $id): array
    {
        $row = $this->db->selectOne(
            'SELECT h.*, g.garden_code, g.name AS garden_name, c.name AS crop_name,
                    v.name AS variety_name, s.season_code, f.farmer_code
             FROM harvest_records h
             INNER JOIN gardens g ON g.id = h.garden_id
             INNER JOIN crops c ON c.id = h.crop_id
             LEFT JOIN crop_varieties v ON v.id = h.variety_id
             INNER JOIN seasons s ON s.id = h.season_id
             INNER JOIN farmers f ON f.id = h.farmer_id
             WHERE h.id = :id LIMIT 1',
            ['id' => $id]
        );

        return $row ?? ['id' => $id];
    }
}
