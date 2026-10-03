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
 * Exports: consignments leaving the country, and the provenance they carry.
 *
 * An export is the last link in the chain the specification requires:
 *
 *   Export -> Sale -> Batch -> Harvest -> Garden -> Crop -> Farmer
 *
 * Everything a destination country asks for on a phytosanitary or quality
 * certificate is recorded here, and the moment a consignment is on its way the
 * underlying batch is flagged as exported so that lot can never be sold twice.
 */
final class ExportController extends Controller
{
    /** Export states that mean the consignment has physically left the store. */
    private const SHIPPED_STATUSES = ['in_transit', 'arrived', 'delivered'];

    private Lookups $lookups;
    private Traceability $traceability;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups      = new Lookups($db);
        $this->traceability = new Traceability($db);
    }

    /* ------------------------------------------------------------------ */
    /* Register                                                             */
    /* ------------------------------------------------------------------ */

    public function index(): Response
    {
        $params = $this->listParams();

        $where  = ['1 = 1'];
        $values = [];

        if ($params['search'] !== '') {
            $where[]      = '(e.export_code LIKE :s OR e.destination_country LIKE :s OR e.certificate_no LIKE :s
                              OR e.quality_certificate_no LIKE :s OR e.phytosanitary_no LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        foreach (['status' => 'e.status', 'buyer_id' => 'e.buyer_id'] as $key => $column) {
            if (($params['filters'][$key] ?? '') !== '') {
                $where[]      = $column . ' = :' . $key;
                $values[$key] = $params['filters'][$key];
            }
        }

        $dateFrom = $this->request->date('date_from');
        if ($dateFrom !== null) {
            $where[]              = 'e.shipment_date >= :date_from';
            $values['date_from'] = $dateFrom;
        }

        $dateTo = $this->request->date('date_to');
        if ($dateTo !== null) {
            $where[]            = 'e.shipment_date <= :date_to';
            $values['date_to'] = $dateTo;
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $totals = $this->db->selectOne(
            'SELECT COUNT(*) AS consignments, COALESCE(SUM(e.quantity), 0) AS quantity
             FROM exports e' . $whereSql,
            $values
        ) ?? ['consignments' => 0, 'quantity' => 0];

        $paginator = new Paginator((int) $totals['consignments'], $params['page'], $params['per_page']);

        $sortable = ['export_code', 'destination_country', 'quantity', 'shipment_date', 'eta', 'status', 'created_at'];
        $column   = in_array($params['sort'], $sortable, true) ? $params['sort'] : 'shipment_date';
        $order    = $params['direction'] === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->db->select(
            'SELECT e.export_code, e.sale_id, e.batch_id, e.destination_country, e.quantity, e.unit,
                    e.shipment_date, e.eta, e.certificate_no, e.status, e.created_at,
                    s.sale_code, b.name AS buyer_name, bt.batch_code
             FROM exports e
             LEFT JOIN sales s ON s.id = e.sale_id
             LEFT JOIN buyers b ON b.id = e.buyer_id
             LEFT JOIN harvest_batches bt ON bt.id = e.batch_id'
            . $whereSql
            . ' ORDER BY e.' . $column . ' ' . $order
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('exports/index', [
            'pageTitle' => 'Exports',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $column,
            'direction' => $params['direction'],
            'totals'    => $totals,
            'filters'   => [
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions(
                    "'draft','in_transit','arrived','delivered','cancelled'",
                    'All statuses'
                )],
                ['key' => 'buyer_id', 'label' => 'Buyer', 'options' => $this->buyerOptions('All buyers')],
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Create                                                               */
    /* ------------------------------------------------------------------ */

    public function create(): Response
    {
        return $this->render('exports/form', [
            'pageTitle' => 'Record export',
            'record'    => ['shipment_date' => date('Y-m-d')],
            'sections'  => $this->sections(),
            'exportCode' => '',
        ]);
    }

    public function store(): Response
    {
        $this->assertCsrf();

        $sections = $this->sections();
        $fields   = array_merge(...array_column($sections, 'fields'));
        $input    = $this->request->all();

        $validator = Form::validate($fields, $input);
        $validator->dateAfter('eta', 'shipment_date', 'Estimated arrival date', 'shipment date');

        if ($validator->fails()) {
            return $this->backWithErrors(url('exports/create'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, [
            'sale_id', 'batch_id', 'buyer_id', 'quantity', 'unit', 'shipment_date', 'eta',
            'certificate_no', 'quality_certificate_no', 'phytosanitary_no', 'notes',
        ]);

        // A consignment raised against a sale belongs to that sale's buyer, so
        // the two halves of the record can never disagree.
        if (!empty($values['sale_id']) && empty($values['buyer_id'])) {
            $buyerId = $this->db->scalar('SELECT buyer_id FROM sales WHERE id = :id', ['id' => $values['sale_id']]);

            if ($buyerId === null) {
                return $this->backWithErrors(url('exports/create'), ['sale_id' => 'That sale could not be found.'], $input);
            }

            $values['buyer_id'] = (int) $buyerId;
        }

        if (empty($values['batch_id']) && !empty($values['sale_id'])) {
            $batchId = $this->db->scalar(
                'SELECT batch_id FROM sale_items WHERE sale_id = :sale AND batch_id IS NOT NULL ORDER BY id LIMIT 1',
                ['sale' => $values['sale_id']]
            );

            $values['batch_id'] = $batchId === null ? null : (int) $batchId;
        }

        if (!empty($values['batch_id']) && empty($values['unit'])) {
            $values['unit'] = $this->db->scalar('SELECT unit FROM harvest_batches WHERE id = :id', ['id' => $values['batch_id']]);
        }

        try {
            $exportId = $this->db->transaction(function () use ($values): int {
                $values['export_code'] = Identifier::generate('export', $this->asDate($values['shipment_date'] ?? null));
                $values['created_by']  = $this->auth->id();

                $exportId = $this->db->insert('exports', $values);

                $this->flagBatchAsExported($exportId, $values);

                return $exportId;
            });
        } catch (HttpException $exception) {
            return $this->backWithErrors(url('exports/create'), ['batch_id' => $exception->getMessage()], $input);
        }

        $export = $this->findByCodeOrFail((string) $this->db->scalar('SELECT export_code FROM exports WHERE id = :id', ['id' => $exportId]));

        Audit::log('create', 'exports', 'export', $exportId, $export['export_code'], null, [
            'destination_country' => $export['destination_country'],
            'buyer_id'            => $export['buyer_id'],
            'batch_id'            => $export['batch_id'],
            'quantity'            => $export['quantity'],
            'unit'                => $export['unit'],
            'shipment_date'       => $export['shipment_date'],
            'status'              => $export['status'],
        ]);

        Flash::success('Export ' . $export['export_code'] . ' recorded for ' . $export['destination_country'] . '.');

        return $this->redirect(url('exports/' . $export['export_code']));
    }

    /* ------------------------------------------------------------------ */
    /* Detail                                                               */
    /* ------------------------------------------------------------------ */

    public function show(string $code): Response
    {
        $export = $this->findByCodeOrFail($code);
        $chain  = $this->traceability->forExport((int) $export['id']);

        if ($chain === null) {
            throw new HttpException(404, 'No export found with the ID ' . $code . '.');
        }

        $origin = $chain['chain'];

        return $this->render('exports/show', [
            'pageTitle'  => 'Export ' . $code,
            'export'     => $export,
            'chain'      => $origin,
            'batch'      => $origin['batch'] ?? [],
            'contributors' => $origin['contributors'] ?? [],
            'summary'    => $origin['summary'] ?? [],
        ]);
    }

    /**
     * The dedicated Export -> Shipment -> Batch -> Harvest -> Garden -> Crop
     * -> Farmer chain, rendered as one card per hop with the contributing
     * farmers at the bottom.
     */
    public function trace(string $code): Response
    {
        $export = $this->findByCodeOrFail($code);
        $chain  = $this->traceability->forExport((int) $export['id']);

        if ($chain === null) {
            throw new HttpException(404, 'No export found with the ID ' . $code . '.');
        }

        $origin  = $chain['chain'];
        $harvest = null;

        $contributors = $origin['contributors'] ?? [];

        if ($contributors !== []) {
            $harvest = $this->db->selectOne(
                'SELECT hr.id, hr.harvest_code, hr.harvest_date, hr.quantity_harvested, hr.unit,
                        hr.quality, hr.grade, hr.moisture_content
                 FROM harvest_records hr WHERE hr.id = :id LIMIT 1',
                ['id' => $contributors[0]['harvest_id']]
            );
        }

        $sale = null;

        if (!empty($export['sale_id'])) {
            $sale = $this->db->selectOne(
                'SELECT s.id, s.sale_code, s.sale_date, s.total_value, s.currency, s.status,
                        b.name AS buyer_name, b.buyer_type, b.country AS buyer_country
                 FROM sales s
                 INNER JOIN buyers b ON b.id = s.buyer_id
                 WHERE s.id = :id LIMIT 1',
                ['id' => $export['sale_id']]
            );
        }

        return $this->render('exports/trace', [
            'pageTitle' => 'Traceability ' . $code,
            'export'    => $export,
            'sale'      => $sale,
            'batch'     => $origin['batch'] ?? [],
            'harvest'   => $harvest,
            'contributors' => $contributors,
            'summary'   => $origin['summary'] ?? [],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Update                                                               */
    /* ------------------------------------------------------------------ */

    public function update(string $code): Response
    {
        $this->assertCsrf();

        $export   = $this->findByCodeOrFail($code);
        $sections = $this->sections();
        $fields   = array_merge(...array_column($sections, 'fields'));
        $input    = $this->request->all();

        $validator = Form::validate($fields, $input);
        $validator->dateAfter('eta', 'shipment_date', 'Estimated arrival date', 'shipment date');

        if ($validator->fails()) {
            return $this->backWithErrors(url('exports/' . $code), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, [
            'sale_id', 'batch_id', 'buyer_id', 'quantity', 'unit', 'shipment_date', 'eta',
            'certificate_no', 'quality_certificate_no', 'phytosanitary_no', 'notes',
        ]);

        if (!empty($values['sale_id']) && empty($values['buyer_id'])) {
            $buyerId = $this->db->scalar('SELECT buyer_id FROM sales WHERE id = :id', ['id' => $values['sale_id']]);

            if ($buyerId === null) {
                return $this->backWithErrors(url('exports/' . $code), ['sale_id' => 'That sale could not be found.'], $input);
            }

            $values['buyer_id'] = (int) $buyerId;
        }

        if (!empty($values['batch_id']) && empty($values['unit'])) {
            $values['unit'] = $this->db->scalar('SELECT unit FROM harvest_batches WHERE id = :id', ['id' => $values['batch_id']]);
        }

        $before = $export;

        $this->db->transaction(function () use ($export, $values): void {
            $this->db->update('exports', $values, 'id = :id', ['id' => $export['id']]);

            $this->flagBatchAsExported((int) $export['id'], $values);
        });

        Audit::log('update', 'exports', 'export', (int) $export['id'], $export['export_code'], $before, $values);

        Flash::success('Export ' . $export['export_code'] . ' updated.');

        return $this->redirect(url('exports/' . $export['export_code']));
    }

    /* ------------------------------------------------------------------ */
    /* Batch state                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Marks the underlying batch as exported once the consignment has actually
     * left, so the lot cannot be committed to a second buyer.
     *
     * @param array<string,mixed> $values
     */
    private function flagBatchAsExported(int $exportId, array $values): void
    {
        if (!in_array((string) ($values['status'] ?? ''), self::SHIPPED_STATUSES, true)) {
            return;
        }

        $batchId = $values['batch_id'] ?? null;

        if (empty($batchId) && !empty($values['sale_id'])) {
            $batchId = $this->db->scalar(
                'SELECT batch_id FROM sale_items WHERE sale_id = :sale AND batch_id IS NOT NULL ORDER BY id LIMIT 1',
                ['sale' => $values['sale_id']]
            );
        }

        if (empty($batchId)) {
            return;
        }

        $batch = $this->db->selectOne('SELECT batch_code, status FROM harvest_batches WHERE id = :id', ['id' => (int) $batchId]);

        if ($batch === null || (string) $batch['status'] === 'exported') {
            return;
        }

        $this->db->update('harvest_batches', ['status' => 'exported'], 'id = :id', ['id' => (int) $batchId]);

        Audit::log('export', 'batches', 'harvest_batch', (int) $batchId, (string) $batch['batch_code'], [
            'status' => $batch['status'],
        ], [
            'status'       => 'exported',
            'export_id'    => $exportId,
            'export_status'=> $values['status'] ?? null,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Supporting data                                                      */
    /* ------------------------------------------------------------------ */

    private function asDate(?string $date): ?\DateTimeInterface
    {
        if ($date === null || $date === '') {
            return null;
        }

        $parsed = \DateTime::createFromFormat('Y-m-d', $date);

        return $parsed === false ? null : $parsed;
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function buyerOptions(string $placeholder = 'Select buyer...'): array
    {
        return Form::options(
            $this->db->select(
                "SELECT id, name FROM buyers WHERE status = 'active' AND deleted_at IS NULL ORDER BY name LIMIT 500"
            ),
            'id',
            'name',
            $placeholder
        );
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function saleOptions(): array
    {
        $rows = $this->db->select(
            'SELECT s.id, s.sale_code, s.sale_date, s.currency, s.total_value, b.name AS buyer_name
             FROM sales s
             INNER JOIN buyers b ON b.id = s.buyer_id
             WHERE s.status <> \'cancelled\'
             ORDER BY s.sale_date DESC, s.id DESC
             LIMIT 400'
        );

        $options = [['value' => '', 'label' => 'Not linked to a sale']];

        foreach ($rows as $row) {
            $options[] = [
                'value' => (string) $row['id'],
                'label' => sprintf(
                    '%s — %s (%s, %s)',
                    (string) $row['sale_code'],
                    (string) $row['buyer_name'],
                    format_date((string) $row['sale_date']),
                    format_money((float) $row['total_value'], (string) $row['currency'])
                ),
            ];
        }

        return $options;
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function batchOptions(): array
    {
        $rows = $this->db->select(
            "SELECT b.id, b.batch_code, b.batch_date, b.total_quantity, b.unit, b.status, c.name AS crop_name
             FROM harvest_batches b
             INNER JOIN crops c ON c.id = b.crop_id
             WHERE b.status NOT IN ('sold', 'exported')
             ORDER BY b.batch_date DESC, b.batch_code
             LIMIT 400"
        );

        $options = [['value' => '', 'label' => 'Inherited from the sale']];

        foreach ($rows as $row) {
            $options[] = [
                'value' => (string) $row['id'],
                'label' => sprintf(
                    '%s — %s (%s %s, %s)',
                    (string) $row['batch_code'],
                    (string) $row['crop_name'],
                    format_number((float) $row['total_quantity']),
                    (string) $row['unit'],
                    (string) $row['status']
                ),
            ];
        }

        return $options;
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'title'  => 'Consignment',
                'fields' => [
                    ['name' => 'export_code', 'label' => 'Export ID', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued automatically when the export is saved.'],
                    ['name' => 'sale_id', 'label' => 'Sale', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->saleOptions(), 'table' => 'sales',
                     'help' => 'The buyer, batch and unit are inherited from the sale.'],
                    ['name' => 'batch_id', 'label' => 'Batch', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->batchOptions(), 'table' => 'harvest_batches'],
                    ['name' => 'buyer_id', 'label' => 'Buyer', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->buyerOptions('Taken from the sale'), 'table' => 'buyers'],
                    ['name' => 'destination_country', 'label' => 'Destination country', 'type' => Form::TEXT,
                     'required' => true, 'colspan' => 6, 'maxlength' => 80],
                    ['name' => 'quantity', 'label' => 'Quantity', 'type' => Form::NUMBER, 'colspan' => 6,
                     'min' => 0.001, 'step' => 0.001],
                    ['name' => 'unit', 'label' => 'Unit', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions("'kg','tonnes','crates','bags','bunches','items',''", 'Use the batch unit...')],
                    ['name' => 'shipment_date', 'label' => 'Shipment date', 'type' => Form::DATE, 'colspan' => 6,
                     'default' => date('Y-m-d')],
                    ['name' => 'eta', 'label' => 'Estimated arrival', 'type' => Form::DATE, 'colspan' => 6,
                     'help' => 'Cannot be earlier than the shipment date.'],
                    ['name' => 'status', 'label' => 'Status', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions("'draft','in_transit','arrived','delivered','cancelled'", ''),
                     'help' => 'Once the consignment is in transit, arrived or delivered the batch is flagged as exported.'],
                    ['name' => 'notes', 'label' => 'Notes', 'type' => Form::TEXTAREA, 'colspan' => 12],
                ],
            ],
            [
                'title'  => 'Certificates',
                'fields' => [
                    ['name' => 'certificate_no', 'label' => 'Certificate number', 'type' => Form::TEXT, 'colspan' => 6],
                    ['name' => 'quality_certificate_no', 'label' => 'Quality certificate number', 'type' => Form::TEXT,
                     'colspan' => 6],
                    ['name' => 'phytosanitary_no', 'label' => 'Phytosanitary number', 'type' => Form::TEXT, 'colspan' => 12,
                     'help' => 'The number printed on the phytosanitary certificate issued by the authorities.'],
                ],
            ],
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
        $export = $this->db->selectOne(
            'SELECT e.*, b.buyer_code, b.name AS buyer_name, b.buyer_type, b.country AS buyer_country,
                    b.contact_person, s.sale_code, s.sale_date, s.total_value AS sale_value, s.currency AS sale_currency,
                    bt.batch_code, bt.grade, bt.quality, bt.storage_location, c.name AS crop_name,
                    v.name AS variety_name, se.season_code, u.username AS created_by_name
             FROM exports e
             LEFT JOIN buyers b ON b.id = e.buyer_id
             LEFT JOIN sales s ON s.id = e.sale_id
             LEFT JOIN harvest_batches bt ON bt.id = e.batch_id
             LEFT JOIN crops c ON c.id = bt.crop_id
             LEFT JOIN crop_varieties v ON v.id = bt.variety_id
             LEFT JOIN seasons se ON se.id = bt.season_id
             LEFT JOIN users u ON u.id = e.created_by
             WHERE e.export_code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($export === null) {
            throw new HttpException(404, 'No export found with the ID ' . $code . '.');
        }

        return $export;
    }
}