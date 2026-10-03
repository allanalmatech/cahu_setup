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
use Services\Traceability;

/**
 * Batches: the lot that brings several farmers' harvests together.
 *
 * A batch is the unit that buyers purchase and that exporters ship, so it is
 * where aggregation rules have to hold. Two invariants are enforced on every
 * change:
 *
 *   1. One lot is one crop, one variety, one season and one unit. Mixing them
 *      would make the batch impossible to grade or to trace meaningfully.
 *   2. A harvest belongs to at most one batch, so no kilo can be counted twice.
 *
 * Once a batch is closed its contents are frozen, because a certificate may
 * already have been issued against it.
 */
final class BatchController extends Controller
{
    private Lookups $lookups;
    private Traceability $traceability;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups       = new Lookups($db);
        $this->traceability  = new Traceability($db);
    }

    public function index(): Response
    {
        $params = $this->listParams();

        $where  = ['1 = 1'];
        $values = [];

        if ($params['search'] !== '') {
            $where[]      = '(b.batch_code LIKE :s OR c.name LIKE :s OR b.storage_location LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        foreach (['status' => 'b.status', 'crop_id' => 'b.crop_id', 'season_id' => 'b.season_id'] as $key => $column) {
            if (($params['filters'][$key] ?? '') !== '') {
                $where[]      = $column . ' = :' . $key;
                $values[$key] = $params['filters'][$key];
            }
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $totals = $this->db->selectOne(
            'SELECT COUNT(*) AS batches, COALESCE(SUM(b.total_quantity), 0) AS quantity,
                    COALESCE(SUM(b.contributor_count), 0) AS contributors
             FROM harvest_batches b
             LEFT JOIN crops c ON c.id = b.crop_id' . $whereSql,
            $values
        ) ?? ['batches' => 0, 'quantity' => 0, 'contributors' => 0];

        $paginator = new Paginator((int) $totals['batches'], $params['page'], $params['per_page']);

        $sortable = ['batch_date', 'total_quantity', 'contributor_count', 'status'];
        $column   = in_array($params['sort'], $sortable, true) ? $params['sort'] : 'batch_date';
        $order    = $params['direction'] === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->db->select(
            'SELECT b.*, c.name AS crop_name, s.name AS season_name,
                    (SELECT COUNT(*) FROM sale_items si WHERE si.batch_id = b.id) AS sale_lines,
                    (SELECT COUNT(*) FROM exports e WHERE e.batch_id = b.id) AS export_lines
             FROM harvest_batches b
             LEFT JOIN crops c ON c.id = b.crop_id
             LEFT JOIN seasons s ON s.id = b.season_id'
            . $whereSql
            . ' ORDER BY b.' . $column . ' ' . $order
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('batches/index', [
            'pageTitle' => 'Batches',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $column,
            'direction' => $params['direction'],
            'totals'    => $totals,
            'canManage' => $this->auth->can('batches.manage'),
            'filters'   => [
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions(
                    "'open','aggregated','allocated','sold','exported','closed',''",
                    'All statuses'
                )],
                ['key' => 'crop_id', 'label' => 'Crop', 'options' => $this->lookups->cropOptions('All crops')],
                ['key' => 'season_id', 'label' => 'Season', 'options' => $this->lookups->seasonOptions('All seasons')],
            ],
        ]);
    }

    public function create(): Response
    {
        return $this->render('batches/form', [
            'pageTitle' => 'Create batch',
            'batch'     => ['batch_date' => date('Y-m-d')],
            'crops'     => $this->lookups->cropOptions('Select crop...'),
            'varieties' => $this->lookups->varietyOptions('No specific variety'),
            'seasons'   => $this->lookups->seasonOptions('Select season...'),
            'options'   => $this->unitOptions(),
        ]);
    }

    public function store(): Response
    {
        $this->assertCsrf();

        $input     = $this->request->all();
        $validator = Form::validate($this->fields(), $input);

        $validator->date('batch_date', 'Batch date', true);
        $validator->numeric('total_quantity', 'Opening quantity', false);

        if ($validator->fails()) {
            return $this->backWithErrors(url('batches/create'), $validator->errors(), $input);
        }

        $crop   = $this->db->selectOne('SELECT * FROM crops WHERE id = :id', ['id' => (int) $input['crop_id']]);
        $season = $this->db->selectOne('SELECT * FROM seasons WHERE id = :id', ['id' => (int) $input['season_id']]);

        if ($crop === null || $season === null) {
            return $this->backWithErrors(
                url('batches/create'),
                ['crop_id' => 'Choose a crop and a season that both exist.'],
                $input
            );
        }

        $unit = trim((string) ($input['unit'] ?? ''));
        if ($unit === '') {
            $unit = (string) ($crop['primary_unit'] ?: 'kg');
        }

        // The opening quantity is only a claim; the authoritative total is
        // recalculated from the harvests that get added, so it starts at zero.
        $values = [
            'batch_code'       => Identifier::generate('batch'),
            'crop_id'          => (int) $input['crop_id'],
            'variety_id'       => ($input['variety_id'] ?? '') !== '' ? (int) $input['variety_id'] : null,
            'season_id'        => (int) $input['season_id'],
            'batch_date'       => (string) $input['batch_date'],
            'total_quantity'   => 0,
            'unit'             => $unit,
            'grade'            => trim((string) ($input['grade'] ?? '')) ?: null,
            'quality'          => trim((string) ($input['quality'] ?? '')) ?: null,
            'storage_location' => trim((string) ($input['storage_location'] ?? '')) ?: null,
            'contributor_count' => 0,
            'status'           => 'open',
            'notes'            => trim((string) ($input['notes'] ?? '')) ?: null,
            'created_by'       => $this->auth->id(),
        ];

        $batchId = $this->db->insert('harvest_batches', $values);

        Audit::log(
            'create',
            'batches',
            'harvest_batch',
            $batchId,
            $values['batch_code'],
            null,
            ['crop_id' => $values['crop_id'], 'season_id' => $values['season_id'], 'unit' => $values['unit']]
        );

        Flash::success(
            'Batch ' . $values['batch_code'] . ' created for ' . $crop['name'] . '. '
            . 'Add the harvests that make up the lot.'
        );

        return $this->redirect(url('batches/' . $values['batch_code']));
    }

    public function show(string $code): Response
    {
        $batch = $this->findByCodeOrFail($code);

        $items = $this->db->select(
            'SELECT hbi.id AS item_id, hbi.quantity AS item_quantity, hbi.unit AS item_unit,
                    hr.id AS harvest_id, hr.harvest_code, hr.harvest_date, hr.quality, hr.grade,
                    hr.quantity_harvested, hr.storage_location,
                    f.farmer_code, f.full_name, g.garden_code, g.name AS garden_name
             FROM harvest_batch_items hbi
             INNER JOIN harvest_records hr ON hr.id = hbi.harvest_id
             INNER JOIN farmers f ON f.id = hr.farmer_id
             LEFT JOIN gardens g ON g.id = hr.garden_id
             WHERE hbi.batch_id = :id
             ORDER BY hr.harvest_date ASC',
            ['id' => $batch['id']]
        );

        // Harvests of the same crop and season that are not yet in any batch.
        $candidates = $this->db->select(
            'SELECT hr.id, hr.harvest_code, hr.harvest_date, hr.quantity_harvested, hr.unit, hr.quality,
                    f.farmer_code, f.full_name
             FROM harvest_records hr
             INNER JOIN farmers f ON f.id = hr.farmer_id
             LEFT JOIN harvest_batch_items hbi ON hbi.harvest_id = hr.id
             WHERE hr.crop_id = :crop
               AND hr.season_id = :season
               AND hr.unit = :unit
               AND hr.status <> \'cancelled\'
               AND hbi.id IS NULL
             ORDER BY hr.harvest_date DESC
             LIMIT 100',
            ['crop' => $batch['crop_id'], 'season' => $batch['season_id'], 'unit' => $batch['unit']]
        );

        $sold = $this->db->select(
            'SELECT s.sale_code, s.sale_date, s.status AS sale_status, bi.quantity, bu.name AS buyer_name
             FROM sale_items bi
             INNER JOIN sales s ON s.id = bi.sale_id
             INNER JOIN buyers bu ON bu.id = s.buyer_id
             WHERE bi.batch_id = :id
             ORDER BY s.sale_date DESC',
            ['id' => $batch['id']]
        );

        $exports = $this->db->select(
            'SELECT export_code, destination_country, shipment_date, status FROM exports
             WHERE batch_id = :id ORDER BY shipment_date DESC',
            ['id' => $batch['id']]
        );

        return $this->render('batches/show', [
            'pageTitle'  => 'Batch ' . $code,
            'batch'      => $batch,
            'items'      => $items,
            'candidates' => $candidates,
            'sold'       => $sold,
            'exports'    => $exports,
            'canManage'  => $this->auth->can('batches.manage'),
            'frozen'     => in_array($batch['status'], ['closed', 'sold', 'exported'], true),
            'summary'    => $this->summary($batch),
        ]);
    }

    /**
     * Adds a harvest to the batch. The route carries the numeric batch id.
     */
    public function addItem(int $id): Response
    {
        $this->assertCsrf();

        $batch  = $this->findByIdOrFail($id);
        $code   = $batch['batch_code'];

        if ($this->isFrozen($batch)) {
            Flash::error('Batch ' . $code . ' is ' . $batch['status'] . ', so its contents can no longer change.');

            return $this->redirect(url('batches/' . $code));
        }

        $harvestId = $this->request->int('harvest_id');

        $harvest = $this->db->selectOne(
            'SELECT * FROM harvest_records WHERE id = :id',
            ['id' => $harvestId]
        );

        if ($harvest === null) {
            Flash::error('Choose a harvest to add.');

            return $this->redirect(url('batches/' . $code));
        }

        // Each reason a harvest cannot join this lot is reported separately, so
        // the user knows which rule to fix rather than just seeing a refusal.
        foreach ([
            ['crop_id', 'crop'],
            ['season_id', 'season'],
        ] as [$column, $label]) {
            if ((int) $harvest[$column] !== (int) $batch[$column]) {
                Flash::error('That harvest is from a different ' . $label . '. A batch holds one ' . $label . ' only.');

                return $this->redirect(url('batches/' . $code));
            }
        }

        if ((string) $harvest['unit'] !== (string) $batch['unit']) {
            Flash::error(
                'That harvest is measured in ' . $harvest['unit'] . ' but this batch uses ' . $batch['unit']
                . '. Convert it before batching so the lot total stays meaningful.'
            );

            return $this->redirect(url('batches/' . $code));
        }

        if ((string) $harvest['status'] === 'cancelled') {
            Flash::error('A cancelled harvest cannot be added to a batch.');

            return $this->redirect(url('batches/' . $code));
        }

        $existing = $this->db->selectOne(
            'SELECT b.batch_code FROM harvest_batch_items hbi
             INNER JOIN harvest_batches b ON b.id = hbi.batch_id
             WHERE hbi.harvest_id = :id LIMIT 1',
            ['id' => $harvestId]
        );

        if ($existing !== null) {
            Flash::error('Harvest ' . $harvest['harvest_code'] . ' is already in batch ' . $existing['batch_code'] . '.');

            return $this->redirect(url('batches/' . $code));
        }

        $this->db->transaction(function () use ($batch, $harvest): void {
            $this->db->insert('harvest_batch_items', [
                'batch_id'   => (int) $batch['id'],
                'harvest_id' => (int) $harvest['id'],
                'quantity'   => (float) $harvest['quantity_harvested'],
                'unit'       => (string) $harvest['unit'],
            ]);

            $this->db->update('harvest_records', ['status' => 'aggregated'], 'id = :id', ['id' => $harvest['id']]);

            $this->recalculate((int) $batch['id']);

            Audit::log(
                'batch_add',
                'batches',
                'harvest_batch',
                (int) $batch['id'],
                $batch['batch_code'],
                null,
                ['harvest_code' => $harvest['harvest_code'], 'quantity' => $harvest['quantity_harvested']]
            );
        });

        Flash::success('Harvest ' . $harvest['harvest_code'] . ' added to batch ' . $code . '.');

        return $this->redirect(url('batches/' . $code));
    }

    public function removeItem(int $id): Response
    {
        $this->assertCsrf();

        $batch = $this->findByIdOrFail($id);
        $code  = $batch['batch_code'];

        if ($this->isFrozen($batch)) {
            Flash::error('Batch ' . $code . ' is ' . $batch['status'] . ', so its contents can no longer change.');

            return $this->redirect(url('batches/' . $code));
        }

        $harvestId = $this->request->int('harvest_id');

        $item = $this->db->selectOne(
            'SELECT hbi.id, hr.harvest_code FROM harvest_batch_items hbi
             INNER JOIN harvest_records hr ON hr.id = hbi.harvest_id
             WHERE hbi.batch_id = :batch AND hbi.harvest_id = :harvest',
            ['batch' => $batch['id'], 'harvest' => $harvestId]
        );

        if ($item === null) {
            Flash::error('That harvest is not part of this batch.');

            return $this->redirect(url('batches/' . $code));
        }

        // Produce already sold or exported from this lot cannot be withdrawn
        // without breaking the chain that has been reported downstream.
        if ($this->hasCommittedQuantity((int) $batch['id'])) {
            Flash::error(
                'Part of this batch has already been sold or exported, so it cannot be reopened. '
                . 'Record a corrective sale instead.'
            );

            return $this->redirect(url('batches/' . $code));
        }

        $this->db->transaction(function () use ($batch, $harvestId, $item, $code): void {
            $this->db->delete('harvest_batch_items', 'id = :id', ['id' => (int) $item['id']]);

            // The harvest becomes available for another batch again.
            $this->db->update('harvest_records', ['status' => 'recorded'], 'id = :id', ['id' => $harvestId]);

            $this->recalculate((int) $batch['id']);

            Audit::log(
                'batch_remove',
                'batches',
                'harvest_batch',
                (int) $batch['id'],
                $code,
                null,
                ['harvest_code' => $item['harvest_code']]
            );
        });

        Flash::success('Harvest ' . $item['harvest_code'] . ' removed from batch ' . $code . '.');

        return $this->redirect(url('batches/' . $code));
    }

    /**
     * Closes the batch, freezing its contents.
     */
    public function close(string $code): Response
    {
        $this->assertCsrf();

        $batch = $this->findByCodeOrFail($code);

        if ($this->isFrozen($batch)) {
            Flash::warning('Batch ' . $code . ' is already ' . $batch['status'] . '.');

            return $this->redirect(url('batches/' . $code));
        }

        $count = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM harvest_batch_items WHERE batch_id = :id',
            ['id' => $batch['id']]
        );

        if ($count === 0) {
            Flash::error('Add at least one harvest before closing the batch.');

            return $this->redirect(url('batches/' . $code));
        }

        $this->recalculate((int) $batch['id']);

        $this->db->update('harvest_batches', ['status' => 'closed'], 'id = :id', ['id' => $batch['id']]);

        Audit::log('close', 'batches', 'harvest_batch', (int) $batch['id'], $code, $batch, ['status' => 'closed']);

        Flash::success('Batch ' . $code . ' closed. Its contents are now fixed and it can be sold or exported.');

        return $this->redirect(url('batches/' . $code));
    }

    /**
     * The printable provenance sheet for the batch.
     */
    public function trace(string $code): Response
    {
        $batch = $this->findByCodeOrFail($code);
        $chain = $this->traceability->forBatch((int) $batch['id']);

        if ($this->request->string('format') === 'csv') {
            return $this->csv(
                'batch-' . $batch['batch_code'] . '-origin.csv',
                [
                    'farmer_code', 'farmer_name', 'phone', 'garden_code', 'garden_name', 'acreage',
                    'latitude', 'longitude', 'district', 'planting_date', 'harvest_date', 'quantity',
                    'unit', 'quality', 'officer',
                ],
                $this->traceability->batchOriginRows((int) $batch['id'])
            );
        }

        return $this->render('batches/trace', [
            'pageTitle' => 'Traceability ' . $code,
            'batch'     => $batch,
            'chain'     => $chain,
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Recomputes the stored total and contributor count from the batch items.
     *
     * The total is always derived, never trusted from input, so a rounding or
     * editing mistake cannot leave a batch that disagrees with its contents.
     */
    private function recalculate(int $batchId): void
    {
        $totals = $this->db->selectOne(
            'SELECT COALESCE(SUM(quantity), 0) AS quantity, COUNT(*) AS lines,
                    COUNT(DISTINCT harvest_id) AS harvests
             FROM harvest_batch_items WHERE batch_id = :id',
            ['id' => $batchId]
        ) ?? ['quantity' => 0, 'lines' => 0, 'harvests' => 0];

        $contributors = (int) $this->db->scalar(
            'SELECT COUNT(DISTINCT hr.farmer_id)
             FROM harvest_batch_items hbi
             INNER JOIN harvest_records hr ON hr.id = hbi.harvest_id
             WHERE hbi.batch_id = :id',
            ['id' => $batchId]
        );

        // An empty batch is 'open'; a populated one is 'aggregated'. Both are
        // still editable, so neither should be called closed.
        $status = (int) $totals['harvests'] === 0 ? 'open' : 'aggregated';

        $this->db->update('harvest_batches', [
            'total_quantity'    => (float) $totals['quantity'],
            'contributor_count' => $contributors,
            'status'            => $status,
        ], 'id = :id', ['id' => $batchId]);
    }

    /**
     * True when any quantity in the batch has been sold or exported.
     */
    private function hasCommittedQuantity(int $batchId): bool
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM sale_items WHERE batch_id = :id',
            ['id' => $batchId]
        ) > 0
            || (int) $this->db->scalar(
                'SELECT COUNT(*) FROM exports WHERE batch_id = :id',
                ['id' => $batchId]
            ) > 0;
    }

    /**
 * True when the batch's contents are no longer editable.
 *
 * 'allocated' counts as frozen: stock has been committed to a buyer, so
 * recalculating the lot behind their back would misstate what they bought.
 */
    private function isFrozen(array $batch): bool
    {
        return in_array($batch['status'], ['allocated', 'closed', 'sold', 'exported'], true);
    }

    /**
     * @return array<string,mixed>
     */
    private function summary(array $batch): array
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS harvests,
                    COUNT(DISTINCT hr.farmer_id) AS farmers,
                    COALESCE(SUM(hr.acreage_harvested), 0) AS acreage,
                    MIN(hr.harvest_date) AS first_date,
                    MAX(hr.harvest_date) AS last_date,
                    COALESCE(SUM(IFNULL(hr.moisture_content, 0) * hbi.quantity), 0) / NULLIF(SUM(hbi.quantity), 0) AS weighted_moisture
             FROM harvest_batch_items hbi
             INNER JOIN harvest_records hr ON hr.id = hbi.harvest_id
             WHERE hbi.batch_id = :id',
            ['id' => $batch['id']]
        ) ?? [];

        return [
            'harvests'          => (int) ($row['harvests'] ?? 0),
            'farmers'           => (int) ($row['farmers'] ?? 0),
            'acreage'           => (float) ($row['acreage'] ?? 0),
            'first_date'        => $row['first_date'] ?? null,
            'last_date'         => $row['last_date'] ?? null,
            'weighted_moisture' => $row['weighted_moisture'] !== null ? (float) $row['weighted_moisture'] : null,
        ];
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function unitOptions(): array
    {
        return Form::enumOptions(
            "'kg','tonnes','crates','bags','bunches','items',''",
            'Use the crop default...'
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fields(): array
    {
        return [
            ['name' => 'crop_id', 'label' => 'Crop', 'type' => Form::SELECT, 'required' => true],
            ['name' => 'variety_id', 'label' => 'Variety', 'type' => Form::SELECT],
            ['name' => 'season_id', 'label' => 'Season', 'type' => Form::SELECT, 'required' => true],
            ['name' => 'batch_date', 'label' => 'Batch date', 'type' => Form::DATE, 'required' => true],
            ['name' => 'unit', 'label' => 'Unit', 'type' => Form::SELECT, 'required' => true],
            ['name' => 'total_quantity', 'label' => 'Opening quantity', 'type' => Form::NUMBER, 'min' => 0, 'step' => 0.001],
            ['name' => 'grade', 'label' => 'Grade', 'type' => Form::TEXT],
            ['name' => 'quality', 'label' => 'Quality', 'type' => Form::TEXT],
            ['name' => 'storage_location', 'label' => 'Storage location', 'type' => Form::TEXT],
            ['name' => 'notes', 'label' => 'Notes', 'type' => Form::TEXTAREA],
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
        $batch = $this->db->selectOne(
            'SELECT b.*, c.name AS crop_name, c.primary_unit AS crop_unit, c.is_perishable,
                    v.name AS variety_name, s.name AS season_name
             FROM harvest_batches b
             LEFT JOIN crops c ON c.id = b.crop_id
             LEFT JOIN crop_varieties v ON v.id = b.variety_id
             LEFT JOIN seasons s ON s.id = b.season_id
             WHERE b.batch_code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($batch === null) {
            throw new HttpException(404, 'No batch found with the ID ' . $code . '.');
        }

        return $batch;
    }

    private function findByIdOrFail(int $id): array
    {
        $batch = $this->db->selectOne(
            'SELECT b.*, c.name AS crop_name FROM harvest_batches b
             LEFT JOIN crops c ON c.id = b.crop_id
             WHERE b.id = :id LIMIT 1',
            ['id' => $id]
        );

        if ($batch === null) {
            throw new HttpException(404, 'That batch could not be found.');
        }

        return $batch;
    }
}
