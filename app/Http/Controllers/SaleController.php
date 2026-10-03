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
 * Sales and deliveries: the commercial record of aggregated produce leaving
 * the cooperative.
 *
 * A sale is only ever a claim on tonnage that physically exists, so every line
 * is written inside one transaction that takes a row lock on each harvest
 * batch before it accepts a quantity. Two rules follow from that and are
 * enforced on every create, edit and cancellation:
 *
 *   1. A batch may only be sold for its own crop, and never once it has been
 *      sold or exported already.
 *   2. The quantity sold may not exceed what is still uncommitted, so two
 *      officers selling the same lot at the same time cannot both win.
 *
 * Deliveries hang off the sale rather than replacing it, and a sale that has
 * been dispatched is never deleted: it is cancelled, which releases the
 * committed tonnage and leaves the audit trail intact.
 */
final class SaleController extends Controller
{
    private Lookups $lookups;
    private Traceability $traceability;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups      = new Lookups($db);
        $this->traceability = new Traceability($db);
    }

    /* ------------------------------------------------------------------ */
    /* Sales register                                                       */
    /* ------------------------------------------------------------------ */

    public function index(): Response
    {
        $params = $this->listParams();

        $where  = ['1 = 1'];
        $values = [];

        if ($params['search'] !== '') {
            $where[]      = '(s.sale_code LIKE :s OR b.name LIKE :s OR s.notes LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        foreach (['buyer_id' => 's.buyer_id', 'status' => 's.status'] as $key => $column) {
            if (($params['filters'][$key] ?? '') !== '') {
                $where[]      = $column . ' = :' . $key;
                $values[$key] = $params['filters'][$key];
            }
        }

        $paymentStatus = $this->request->string('payment_status');
        if ($paymentStatus !== '') {
            $where[]                = 's.payment_status = :payment_status';
            $values['payment_status'] = $paymentStatus;
        }

        $dateFrom = $this->request->date('date_from');
        if ($dateFrom !== null) {
            $where[]              = 's.sale_date >= :date_from';
            $values['date_from'] = $dateFrom;
        }

        $dateTo = $this->request->date('date_to');
        if ($dateTo !== null) {
            $where[]            = 's.sale_date <= :date_to';
            $values['date_to'] = $dateTo;
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $totals = $this->db->selectOne(
            'SELECT COUNT(*) AS sales, COALESCE(SUM(s.total_value), 0) AS value,
                    COALESCE(SUM(s.amount_paid), 0) AS received
             FROM sales s
             INNER JOIN buyers b ON b.id = s.buyer_id' . $whereSql,
            $values
        ) ?? ['sales' => 0, 'value' => 0, 'received' => 0];

        $paginator = new Paginator((int) $totals['sales'], $params['page'], $params['per_page']);

        $sortable = ['sale_code', 'sale_date', 'total_value', 'amount_paid', 'created_at'];
        $column   = in_array($params['sort'], $sortable, true) ? $params['sort'] : 'sale_date';
        $order    = $params['direction'] === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->db->select(
            'SELECT s.sale_code, s.sale_date, s.total_value, s.amount_paid, s.currency,
                    s.payment_status, s.status, s.payment_due_date,
                    b.name AS buyer_name, b.buyer_type,
                    m.name AS market_name,
                    (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS item_count,
                    (SELECT COALESCE(SUM(si.quantity), 0) FROM sale_items si WHERE si.sale_id = s.id) AS quantity,
                    (SELECT COUNT(*) FROM deliveries d WHERE d.sale_id = s.id) AS delivery_count,
                    (SELECT d.status FROM deliveries d WHERE d.sale_id = s.id ORDER BY d.id DESC LIMIT 1) AS delivery_status
             FROM sales s
             INNER JOIN buyers b ON b.id = s.buyer_id
             LEFT JOIN markets m ON m.id = s.market_id'
            . $whereSql
            . ' ORDER BY s.' . $column . ' ' . $order
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('sales/index', [
            'pageTitle' => 'Sales',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $column,
            'direction' => $params['direction'],
            'totals'    => $totals,
            'filters'   => [
                ['key' => 'status', 'label' => 'Sale status', 'options' => Form::enumOptions(
                    "'draft','confirmed','fulfilled','cancelled'",
                    'All sale statuses'
                )],
                ['key' => 'payment_status', 'label' => 'Payment', 'options' => Form::enumOptions(
                    "'unpaid','partial','paid','overdue'",
                    'All payment statuses'
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
        return $this->render('sales/form', [
            'pageTitle' => 'Record sale',
            'record'    => ['sale_date' => date('Y-m-d')],
            'sections'  => $this->sections(),
            'crops'     => $this->lookups->cropOptions('Select crop...'),
            'batches'   => $this->batchOptions(),
            'items'     => [],
            'saleCode'  => '',
            'locked'    => false,
        ]);
    }

    public function store(): Response
    {
        $this->assertCsrf();

        $sections = $this->sections();
        $fields   = array_merge(...array_column($sections, 'fields'));
        $input    = $this->request->all();

        $validator = Form::validate($fields, $input);

        if ($validator->fails()) {
            return $this->backWithErrors(url('sales/create'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, ['market_id', 'payment_due_date', 'notes']);
        $items  = $this->submittedItems($input);

        if ($items === []) {
            return $this->backWithErrors(
                url('sales/create'),
                ['items' => 'Add at least one line: choose the batch being sold and how much of it goes to this buyer.'],
                $input
            );
        }

        try {
            $saleId = $this->db->transaction(function () use ($values, $items): int {
                $values['sale_code']      = Identifier::generate('sale', $this->asDate($values['sale_date'] ?? null));
                $values['created_by']     = $this->auth->id();
                $values['total_value']    = 0.00;
                $values['amount_paid']    = 0.00;
                $values['payment_status'] = 'unpaid';

                $saleId = $this->db->insert('sales', $values);

                $this->db->update(
                    'sales',
                    ['total_value' => $this->applyItems($saleId, $items)],
                    'id = :id',
                    ['id' => $saleId]
                );

                return $saleId;
            });
        } catch (HttpException $exception) {
            return $this->backWithErrors(url('sales/create'), ['items' => $exception->getMessage()], $input);
        }

        $sale = $this->findByCodeOrFail((string) $this->db->scalar('SELECT sale_code FROM sales WHERE id = :id', ['id' => $saleId]));

        Audit::log('create', 'sales', 'sale', $saleId, $sale['sale_code'], null, [
            'buyer_id'   => $sale['buyer_id'],
            'sale_date'  => $sale['sale_date'],
            'status'     => $sale['status'],
            'total_value'=> $sale['total_value'],
            'currency'   => $sale['currency'],
            'line_count' => count($items),
        ]);

        Flash::success('Sale ' . $sale['sale_code'] . ' recorded for ' . $sale['buyer_name'] . '.');

        return $this->redirect(url('sales/' . $sale['sale_code']));
    }

    /* ------------------------------------------------------------------ */
    /* Detail                                                               */
    /* ------------------------------------------------------------------ */

    public function show(string $code): Response
    {
        $sale  = $this->findByCodeOrFail($code);
        $trace = $this->traceability->forSale((int) $sale['id']);

        if ($trace === null) {
            throw new HttpException(404, 'No sale found with the ID ' . $code . '.');
        }

        $deliveries = $this->db->select(
            'SELECT d.id, d.delivery_code, d.dispatch_date, d.expected_date, d.delivered_date,
                    d.delivery_location, d.status, d.transporter_name, d.vehicle_number, d.tracking_number
             FROM deliveries d WHERE d.sale_id = :sale ORDER BY d.id DESC',
            ['sale' => $sale['id']]
        );

        $exports = $this->db->select(
            'SELECT e.export_code, e.destination_country, e.shipment_date, e.eta, e.status, e.quantity, e.unit
             FROM exports e WHERE e.sale_id = :sale ORDER BY e.id DESC',
            ['sale' => $sale['id']]
        );

        $farmerCount = 0;
        foreach ($trace['items'] as $item) {
            $summary = $item['origin']['summary'] ?? [];

            if (is_array($summary)) {
                $farmerCount += (int) ($summary['farmer_count'] ?? 0);
            }
        }

        return $this->render('sales/show', [
            'pageTitle'    => 'Sale ' . $code,
            'sale'         => $sale,
            'items'        => $trace['items'],
            'deliveries'   => $deliveries,
            'exports'      => $exports,
            'farmerCount'  => $farmerCount,
            'outstanding'  => round((float) $sale['total_value'] - (float) $sale['amount_paid'], 2),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Edit                                                                 */
    /* ------------------------------------------------------------------ */

    public function edit(string $code): Response
    {
        $sale = $this->findByCodeOrFail($code);

        $items = $this->db->select(
            'SELECT si.id, si.batch_id, si.crop_id, si.quantity, si.unit, si.unit_price, si.total_value,
                    c.name AS crop_name, bt.batch_code
             FROM sale_items si
             INNER JOIN crops c ON c.id = si.crop_id
             LEFT JOIN harvest_batches bt ON bt.id = si.batch_id
             WHERE si.sale_id = :sale ORDER BY si.id',
            ['sale' => $sale['id']]
        );

        return $this->render('sales/form', [
            'pageTitle' => 'Edit ' . $sale['sale_code'],
            'record'    => $sale,
            'sections'  => $this->sections(),
            'crops'     => $this->lookups->cropOptions('Select crop...'),
            'batches'   => $this->batchOptions((int) $sale['id']),
            'items'     => $items,
            'saleCode'  => (string) $sale['sale_code'],
            'locked'    => (string) $sale['status'] === 'fulfilled',
        ]);
    }

    public function update(string $code): Response
    {
        $this->assertCsrf();

        $sale     = $this->findByCodeOrFail($code);
        $input    = $this->request->all();
        $sections = $this->sections();
        $fields   = array_merge(...array_column($sections, 'fields'));

        $validator = Form::validate($fields, $input);

        if ($validator->fails()) {
            return $this->backWithErrors(url('sales/' . $code . '/edit'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, ['market_id', 'payment_due_date', 'notes']);
        $items  = $this->submittedItems($input);

        // A fulfilled sale has already left the store room against a dispatch,
        // so its lines are frozen: the quantity a buyer is waiting on cannot
        // quietly change.
        if ((string) $sale['status'] === 'fulfilled' && $items !== []) {
            return $this->backWithErrors(
                url('sales/' . $code . '/edit'),
                ['items' => 'This sale is fulfilled, so its lines can no longer be changed. Record a corrective sale instead.'],
                $input
            );
        }

        if ($items === []) {
            return $this->backWithErrors(
                url('sales/' . $code . '/edit'),
                ['items' => 'A sale must keep at least one line.'],
                $input
            );
        }

        $before = $sale;

        try {
            $this->db->transaction(function () use ($sale, $values, $items): void {
                $this->db->update('sales', $values, 'id = :id', ['id' => $sale['id']]);

                $this->db->update(
                    'sales',
                    ['total_value' => $this->applyItems((int) $sale['id'], $items)],
                    'id = :id',
                    ['id' => $sale['id']]
                );
            });
        } catch (HttpException $exception) {
            return $this->backWithErrors(url('sales/' . $code . '/edit'), ['items' => $exception->getMessage()], $input);
        }

        $after = $this->findByCodeOrFail($code);

        Audit::log('update', 'sales', 'sale', (int) $sale['id'], $sale['sale_code'], $before, [
            'buyer_id'    => $after['buyer_id'],
            'sale_date'   => $after['sale_date'],
            'status'      => $after['status'],
            'total_value' => $after['total_value'],
            'line_count'  => count($items),
        ]);

        Flash::success('Sale ' . $sale['sale_code'] . ' updated.');

        return $this->redirect(url('sales/' . $sale['sale_code']));
    }

    /* ------------------------------------------------------------------ */
    /* Payment                                                              */
    /* ------------------------------------------------------------------ */

    public function recordPayment(string $code): Response
    {
        $this->assertCsrf();

        $sale = $this->findByCodeOrFail($code);

        if ((string) $sale['status'] === 'cancelled') {
            Flash::error('Sale ' . $code . ' was cancelled, so no payment can be recorded against it.');

            return $this->redirect(url('sales/' . $code));
        }

        $raw = $this->request->string('amount');

        if ($raw === '' || !is_numeric($raw)) {
            Flash::error('Enter the amount received as a number.');

            return $this->redirect(url('sales/' . $code));
        }

        $amount = round((float) $raw, 2);

        if ($amount <= 0.0) {
            Flash::error('The amount received must be greater than zero.');

            return $this->redirect(url('sales/' . $code));
        }

        $outstanding = round((float) $sale['total_value'] - (float) $sale['amount_paid'], 2);

        if ($amount - $outstanding > 0.005) {
            Flash::error(
                'That is more than the ' . format_money($outstanding, (string) $sale['currency'])
                . ' still outstanding on sale ' . $code . '.'
            );

            return $this->redirect(url('sales/' . $code));
        }

        $paid     = round((float) $sale['amount_paid'] + $amount, 2);
        $remainder = round((float) $sale['total_value'] - $paid, 2);

        $status = match (true) {
            $remainder <= 0.005  => 'paid',
            $paid > 0.0          => 'partial',
            default              => 'unpaid',
        };

        $this->db->update(
            'sales',
            ['amount_paid' => $paid, 'payment_status' => $status],
            'id = :id',
            ['id' => $sale['id']]
        );

        Audit::log('payment', 'sales', 'sale', (int) $sale['id'], $sale['sale_code'], [
            'amount_paid'    => $sale['amount_paid'],
            'payment_status' => $sale['payment_status'],
        ], [
            'amount_received'=> $amount,
            'amount_paid'    => $paid,
            'payment_status' => $status,
        ]);

        Flash::success(
            'Payment of ' . format_money($amount, (string) $sale['currency']) . ' recorded on sale ' . $code . '. '
            . ($status === 'paid'
                ? 'The sale is now fully paid.'
                : format_money($remainder, (string) $sale['currency']) . ' is still outstanding.')
        );

        return $this->redirect(url('sales/' . $code));
    }

    /* ------------------------------------------------------------------ */
    /* Dispatch                                                             */
    /* ------------------------------------------------------------------ */

    public function dispatch(string $code): Response
    {
        $this->assertCsrf();

        $sale = $this->findByCodeOrFail($code);

        if ((string) $sale['status'] === 'cancelled') {
            Flash::error('Sale ' . $code . ' was cancelled, so there is nothing to dispatch.');

            return $this->redirect(url('sales/' . $code));
        }

        if ((string) $sale['status'] === 'draft') {
            Flash::warning('Confirm sale ' . $code . ' before dispatching it.');

            return $this->redirect(url('sales/' . $code . '/edit'));
        }

        $dispatchDate = $this->request->date('dispatch_date');
        $expectedDate = $this->request->date('expected_date');

        if ($dispatchDate !== null && $expectedDate !== null && $expectedDate < $dispatchDate) {
            Flash::error('The expected date cannot be earlier than the dispatch date.');

            return $this->redirect(url('sales/' . $code));
        }

        $values = [
            'sale_id'           => (int) $sale['id'],
            'dispatch_date'     => $dispatchDate,
            'expected_date'     => $expectedDate,
            'delivered_date'    => null,
            'delivery_location' => $this->request->string('delivery_location') ?: null,
            'status'            => $dispatchDate === null ? 'pending' : 'in_transit',
            'transporter_name'  => $this->request->string('transporter_name') ?: null,
            'vehicle_number'    => $this->request->string('vehicle_number') ?: null,
            'tracking_number'   => $this->request->string('tracking_number') ?: null,
            'notes'             => $this->request->string('notes') ?: null,
        ];

        $deliveryId = $this->db->transaction(function () use ($values, $dispatchDate): int {
            $data = $values;
            $data['delivery_code'] = Identifier::generate('delivery', $this->asDate($dispatchDate));

            $deliveryId = $this->db->insert('deliveries', $data);

            $this->db->update('sales', ['status' => 'fulfilled'], 'id = :id', ['id' => $values['sale_id']]);

            return $deliveryId;
        });

        $deliveryCode = (string) $this->db->scalar('SELECT delivery_code FROM deliveries WHERE id = :id', ['id' => $deliveryId]);

        Audit::log('dispatch', 'sales', 'sale', (int) $sale['id'], $sale['sale_code'], ['status' => $sale['status']], [
            'delivery_code' => $deliveryCode,
            'dispatch_date' => $dispatchDate,
            'status'        => $values['status'],
        ]);

        Flash::success(
            'Delivery ' . $deliveryCode . ' raised for sale ' . $code . '. The sale is now fulfilled.'
        );

        return $this->redirect(url('sales/' . $code));
    }

    /* ------------------------------------------------------------------ */
    /* Cancellation                                                         */
    /* ------------------------------------------------------------------ */

    public function destroy(string $code): Response
    {
        $this->assertCsrf();

        $sale = $this->findByCodeOrFail($code);

        $deliveries = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM deliveries WHERE sale_id = :sale',
            ['sale' => $sale['id']]
        );

        if ($deliveries > 0) {
            Flash::error(
                'Sale ' . $code . ' already has ' . $deliveries . ' delivery record(s) against it. '
                . 'Cancel the delivery first so the consignment history stays readable.'
            );

            return $this->redirect(url('sales/' . $code));
        }

        if ((string) $sale['status'] === 'cancelled') {
            Flash::warning('Sale ' . $code . ' is already cancelled.');

            return $this->redirect(url('sales/' . $code));
        }

        $batchIds = $this->db->column(
            'SELECT DISTINCT batch_id FROM sale_items WHERE sale_id = :sale AND batch_id IS NOT NULL',
            ['sale' => $sale['id']]
        );

        $this->db->transaction(function () use ($sale, $batchIds): void {
            $this->db->update('sales', ['status' => 'cancelled'], 'id = :id', ['id' => $sale['id']]);

            foreach ($batchIds as $batchId) {
                $this->refreshBatch((int) $batchId);
            }
        });

        Audit::log('cancel', 'sales', 'sale', (int) $sale['id'], $sale['sale_code'], [
            'status'      => $sale['status'],
            'total_value' => $sale['total_value'],
        ], [
            'status'         => 'cancelled',
            'released_batches' => count($batchIds),
        ]);

        Flash::success(
            'Sale ' . $code . ' cancelled. The record is kept for audit and the committed tonnage is back in the batches.'
        );

        return $this->redirect(url('sales'));
    }

    /* ------------------------------------------------------------------ */
    /* Deliveries register                                                  */
    /* ------------------------------------------------------------------ */

    public function deliveries(): Response
    {
        $params = $this->listParams();

        $where  = ['1 = 1'];
        $values = [];

        if ($params['search'] !== '') {
            $where[]      = '(d.delivery_code LIKE :s OR b.name LIKE :s OR s.sale_code LIKE :s
                              OR d.transporter_name LIKE :s OR d.vehicle_number LIKE :s OR d.tracking_number LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        if (($params['filters']['status'] ?? '') !== '') {
            $where[]      = 'd.status = :status';
            $values['status'] = $params['filters']['status'];
        }

        if (($params['filters']['buyer_id'] ?? '') !== '') {
            $where[]              = 's.buyer_id = :buyer_id';
            $values['buyer_id'] = $params['filters']['buyer_id'];
        }

        $dateFrom = $this->request->date('date_from');
        if ($dateFrom !== null) {
            $where[]              = 's.sale_date >= :date_from';
            $values['date_from'] = $dateFrom;
        }

        $dateTo = $this->request->date('date_to');
        if ($dateTo !== null) {
            $where[]            = 's.sale_date <= :date_to';
            $values['date_to'] = $dateTo;
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total     = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM deliveries d
             INNER JOIN sales s ON s.id = d.sale_id
             INNER JOIN buyers b ON b.id = s.buyer_id' . $whereSql,
            $values
        );
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $sortable = ['delivery_code', 'dispatch_date', 'delivered_date', 'status', 'created_at'];
        $column   = in_array($params['sort'], $sortable, true) ? $params['sort'] : 'dispatch_date';
        $order    = $params['direction'] === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->db->select(
            'SELECT d.id, d.delivery_code, d.dispatch_date, d.expected_date, d.delivered_date,
                    d.delivery_location, d.status, d.transporter_name, d.vehicle_number, d.tracking_number,
                    d.created_at, s.sale_code, s.sale_date, b.name AS buyer_name
             FROM deliveries d
             INNER JOIN sales s ON s.id = d.sale_id
             INNER JOIN buyers b ON b.id = s.buyer_id'
            . $whereSql
            . ' ORDER BY d.' . $column . ' ' . $order
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('deliveries/index', [
            'pageTitle' => 'Deliveries',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $column,
            'direction' => $params['direction'],
            'filters'   => [
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions(
                    "'pending','in_transit','delivered','partially_delivered','cancelled'",
                    'All delivery statuses'
                )],
                ['key' => 'buyer_id', 'label' => 'Buyer', 'options' => $this->buyerOptions('All buyers')],
            ],
        ]);
    }

    public function deliveryEdit(string $code): Response
    {
        $delivery = $this->findDeliveryByCodeOrFail($code);

        return $this->render('deliveries/form', [
            'pageTitle' => 'Delivery ' . $delivery['delivery_code'],
            'delivery'  => $delivery,
        ]);
    }

    public function deliveryUpdate(string $code): Response
    {
        $this->assertCsrf();

        $delivery = $this->findDeliveryByCodeOrFail($code);
        $input    = $this->request->all();

        $fields = $this->deliveryFields();

        $validator = Form::validate($fields, $input);
        $validator->dateAfter('delivered_date', 'dispatch_date', 'Delivered date', 'dispatch date');

        if (($input['status'] ?? '') === 'delivered' && ($input['delivered_date'] ?? '') === '') {
            $validator->addError('delivered_date', 'Record the date the consignment was delivered.');
        }

        if ($validator->fails()) {
            return $this->backWithErrors(url('deliveries/' . $code . '/edit'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, [
            'dispatch_date', 'expected_date', 'delivered_date', 'delivery_location',
            'transporter_name', 'vehicle_number', 'tracking_number', 'notes',
        ]);

        $before = $delivery;

        $this->db->update('deliveries', $values, 'id = :id', ['id' => $delivery['id']]);

        Audit::log('update', 'sales', 'delivery', (int) $delivery['id'], $delivery['delivery_code'], $before, $values);

        Flash::success('Delivery ' . $delivery['delivery_code'] . ' updated.');

        return $this->redirect(url('deliveries'));
    }

    /* ------------------------------------------------------------------ */
    /* Line writing                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Reads the repeatable sale-item sub-form. Blank rows are dropped so the
     * client can leave an extra line ready without storing an empty claim.
     *
     * @param array<string,mixed> $input
     * @return array<int,array<string,mixed>>
     */
    private function submittedItems(array $input): array
    {
        $raw = $input['items'] ?? null;

        if (!is_array($raw)) {
            return [];
        }

        $items = [];

        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }

            $quantity = $row['quantity'] ?? null;
            $batchId  = (int) ($row['batch_id'] ?? 0);

            if ($batchId === 0 && ($quantity === null || trim((string) $quantity) === '')) {
                continue;
            }

            $price = $row['unit_price'] ?? null;

            $items[] = [
                'id'         => (int) ($row['id'] ?? 0),
                'crop_id'    => (int) ($row['crop_id'] ?? 0),
                'batch_id'   => $batchId,
                'quantity'   => is_numeric($quantity) ? (float) $quantity : null,
                'unit_price' => is_numeric($price) ? (float) $price : null,
            ];
        }

        return $items;
    }

    /**
     * Writes every line of a sale and returns the sale's total value.
     *
     * Must run inside the transaction that owns the sale row: a rejected line
     * has to take the whole sale with it, or the header would claim tonnage
     * that was never committed.
     *
     * The sale is always excluded from the committed figure, and quantity
     * taken by earlier lines of this same sale is tracked separately, so two
     * lines drawing on one lot are checked against what is left between them.
     *
     * @param array<int,array<string,mixed>> $items
     */
    private function applyItems(int $saleId, array $items): float
    {
        if ($items === []) {
            throw new HttpException(422, 'A sale must have at least one line.');
        }

        $existing = [];

        foreach ($this->db->select('SELECT id FROM sale_items WHERE sale_id = :sale', ['sale' => $saleId]) as $row) {
            $existing[(int) $row['id']] = true;
        }

        $pending = [];
        $kept    = [];
        $batches = [];
        $total   = 0.0;

        foreach ($items as $item) {
            $line = $this->writeItem($saleId, $item, $pending);

            $pending[$item['batch_id']]   = ($pending[$item['batch_id']] ?? 0.0) + (float) $item['quantity'];
            $batches[$item['batch_id']] = true;

            if ($line > 0) {
                $kept[$line] = true;
            }

            $total += round((float) $item['quantity'] * (float) $item['unit_price'], 2);
        }

        foreach (array_keys($existing) as $id) {
            if (!isset($kept[$id])) {
                $this->db->delete('sale_items', 'id = :id', ['id' => $id]);
            }
        }

        foreach (array_keys($batches) as $batchId) {
            $this->refreshBatch((int) $batchId);
        }

        return round($total, 2);
    }

    /**
     * Validates one line against the batch it draws from and writes it,
     * returning the sale_item id.
     *
     * @param array<string,mixed> $item
     * @param array<int,float>    $pending quantity already taken by earlier lines of this same sale
     */
    private function writeItem(int $saleId, array $item, array $pending): int
    {
        if ($item['batch_id'] <= 0) {
            throw new HttpException(422, 'Choose the batch being sold on every line.');
        }

        if ($item['crop_id'] <= 0) {
            throw new HttpException(422, 'Choose the crop on every line.');
        }

        $quantity = $item['quantity'];

        if ($quantity === null || $quantity <= 0.0) {
            throw new HttpException(422, 'Every line needs a quantity greater than zero.');
        }

        $unitPrice = $item['unit_price'];

        if ($unitPrice === null || $unitPrice < 0.0) {
            throw new HttpException(422, 'Every line needs a unit price of zero or more.');
        }

        $batch = $this->lockBatch($item['batch_id']);

        if ((int) $batch['crop_id'] !== $item['crop_id']) {
            throw new HttpException(422, sprintf(
                'Batch %s holds %s, not %s. A line must name the crop the batch actually contains.',
                (string) $batch['batch_code'],
                (string) ($batch['crop_name'] ?? 'a different crop'),
                (string) $this->cropName($item['crop_id'])
            ));
        }

        if (in_array((string) $batch['status'], ['sold', 'exported'], true)) {
            throw new HttpException(422, sprintf(
                'Batch %s is already %s and cannot be sold again.',
                (string) $batch['batch_code'],
                (string) $batch['status']
            ));
        }

        $remaining = $this->remainingQuantity((int) $batch['id'], $saleId) - ($pending[$item['batch_id']] ?? 0.0);

        if ($quantity - $remaining > 0.001) {
            throw new HttpException(422, sprintf(
                'Only %s %s of batch %s is still uncommitted; %s was entered on this line.',
                format_number($remaining),
                (string) $batch['unit'],
                (string) $batch['batch_code'],
                format_number($quantity)
            ));
        }

        $row = [
            'sale_id'     => $saleId,
            'batch_id'    => (int) $batch['id'],
            'crop_id'     => $item['crop_id'],
            'variety_id'  => $batch['variety_id'] ?: null,
            'quantity'    => $quantity,
            'unit'        => (string) $batch['unit'],
            'unit_price'  => $unitPrice,
            'total_value' => round($quantity * $unitPrice, 2),
        ];

        if ($item['id'] > 0) {
            $this->db->update(
                'sale_items',
                $row,
                'id = :id AND sale_id = :sale',
                ['id' => $item['id'], 'sale' => $saleId]
            );

            return $item['id'];
        }

        return $this->db->insert('sale_items', $row);
    }

    /**
     * Reads a batch under a row lock so two concurrent sales cannot both see
     * the same free tonnage.
     */
    private function lockBatch(int $batchId): array
    {
        $batch = $this->db->selectOne(
            'SELECT b.id, b.batch_code, b.crop_id, b.variety_id, b.total_quantity, b.unit, b.status,
                    c.name AS crop_name
             FROM harvest_batches b
             LEFT JOIN crops c ON c.id = b.crop_id
             WHERE b.id = :id
             FOR UPDATE',
            ['id' => $batchId]
        );

        if ($batch === null) {
            throw new HttpException(422, 'One of the batches chosen no longer exists.');
        }

        return $batch;
    }

    /**
     * Quantity of a batch that no live sale has already claimed.
     */
    private function remainingQuantity(int $batchId, ?int $excludeSaleId): float
    {
        return round($this->lockedTotal($batchId) - $this->committedQuantity($batchId, $excludeSaleId), 3);
    }

    private function lockedTotal(int $batchId): float
    {
        return (float) $this->db->scalar(
            'SELECT COALESCE(SUM(quantity), 0) FROM harvest_batch_items WHERE batch_id = :batch',
            ['batch' => $batchId]
        );
    }

    private function committedQuantity(int $batchId, ?int $excludeSaleId): float
    {
        $sql    = 'SELECT COALESCE(SUM(si.quantity), 0) FROM sale_items si
                   INNER JOIN sales s ON s.id = si.sale_id
                   WHERE si.batch_id = :batch AND s.status <> :cancelled';
        $values = ['batch' => $batchId, 'cancelled' => 'cancelled'];

        if ($excludeSaleId !== null) {
            $sql          .= ' AND si.sale_id <> :exclude';
            $values['exclude'] = $excludeSaleId;
        }

        return (float) $this->db->scalar($sql, $values);
    }

    /**
     * Restates a batch from what it actually contains and what has actually
     * been sold from it. Called after every line write and after a sale is
     * cancelled, so a batch can never disagree with its own contents.
     */
    private function refreshBatch(int $batchId): void
    {
        $batch = $this->lockBatch($batchId);

        $total     = $this->lockedTotal($batchId);
        $remaining = round($total - $this->committedQuantity($batchId, null), 3);
        $status    = (string) $batch['status'];

        // An exported lot can never come back into stock, and one that has
        // already been handed to a buyer under allocation stays allocated.
        // Everything else follows what has actually been committed.
        if (!in_array($status, ['exported', 'allocated'], true)) {
            if ($total > 0.0 && $remaining <= 0.001) {
                $status = 'sold';
            } elseif ($status === 'sold') {
                $status = $total > 0.0 ? 'aggregated' : 'open';
            }
        }

        $this->db->update(
            'harvest_batches',
            ['total_quantity' => $total, 'status' => $status],
            'id = :id',
            ['id' => $batchId]
        );
    }

    /* ------------------------------------------------------------------ */
    /* Supporting data                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Batches that may still be sold, each labelled with the tonnage that is
     * genuinely free. The sale being edited is excluded from its own
     * committed figure so an unchanged line does not look oversold.
     *
     * @return array<int,array{id:int,crop_id:int,label:string}>
     */
    private function batchOptions(?int $excludeSaleId = null): array
    {
        $rows = $this->db->select(
            'SELECT b.id, b.batch_code, b.crop_id, b.total_quantity, b.unit, b.status, c.name AS crop_name,
                    COALESCE((SELECT SUM(si.quantity) FROM sale_items si
                              INNER JOIN sales s ON s.id = si.sale_id
                              WHERE si.batch_id = b.id AND s.status <> \'cancelled\'
                                AND s.id <> :exclude), 0) AS committed
             FROM harvest_batches b
             INNER JOIN crops c ON c.id = b.crop_id
             WHERE b.status <> \'exported\'
             ORDER BY b.batch_date DESC, b.batch_code
             LIMIT 500',
            ['exclude' => $excludeSaleId ?? 0]
        );

        $options = [];

        foreach ($rows as $row) {
            $options[] = [
                'id'      => (int) $row['id'],
                'crop_id' => (int) $row['crop_id'],
                'label'   => sprintf(
                    '%s — %s (%s %s uncommitted, %s)',
                    (string) $row['batch_code'],
                    (string) $row['crop_name'],
                    format_number((float) $row['total_quantity'] - (float) $row['committed']),
                    (string) $row['unit'],
                    (string) $row['status']
                ),
            ];
        }

        return $options;
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

    private function cropName(int $cropId): string
    {
        return (string) ($this->db->scalar('SELECT name FROM crops WHERE id = :id', ['id' => $cropId]) ?? 'another crop');
    }

    private function asDate(?string $date): ?\DateTimeInterface
    {
        if ($date === null || $date === '') {
            return null;
        }

        $parsed = \DateTime::createFromFormat('Y-m-d', $date);

        return $parsed === false ? null : $parsed;
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'title'  => 'Sale',
                'fields' => [
                    ['name' => 'sale_code', 'label' => 'Sale ID', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued automatically when the sale is saved.'],
                    ['name' => 'buyer_id', 'label' => 'Buyer', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => $this->buyerOptions(), 'table' => 'buyers'],
                    ['name' => 'market_id', 'label' => 'Market', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::options(
                         $this->db->select("SELECT id, name FROM markets WHERE status = 'active' ORDER BY name"),
                         'id',
                         'name',
                         'No market'
                     ), 'table' => 'markets'],
                    ['name' => 'sale_date', 'label' => 'Sale date', 'type' => Form::DATE, 'required' => true, 'colspan' => 6,
                     'default' => date('Y-m-d')],
                    ['name' => 'currency', 'label' => 'Currency', 'type' => Form::TEXT, 'colspan' => 6,
                     'default' => 'UGX', 'maxlength' => 10],
                    ['name' => 'payment_due_date', 'label' => 'Payment due', 'type' => Form::DATE, 'colspan' => 6,
                     'help' => 'Used to flag the sale as overdue once the date passes.'],
                    ['name' => 'status', 'label' => 'Sale status', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions("'draft','confirmed','fulfilled','cancelled'", '')],
                    ['name' => 'notes', 'label' => 'Notes', 'type' => Form::TEXTAREA, 'colspan' => 12,
                     'help' => 'Contract reference, delivery terms or anything a buyer agreed.'],
                ],
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function deliveryFields(): array
    {
        return [
            ['name' => 'dispatch_date', 'label' => 'Dispatch date', 'type' => Form::DATE, 'colspan' => 6],
            ['name' => 'expected_date', 'label' => 'Expected date', 'type' => Form::DATE, 'colspan' => 6],
            ['name' => 'delivered_date', 'label' => 'Delivered date', 'type' => Form::DATE, 'colspan' => 6,
             'help' => 'Required once the delivery status is delivered.'],
            ['name' => 'status', 'label' => 'Status', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
             'options' => Form::enumOptions("'pending','in_transit','delivered','partially_delivered','cancelled'", '')],
            ['name' => 'delivery_location', 'label' => 'Delivery location', 'type' => Form::TEXT, 'colspan' => 12],
            ['name' => 'transporter_name', 'label' => 'Transporter', 'type' => Form::TEXT, 'colspan' => 6],
            ['name' => 'vehicle_number', 'label' => 'Vehicle number', 'type' => Form::TEXT, 'colspan' => 6],
            ['name' => 'tracking_number', 'label' => 'Tracking number', 'type' => Form::TEXT, 'colspan' => 6],
            ['name' => 'notes', 'label' => 'Notes', 'type' => Form::TEXTAREA, 'colspan' => 12],
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
        $sale = $this->db->selectOne(
            'SELECT s.*, b.buyer_code, b.name AS buyer_name, b.buyer_type, b.phone AS buyer_phone,
                    b.contact_person, b.contract_ref, b.country AS buyer_country,
                    m.name AS market_name, m.market_type, u.username AS created_by_name
             FROM sales s
             INNER JOIN buyers b ON b.id = s.buyer_id
             LEFT JOIN markets m ON m.id = s.market_id
             LEFT JOIN users u ON u.id = s.created_by
             WHERE s.sale_code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($sale === null) {
            throw new HttpException(404, 'No sale found with the ID ' . $code . '.');
        }

        return $sale;
    }

    private function findDeliveryByCodeOrFail(string $code): array
    {
        $delivery = $this->db->selectOne(
            'SELECT d.*, s.sale_code, s.sale_date, s.total_value, s.currency, s.status AS sale_status,
                    b.name AS buyer_name, b.buyer_code
             FROM deliveries d
             INNER JOIN sales s ON s.id = d.sale_id
             INNER JOIN buyers b ON b.id = s.buyer_id
             WHERE d.delivery_code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($delivery === null) {
            throw new HttpException(404, 'No delivery found with the ID ' . $code . '.');
        }

        return $delivery;
    }
}