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
use Services\InputLedger;
use Services\Lookups;
use Services\Notifier;

/**
 * Spraying records: what was applied to a plot, by whom, and when to apply it
 * again.
 *
 * A spray record is two things at once. It is the field record of a treatment —
 * product, target, dose, applicator — and, when it is tied to the inventory
 * catalogue, it is also a stock movement. Those two must never drift apart: the
 * inventory is the source of truth for what a product is called and in what
 * unit it is held, and the store balance has to reflect every litre that left
 * it. So the record is written and the stock consumed inside one transaction,
 * and a later correction posts an adjustment rather than consuming a second
 * time.
 *
 * The next application date is treated as a commitment rather than a note. A
 * spray schedule that slips quietly is how a pest population rebuilds itself,
 * so an overdue follow-up is flagged in the register and raises a reminder that
 * is deduplicated per record, so the officer is told once rather than on every
 * screen refresh.
 */
final class SprayingController extends Controller
{
    private Lookups $lookups;
    private InputLedger $ledger;
    private Notifier $notifier;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups  = new Lookups($db);
        $this->ledger   = new InputLedger($db);
        $this->notifier = new Notifier($db);
    }

    /**
     * The spray register. Sorted by application date so the most recent
     * treatment on any plot is the first thing read, with crop and officer
     * filters and a date range for a season's spraying programme.
     */
    public function index(): Response
    {
        $params = $this->listParams();

        $where  = ['1 = 1'];
        $values = [];

        if ($params['search'] !== '') {
            $where[]      = '(sr.spray_code LIKE :s OR sr.product_name LIKE :s OR sr.active_ingredient LIKE :s
                             OR sr.pest_disease_targeted LIKE :s OR f.full_name LIKE :s OR f.farmer_code LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        foreach (['crop_id' => 'sr.crop_id', 'officer_id' => 'sr.officer_id'] as $key => $column) {
            if (($params['filters'][$key] ?? '') !== '') {
                $where[]      = $column . ' = :' . $key;
                $values[$key] = $params['filters'][$key];
            }
        }

        $from = $this->request->date('from');
        $to   = $this->request->date('to');

        if ($from !== null) {
            $where[]            = 'sr.application_date >= :from_date';
            $values['from_date'] = $from;
        }

        if ($to !== null) {
            $where[]          = 'sr.application_date <= :to_date';
            $values['to_date'] = $to;
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM spraying_records sr
             INNER JOIN farmers f ON f.id = sr.farmer_id' . $whereSql,
            $values
        );

        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $sortable = ['application_date', 'created_at'];
        $column   = in_array($params['sort'], $sortable, true) ? $params['sort'] : 'application_date';
        $order    = $params['direction'] === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->db->select(
            'SELECT sr.id, sr.spray_code, sr.product_name, sr.active_ingredient, sr.quantity_used,
                    sr.unit, sr.application_date, sr.next_application_date, sr.pest_disease_targeted,
                    sr.application_method, sr.input_id, sr.created_at,
                    f.full_name, f.farmer_code,
                    g.garden_code, g.name AS garden_name,
                    c.name AS crop_name, o.full_name AS officer_name
             FROM spraying_records sr
             INNER JOIN farmers f ON f.id = sr.farmer_id
             INNER JOIN gardens g ON g.id = sr.garden_id
             LEFT JOIN crops c ON c.id = sr.crop_id
             LEFT JOIN field_officers o ON o.id = sr.officer_id'
            . $whereSql
            . ' ORDER BY sr.' . $column . ' ' . $order
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('spraying/index', [
            'pageTitle' => 'Spraying',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $column,
            'direction' => $params['direction'],
            'from'      => $from ?? '',
            'to'        => $to ?? '',
            'filters'   => [
                ['key' => 'crop_id', 'label' => 'Crop', 'options' => $this->lookups->cropOptions('All crops')],
                ['key' => 'officer_id', 'label' => 'Officer', 'options' => $this->lookups->officerOptions('All officers')],
            ],
        ]);
    }

    /**
     * The spray form. Opened from a garden page the farmer, garden and standing
     * planting arrive already chosen, because a treatment is always applied to a
     * specific plot.
     */
    public function create(): Response
    {
        $record = [
            'farmer_id'        => $this->request->int('farmer_id') ?: null,
            'garden_id'        => $this->request->int('garden_id') ?: null,
            'crop_id'          => $this->request->int('crop_id') ?: null,
            'planting_id'      => $this->request->int('planting_id') ?: null,
            'season_id'        => $this->request->int('season_id') ?: null,
            'application_date' => date('Y-m-d'),
            'application_method' => 'knapsack',
            'officer_id'       => $this->auth->officerId(),
        ];

        if ($record['garden_id'] !== null && $record['farmer_id'] === null) {
            $garden = $this->db->selectOne('SELECT farmer_id FROM gardens WHERE id = :id', ['id' => $record['garden_id']]);

            if ($garden !== null) {
                $record['farmer_id'] = (int) $garden['farmer_id'];
            }
        }

        return $this->render('spraying/form', [
            'pageTitle' => 'Record spraying',
            'record'    => $record,
            'sections'  => $this->sections(),
            'gpsLng'    => '',
        ]);
    }

    /**
     * Records the spray and consumes the stock in one step.
     *
     * The identifier is minted inside the transaction, and the ledger movement
     * is written by the same transaction: if the store does not hold enough, the
     * spray record is rolled back with it rather than being saved against stock
     * that was never there.
     */
    public function store(): Response
    {
        $this->assertCsrf();

        $input  = $this->request->all();
        $fields = array_merge(...array_column($this->sections(), 'fields'));

        $validator = Form::validate($fields, $input);
        $validator->dateAfter('next_application_date', 'application_date', 'Next application date', 'application date');

        if ($validator->fails()) {
            return $this->backWithErrors(url('spraying/create'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, $this->nullableColumns());

        $garden = $this->gardenForFarmer(
            isset($values['garden_id']) ? (int) $values['garden_id'] : 0,
            isset($values['farmer_id']) ? (int) $values['farmer_id'] : 0
        );

        if ($garden === null) {
            return $this->backWithErrors(
                url('spraying/create'),
                ['garden_id' => 'Select a garden that belongs to the chosen farmer.'],
                $input
            );
        }

        $values = $this->resolveProduct($values);

        $values['garden_id']          = $garden['id'];
        $values['farmer_id']          = $garden['farmer_id'];
        $values['application_date']   = $values['application_date'] ?? date('Y-m-d');
        $values['quantity_used']      = round((float) ($values['quantity_used'] ?? 0), 3);
        $values['officer_id']         = $values['officer_id'] ?? $this->auth->officerId();

        try {
            $result = $this->db->transaction(function () use ($values): array {
                $values['spray_code'] = Identifier::generate(
                    'spray',
                    date_create_from_format('Y-m-d', (string) $values['application_date']) ?: null
                );
                $values['created_by'] = $this->auth->id();

                $id = $this->db->insert('spraying_records', $values);

                $this->consumeStock($values, (string) $values['spray_code']);

                return ['id' => $id, 'code' => (string) $values['spray_code']];
            });
        } catch (\RuntimeException $exception) {
            Flash::error($exception->getMessage());

            return $this->redirect(url('spraying'));
        }

        Audit::log('create', 'spraying_records', 'spraying_record', $result['id'], $result['code'], null, $values);

        $this->notifyFollowUp($result['code'], $values, $result['id']);

        Flash::success('Spraying record ' . $result['code'] . ' has been saved.');

        return $this->redirect(url('spraying/' . $result['code']));
    }

    /**
     * One spray record with the plot it was applied to, the planting it belongs
     * to, and the rest of that plot's spray history, so a rotation or a repeat
     * treatment is visible without leaving the page.
     */
    public function show(string $code): Response
    {
        $spraying = $this->findByCodeOrFail($code);

        $history = $this->db->select(
            'SELECT sr.spray_code, sr.application_date, sr.next_application_date, sr.product_name,
                    sr.active_ingredient, sr.quantity_used, sr.unit, sr.pest_disease_targeted,
                    o.full_name AS officer_name
             FROM spraying_records sr
             LEFT JOIN field_officers o ON o.id = sr.officer_id
             WHERE sr.garden_id = :garden AND sr.id <> :id
             ORDER BY sr.application_date DESC LIMIT 20',
            ['garden' => $spraying['garden_id'], 'id' => $spraying['id']]
        );

        $movements = [];

        if ($spraying['input_id'] !== null) {
            $movements = $this->db->select(
                'SELECT transaction_code, transaction_type, quantity, unit, balance_after,
                        transaction_date, notes
                 FROM input_transactions
                 WHERE input_id = :input AND (reference_no = :code OR notes LIKE :note)
                 ORDER BY transaction_date DESC, id DESC LIMIT 10',
                ['input' => $spraying['input_id'], 'code' => $code, 'note' => '%' . $code . '%']
            );
        }

        return $this->render('spraying/show', [
            'pageTitle'  => 'Spraying ' . $code,
            'spraying'   => $spraying,
            'history'    => $history,
            'movements'  => $movements,
        ]);
    }

    public function edit(string $code): Response
    {
        $spraying = $this->findByCodeOrFail($code);

        return $this->render('spraying/form', [
            'pageTitle' => 'Edit ' . $code,
            'record'    => $spraying,
            'sections'  => $this->sections(),
            'gpsLng'    => '',
        ]);
    }

    /**
     * Corrects a spray record without double-counting it against the store.
     *
     * Stock is consumed once, when the record is first saved. Re-consuming it on
     * every edit would deduct the same litres again and quietly drain the store,
     * so a change in quantity or in the product used is settled with a single
     * adjustment movement for the difference: a positive delta consumes more,
     * a negative one returns what was never used. Switching to a different
     * product is the same idea twice — the old product is returned in full and
     * the new one is consumed — because the two movements concern different
     * stock lines and cannot be netted against each other.
     */
    public function update(string $code): Response
    {
        $this->assertCsrf();

        $spraying = $this->findByCodeOrFail($code);
        $input    = $this->request->all();
        $fields   = array_merge(...array_column($this->sections(), 'fields'));

        $validator = Form::validate($fields, $input);
        $validator->dateAfter('next_application_date', 'application_date', 'Next application date', 'application date');

        if ($validator->fails()) {
            return $this->backWithErrors(url('spraying/' . $code . '/edit'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, $this->nullableColumns());

        $garden = $this->gardenForFarmer(
            isset($values['garden_id']) ? (int) $values['garden_id'] : 0,
            isset($values['farmer_id']) ? (int) $values['farmer_id'] : 0
        );

        if ($garden === null) {
            return $this->backWithErrors(
                url('spraying/' . $code . '/edit'),
                ['garden_id' => 'Select a garden that belongs to the chosen farmer.'],
                $input
            );
        }

        $values = $this->resolveProduct($values);

        $values['garden_id']        = $garden['id'];
        $values['farmer_id']        = $garden['farmer_id'];
        $values['quantity_used']    = round((float) ($values['quantity_used'] ?? 0), 3);

        $before = $spraying;

        try {
            $this->db->transaction(function () use ($values, $spraying, $code): void {
                $this->db->update('spraying_records', $values, 'id = :id', ['id' => $spraying['id']]);

                $this->settleStockDelta($spraying, $values, $code);
            });
        } catch (\RuntimeException $exception) {
            Flash::error($exception->getMessage());

            return $this->redirect(url('spraying/' . $code));
        }

        Audit::log('update', 'spraying_records', 'spraying_record', (int) $spraying['id'], $code, $before, $values);

        $this->notifyFollowUp($code, $values, (int) $spraying['id']);

        Flash::success('Spraying record ' . $code . ' has been updated.');

        return $this->redirect(url('spraying/' . $code));
    }

    /**
     * Removes a spray record, returning anything it consumed to the store first.
     *
     * The record was a stock movement as well as a field note, so deleting it
     * without returning the litres would leave the balance permanently short for
     * a product that is still on the shelf. The return and the delete share one
     * transaction, so the ledger can never show a return without the record that
     * explains it.
     */
    public function destroy(string $code): Response
    {
        $this->assertCsrf();

        $spraying = $this->findByCodeOrFail($code);

        try {
            $this->db->transaction(function () use ($spraying, $code): void {
                $this->returnConsumedStock($spraying, $code);

                $this->db->delete('spraying_records', 'id = :id', ['id' => $spraying['id']]);
            });
        } catch (\RuntimeException $exception) {
            Flash::error($exception->getMessage());

            return $this->redirect(url('spraying/' . $code));
        }

        Audit::log('delete', 'spraying_records', 'spraying_record', (int) $spraying['id'], $code, $spraying);

        Flash::success('Spraying record ' . $code . ' has been removed.');

        return $this->redirect(url('spraying'));
    }

    /**
     * Takes the quantity out of the store for a spray record tied to the
     * inventory catalogue. Throwing rolls the surrounding transaction back, which
     * is what keeps a record and its stock movement in step.
     *
     * @param array<string,mixed> $values
     */
    private function consumeStock(array $values, string $code): void
    {
        $inputId  = isset($values['input_id']) ? (int) $values['input_id'] : 0;
        $quantity = round((float) ($values['quantity_used'] ?? 0), 3);

        if ($inputId <= 0 || $quantity <= 0.0) {
            return;
        }

        $date = trim((string) ($values['application_date'] ?? ''));

        $result = $this->ledger->move($inputId, [
            'type'         => 'usage',
            'quantity'     => $quantity,
            'date'         => $date === '' ? date('Y-m-d') : $date,
            'reference_no' => $code,
            'notes'        => 'Applied under spraying record ' . $code . '.',
        ], $this->auth->id());

        if (($result['success'] ?? false) !== true) {
            throw new \RuntimeException((string) ($result['message'] ?? 'The stock could not be deducted.'));
        }
    }

    /**
     * Settles the stock difference between what was recorded before an edit and
     * what is recorded after it.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private function settleStockDelta(array $before, array $after, string $code): void
    {
        $oldInput  = isset($before['input_id']) ? (int) $before['input_id'] : 0;
        $newInput  = isset($after['input_id']) ? (int) $after['input_id'] : 0;
        $oldQty    = round((float) ($before['quantity_used'] ?? 0), 3);
        $newQty    = round((float) ($after['quantity_used'] ?? 0), 3);

        if ($oldInput === $newInput) {
            $delta = round($newQty - $oldQty, 3);

            if ($delta > 0.0) {
                $this->consumeStock(['input_id' => $newInput, 'quantity_used' => $delta, 'application_date' => $after['application_date'] ?? null], $code);
            } elseif ($delta < 0.0) {
                $this->returnStock($newInput, abs($delta), (string) ($after['application_date'] ?? date('Y-m-d')), $code);
            }

            return;
        }

        // A different product means two different stock lines, so neither can be
        // settled by netting: the original line is given back in full and the new
        // one is taken in full.
        if ($oldInput > 0 && $oldQty > 0.0) {
            $this->returnStock($oldInput, $oldQty, (string) ($before['application_date'] ?? date('Y-m-d')), $code);
        }

        if ($newInput > 0 && $newQty > 0.0) {
            $this->consumeStock(['input_id' => $newInput, 'quantity_used' => $newQty, 'application_date' => $after['application_date'] ?? null], $code);
        }
    }

    /**
     * Returns everything a deleted record had taken out of the store.
     *
     * @param array<string,mixed> $spraying
     */
    private function returnConsumedStock(array $spraying, string $code): void
    {
        $inputId  = isset($spraying['input_id']) ? (int) $spraying['input_id'] : 0;
        $quantity = round((float) ($spraying['quantity_used'] ?? 0), 3);

        if ($inputId <= 0 || $quantity <= 0.0) {
            return;
        }

        $this->returnStock($inputId, $quantity, (string) ($spraying['application_date'] ?? date('Y-m-d')), $code);
    }

    /**
     * Puts a quantity back on an item. An adjustment is an increase on the
     * ledger, which is what returning stock is: the goods never left the farm's
     * account of them, so the correction is posted as one rather than as a
     * negative usage that the ledger would reject.
     */
    private function returnStock(int $inputId, float $quantity, string $date, string $code): void
    {
        $result = $this->ledger->move($inputId, [
            'type'         => 'adjustment',
            'quantity'     => round($quantity, 3),
            'date'         => $date === '' ? date('Y-m-d') : $date,
            'reference_no' => $code,
            'notes'        => 'Returned to store from spraying record ' . $code . '.',
        ], $this->auth->id());

        if (($result['success'] ?? false) !== true) {
            throw new \RuntimeException((string) ($result['message'] ?? 'The stock could not be returned.'));
        }
    }

    /**
     * Reconciles the free-text product fields with the inventory catalogue.
     *
     * When a record is tied to an inventory item, the catalogue is the source of
     * truth: a spray of "the fungicide" must not be filed under a different name
     * or a different unit than the stock it came from, or stock and field
     * records stop reconciling. Anything the officer left blank is filled from
     * the catalogue; anything they typed against a tied item is corrected to
     * match it.
     *
     * A record with no catalogue link still needs a name to file against, so a
     * blank one is recorded as unspecified rather than written as an empty
     * string against a NOT NULL column.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function resolveProduct(array $values): array
    {
        $inputId = isset($values['input_id']) ? (int) $values['input_id'] : 0;

        $catalogue = $inputId > 0
            ? $this->db->selectOne(
                'SELECT id, name, unit, active_ingredient FROM inputs WHERE id = :id LIMIT 1',
                ['id' => $inputId]
            )
            : null;

        if ($catalogue === null) {
            $values['product_name'] = trim((string) ($values['product_name'] ?? ''));

            if ($values['product_name'] === '') {
                $values['product_name'] = 'Unspecified product';
            }

            // spraying_records.unit is NOT NULL, and a record with no product
            // still has to say how it was measured.
            if (trim((string) ($values['unit'] ?? '')) === '') {
                $values['unit'] = 'L';
            }

            return $values;
        }

        $values['product_name'] = (string) $catalogue['name'];
        $values['unit']         = (string) $catalogue['unit'];

        if (trim((string) ($values['active_ingredient'] ?? '')) === '') {
            $values['active_ingredient'] = $catalogue['active_ingredient'];
        }

        return $values;
    }

    /**
     * Raises the follow-up reminder for a spray that has a next application
     * date. The dedupe key is per record, so saving the same spray twice — or
     * editing it after the fact — cannot turn into a second reminder.
     *
     * @param array<string,mixed> $values
     */
    private function notifyFollowUp(string $code, array $values, int $id): void
    {
        $next = $values['next_application_date'] ?? null;

        if ($next === null || $next === '') {
            return;
        }

        $this->notifier->notify([
            'type'        => 'spray_followup',
            'title'       => 'Follow-up spraying scheduled',
            'message'     => sprintf(
                'Spraying %s (%s) is due again on %s.',
                $code,
                (string) ($values['pest_disease_targeted'] ?? $values['product_name'] ?? 'product'),
                format_date((string) $next)
            ),
            'farmer_id'   => (int) ($values['farmer_id'] ?? 0) ?: null,
            'officer_id'  => isset($values['officer_id']) ? (int) $values['officer_id'] : null,
            'link'        => url('spraying/' . $code),
            'entity_type' => 'spraying_record',
            'entity_id'   => $id,
            'dedupe_key'  => 'spray_next:' . $code,
        ]);
    }

    /**
     * Confirms the chosen garden belongs to the chosen farmer, so a spray can
     * never be filed against a plot that is not the farmer's.
     *
     * @return array<string,mixed>|null
     */
    private function gardenForFarmer(int $gardenId, int $farmerId): ?array
    {
        if ($gardenId <= 0 || $farmerId <= 0) {
            return null;
        }

        return $this->db->selectOne(
            'SELECT id, farmer_id, garden_code, name FROM gardens
             WHERE id = :id AND farmer_id = :farmer AND deleted_at IS NULL
             LIMIT 1',
            ['id' => $gardenId, 'farmer' => $farmerId]
        );
    }

    /**
     * Every nullable column on spraying_records.
     *
     * @return array<int,string>
     */
    private function nullableColumns(): array
    {
        return [
            'crop_id', 'variety_id', 'planting_id', 'season_id', 'input_id',
            'active_ingredient', 'dosage', 'next_application_date', 'pest_disease_targeted',
            'application_method', 'applicator_name', 'officer_id', 'weather_conditions', 'remarks',
        ];
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'title'  => 'Where and what',
                'fields' => [
                    ['name' => 'spray_code', 'label' => 'Spray ID', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued automatically when the record is saved.'],
                    ['name' => 'farmer_id', 'label' => 'Farmer', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => $this->lookups->farmerOptions(), 'table' => 'farmers'],
                    ['name' => 'garden_id', 'label' => 'Garden', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => $this->lookups->gardenOptions(), 'table' => 'gardens',
                     'help' => 'Must belong to the farmer above.'],
                    ['name' => 'input_id', 'label' => 'Product from inventory', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->inputOptions(), 'table' => 'inputs',
                     'help' => 'Choosing a stocked product takes its name and unit from the catalogue and deducts the quantity used.'],
                    ['name' => 'product_name', 'label' => 'Product name', 'type' => Form::TEXT, 'colspan' => 6,
                     'maxlength' => 150],
                    ['name' => 'active_ingredient', 'label' => 'Active ingredient', 'type' => Form::TEXT, 'colspan' => 6,
                     'maxlength' => 150, 'help' => 'Filled from the catalogue when the product is taken from stock.'],
                    ['name' => 'crop_id', 'label' => 'Crop', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->cropOptions('Not specified')],
                    ['name' => 'variety_id', 'label' => 'Variety', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->varietyOptions('Not specified')],
                    ['name' => 'planting_id', 'label' => 'Planting', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->plantingOptions()],
                    ['name' => 'season_id', 'label' => 'Season', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->seasonOptions('Not specified')],
                ],
            ],
            [
                'title'  => 'The application',
                'fields' => [
                    ['name' => 'application_date', 'label' => 'Application date', 'type' => Form::DATE, 'required' => true,
                     'colspan' => 6, 'default' => date('Y-m-d')],
                    ['name' => 'next_application_date', 'label' => 'Next application date', 'type' => Form::DATE, 'colspan' => 6,
                     'help' => 'Sets the follow-up reminder. Cannot be earlier than the application date.'],
                    ['name' => 'pest_disease_targeted', 'label' => 'Pest or disease targeted', 'type' => Form::TEXT, 'colspan' => 6,
                     'maxlength' => 255, 'placeholder' => 'e.g. Fall armyworm, coffee berry borer'],
                    ['name' => 'application_method', 'label' => 'Application method', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions("'knapsack','tractor','manual','other'", '')],
                    ['name' => 'quantity_used', 'label' => 'Quantity used', 'type' => Form::NUMBER, 'colspan' => 6,
                     'min' => 0, 'step' => 0.001],
                    ['name' => 'unit', 'label' => 'Unit', 'type' => Form::TEXT, 'colspan' => 6,
                     'maxlength' => 20, 'default' => 'L'],
                    ['name' => 'dosage', 'label' => 'Dosage', 'type' => Form::TEXT, 'colspan' => 6,
                     'maxlength' => 120, 'placeholder' => 'e.g. 2 ml per litre of water'],
                    ['name' => 'applicator_name', 'label' => 'Who applied it', 'type' => Form::TEXT, 'colspan' => 6,
                     'maxlength' => 150],
                    ['name' => 'officer_id', 'label' => 'Supervising officer', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->officerOptions(), 'table' => 'field_officers'],
                    ['name' => 'weather_conditions', 'label' => 'Weather conditions', 'type' => Form::TEXT, 'colspan' => 12,
                     'maxlength' => 120,
                     'help' => 'Spraying before rain or in strong wind wastes product and can carry it off target.'],
                    ['name' => 'remarks', 'label' => 'Remarks', 'type' => Form::TEXTAREA, 'colspan' => 12],
                ],
            ],
        ];
    }

    /**
     * Stocked input items, so the product list on this form is the same list the
     * inventory holds rather than a second, drifting copy of it.
     *
     * @return array<int,array{value:string,label:string}>
     */
    private function inputOptions(): array
    {
        $rows = $this->db->select(
            'SELECT i.id, i.input_code, i.name, i.unit, i.current_stock, c.name AS category_name
             FROM inputs i
             LEFT JOIN input_categories c ON c.id = i.category_id
             WHERE i.status = \'active\'
             ORDER BY c.name, i.name
             LIMIT 300'
        );

        $options = [['value' => '', 'label' => 'Not from the inventory catalogue']];

        foreach ($rows as $row) {
            $options[] = [
                'value' => (string) $row['id'],
                'label' => sprintf(
                    '%s - %s%s (%s %s in stock)',
                    (string) $row['input_code'],
                    (string) $row['name'],
                    $row['category_name'] !== null ? ' [' . $row['category_name'] . ']' : '',
                    rtrim(rtrim(number_format((float) $row['current_stock'], 2), '0'), '.'),
                    (string) $row['unit']
                ),
            ];
        }

        return $options;
    }

    /**
     * Plantings available for attachment, bounded so the dropdown stays usable
     * on a large register.
     *
     * @return array<int,array{value:string,label:string}>
     */
    private function plantingOptions(): array
    {
        $rows = $this->db->select(
            'SELECT p.id, p.planting_code, c.name AS crop_name, f.full_name, g.name AS garden_name
             FROM plantings p
             INNER JOIN crops c ON c.id = p.crop_id
             INNER JOIN farmers f ON f.id = p.farmer_id
             LEFT JOIN gardens g ON g.id = p.garden_id
             WHERE p.status <> \'terminated\'
             ORDER BY p.planting_date DESC LIMIT 300'
        );

        $options = [['value' => '', 'label' => 'Not linked to a planting']];

        foreach ($rows as $row) {
            $options[] = [
                'value' => (string) $row['id'],
                'label' => sprintf(
                    '%s — %s (%s)',
                    (string) $row['crop_name'],
                    $row['garden_name'] ?: 'no garden',
                    (string) $row['planting_code']
                ),
            ];
        }

        return $options;
    }

    /**
     * @return array<string,mixed>
     */
    private function findByCodeOrFail(string $code): array
    {
        $spraying = $this->db->selectOne(
            'SELECT sr.*, f.full_name, f.farmer_code, f.phone AS farmer_phone, f.village_name,
                    g.garden_code, g.name AS garden_name, g.acreage AS garden_acreage,
                    c.name AS crop_name, v.name AS variety_name,
                    p.planting_code, p.acreage_planted,
                    s.season_code, s.name AS season_name,
                    i.input_code, i.name AS input_name, i.current_stock AS input_stock,
                    o.full_name AS officer_name, u.full_name AS recorded_by_name
             FROM spraying_records sr
             INNER JOIN farmers f ON f.id = sr.farmer_id
             INNER JOIN gardens g ON g.id = sr.garden_id
             LEFT JOIN crops c ON c.id = sr.crop_id
             LEFT JOIN crop_varieties v ON v.id = sr.variety_id
             LEFT JOIN plantings p ON p.id = sr.planting_id
             LEFT JOIN seasons s ON s.id = sr.season_id
             LEFT JOIN inputs i ON i.id = sr.input_id
             LEFT JOIN field_officers o ON o.id = sr.officer_id
             LEFT JOIN users u ON u.id = sr.created_by
             WHERE sr.spray_code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($spraying === null) {
            throw new HttpException(404, 'No spraying record found with the ID ' . $code . '.');
        }

        return $spraying;
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }
}