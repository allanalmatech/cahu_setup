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
use Services\Forecaster;
use Services\Lookups;
use Services\Notifier;

/**
 * Harvest records and yield forecasting.
 *
 * A harvest is the point where produce leaves the field, so it is the record
 * the whole market side depends on: batches are built from harvest rows, and
 * any sale or export traces back through them. Records are therefore never
 * deleted once they have entered a batch.
 */
final class HarvestController extends Controller
{
    private Lookups $lookups;
    private Forecaster $forecaster;
    private Notifier $notifier;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups   = new Lookups($db);
        $this->forecaster = new Forecaster($db);
        $this->notifier  = new Notifier($db);
    }

    public function index(): Response
    {
        $params = $this->listParams();

        $where  = ['1 = 1'];
        $values = [];

        if ($params['search'] !== '') {
            $where[]      = '(hr.harvest_code LIKE :s OR c.name LIKE :s OR f.full_name LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        foreach (['status' => 'hr.status', 'crop_id' => 'hr.crop_id', 'season_id' => 'hr.season_id'] as $key => $column) {
            if (($params['filters'][$key] ?? '') !== '') {
                $where[]      = $column . ' = :' . $key;
                $values[$key] = $params['filters'][$key];
            }
        }

        if (($params['filters']['year'] ?? '') !== '') {
            $where[]      = 'YEAR(hr.harvest_date) = :year';
            $values['year'] = (string) (int) $params['filters']['year'];
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where) . $this->scopeWhere($values);

        $totals = $this->db->selectOne(
            'SELECT COUNT(*) AS records, COALESCE(SUM(hr.quantity_harvested), 0) AS quantity
             FROM harvest_records hr
             INNER JOIN farmers f ON f.id = hr.farmer_id
             LEFT JOIN crops c ON c.id = hr.crop_id' . $whereSql,
            $values
        ) ?? ['records' => 0, 'quantity' => 0];

        $paginator = new Paginator((int) $totals['records'], $params['page'], $params['per_page']);

        $sortable = ['harvest_date', 'quantity_harvested', 'status'];
        $column   = in_array($params['sort'], $sortable, true) ? $params['sort'] : 'harvest_date';
        $order    = $params['direction'] === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->db->select(
            'SELECT hr.*, c.name AS crop_name, f.full_name, f.farmer_code, g.garden_code,
                    b.batch_code, b.status AS batch_status
             FROM harvest_records hr
             INNER JOIN farmers f ON f.id = hr.farmer_id
             LEFT JOIN crops c ON c.id = hr.crop_id
             LEFT JOIN gardens g ON g.id = hr.garden_id
             LEFT JOIN harvest_batch_items hbi ON hbi.harvest_id = hr.id
             LEFT JOIN harvest_batches b ON b.id = hbi.batch_id'
            . $whereSql
            . ' ORDER BY hr.' . $column . ' ' . $order
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('harvest/index', [
            'pageTitle' => 'Harvest records',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $column,
            'direction' => $params['direction'],
            'totalQty'  => (float) $totals['quantity'],
            'records'   => (int) $totals['records'],
            'canManage' => $this->auth->can('harvest.manage'),
            'filters'   => [
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions("'recorded','aggregated','sold','cancelled'", 'All statuses')],
                ['key' => 'crop_id', 'label' => 'Crop', 'options' => $this->lookups->cropOptions('All crops')],
                ['key' => 'season_id', 'label' => 'Season', 'options' => $this->lookups->seasonOptions('All seasons')],
                ['key' => 'year', 'label' => 'Year', 'options' => $this->yearOptions()],
            ],
        ]);
    }

    public function create(): Response
    {
        $record = null;

        $plantingCode = $this->request->string('planting_code');

        if ($plantingCode !== '') {
            $planting = $this->db->selectOne(
                'SELECT p.id, p.farmer_id, p.garden_id, p.crop_id, p.variety_id, p.season_id,
                        p.planting_code, c.name AS crop_name, c.primary_unit
                 FROM plantings p
                 INNER JOIN crops c ON c.id = p.crop_id
                 WHERE p.planting_code = :code',
                ['code' => $plantingCode]
            );

            if ($planting !== null) {
                $record = [
                    'planting_id'      => $planting['id'],
                    'farmer_id'        => $planting['farmer_id'],
                    'garden_id'        => $planting['garden_id'],
                    'crop_id'          => $planting['crop_id'],
                    'variety_id'       => $planting['variety_id'],
                    'season_id'        => $planting['season_id'],
                    'harvest_date'     => date('Y-m-d'),
                    'unit'             => $planting['primary_unit'],
                ];
            }
        }

        return $this->render('harvest/form', [
            'pageTitle' => 'Record harvest',
            'record'    => $record,
            'sections'  => $this->sections(),
        ]);
    }

    public function store(): Response
    {
        $this->assertCsrf();

        $input    = $this->request->all();
        $fields   = array_merge(...array_column($this->sections(), 'fields'));
        $planting = $this->plantingFrom($input);

        if ($planting === null) {
            return $this->backWithErrors(
                url('harvest/create'),
                ['planting_id' => 'Select the planting this harvest came from.'],
                $input
            );
        }

        $validator = Form::validate($fields, $input);
        $validator->date('harvest_date', 'Harvest date', true);
        $validator->numeric('quantity_harvested', 'Quantity harvested', true);
        $validator->minValue('quantity_harvested', 0.001, 'Quantity harvested');

        // Harvesting before the crop was planted is impossible; harvesting long
        // before the expected date usually means the wrong date was entered.
        $harvestDate = (string) ($input['harvest_date'] ?? '');
        $plantedOn   = (string) $planting['planting_date'];

        if ($harvestDate !== '' && $plantedOn !== '' && $harvestDate < $plantedOn) {
            return $this->backWithErrors(
                url('harvest/create'),
                ['harvest_date' => 'The harvest date cannot be before the planting date (' . format_date($plantedOn) . ').'],
                $input
            );
        }

        if ($validator->fails()) {
            return $this->backWithErrors(url('harvest/create'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, ['moisture_content', 'losses', 'loss_unit', 'storage_location', 'officer_id', 'notes']);

        // Identity and location come from the planting, never from the form: a
        // harvest must be traceable to exactly one plot.
        $values['farmer_id']  = (int) $planting['farmer_id'];
        $values['garden_id']  = (int) $planting['garden_id'];
        $values['crop_id']    = (int) $planting['crop_id'];
        $values['variety_id'] = $planting['variety_id'];
        $values['season_id']  = (int) $planting['season_id'];
        $values['status']     = 'recorded';

        if (empty($values['unit'])) {
            $values['unit'] = $planting['primary_unit'] ?: 'kg';
        }

        $harvestId = $this->db->transaction(function () use ($values, $planting): int {
            $values['harvest_code'] = Identifier::generate('harvest');
            $values['created_by']   = $this->auth->id();

            $id = $this->db->insert('harvest_records', $values);

            // Fold the quantity into the planting's forecast so the variance
            // stays live as harvests come in.
            $this->forecaster->recordActual((int) $planting['id']);

            Audit::log('create', 'harvest', 'harvest_record', $id, $values['harvest_code'], null, $values);

            return $id;
        });

        $this->refreshPlantingStatus((int) $planting['id']);

        $this->notifier->notify([
            'type'        => 'harvest_recorded',
            'title'       => 'Harvest recorded',
            'message'     => sprintf(
                '%s of %s was recorded from planting %s.',
                format_number($values['quantity_harvested']),
                (string) $values['unit'],
                $planting['planting_code']
            ),
            'farmer_id'   => $values['farmer_id'],
            'link'        => url('harvest/' . $values['harvest_code']),
            'entity_type' => 'harvest_record',
            'entity_id'   => $harvestId,
            'dedupe_key'  => 'harvest_recorded:' . $values['harvest_code'],
        ]);

        Flash::success('Harvest ' . $values['harvest_code'] . ' recorded.');

        return $this->redirect(url('harvest/' . $values['harvest_code']));
    }

    public function show(string $code): Response
    {
        $harvest = $this->findByCodeOrFail($code);
        $this->assertScope($harvest);

        $batch = $this->db->selectOne(
            'SELECT b.*, b.total_quantity
             FROM harvest_batch_items hbi
             INNER JOIN harvest_batches b ON b.id = hbi.batch_id
             WHERE hbi.harvest_id = :id',
            ['id' => $harvest['id']]
        );

        $chain = $this->db->select(
            'SELECT s.sale_code, s.sale_date, s.status, bi.quantity, bi.unit,
                    bu.buyer_code, bu.name AS buyer_name, bu.buyer_type
             FROM sale_items bi
             INNER JOIN sales s ON s.id = bi.sale_id
             INNER JOIN buyers bu ON bu.id = s.buyer_id
             WHERE bi.batch_id = :batch
             ORDER BY s.sale_date DESC',
            ['batch' => $batch['id'] ?? 0]
        );

        $exports = $this->db->select(
            'SELECT e.export_code, e.shipment_date, e.status, e.quantity, e.unit, e.destination_country
             FROM exports e
             WHERE e.batch_id = :batch
             ORDER BY e.shipment_date DESC',
            ['batch' => $batch['id'] ?? 0]
        );

        return $this->render('harvest/show', [
            'pageTitle' => 'Harvest ' . $code,
            'harvest'   => $harvest,
            'batch'     => $batch,
            'chain'     => $chain,
            'exports'   => $exports,
        ]);
    }

    public function edit(string $code): Response
    {
        $harvest = $this->findByCodeOrFail($code);
        $this->assertScope($harvest);

        return $this->render('harvest/form', [
            'pageTitle' => 'Edit ' . $code,
            'record'    => $harvest,
            'sections'  => $this->sections(),
        ]);
    }

    public function update(string $code): Response
    {
        $this->assertCsrf();

        $harvest   = $this->findByCodeOrFail($code);
        $this->assertScope($harvest);

        $input     = $this->request->all();
        $fields    = array_merge(...array_column($this->sections(), 'fields'));
        $validator = Form::validate($fields, $input);

        $validator->date('harvest_date', 'Harvest date', true);
        $validator->numeric('quantity_harvested', 'Quantity harvested', true);
        $validator->minValue('quantity_harvested', 0.001, 'Quantity harvested');

        if ($validator->fails()) {
            return $this->backWithErrors(url('harvest/' . $code . '/edit'), $validator->errors(), $input);
        }

        // The harvest cannot be moved to a different planting: that would break
        // the origin chain that a batch depends on.
        if ((string) ($input['planting_id'] ?? '') !== (string) $harvest['planting_id']) {
            return $this->backWithErrors(
                url('harvest/' . $code . '/edit'),
                ['planting_id' => 'A harvest record cannot be moved to another planting. Create a new record instead.'],
                $input
            );
        }

        $values = Form::extract($fields, $input, ['moisture_content', 'losses', 'loss_unit', 'storage_location', 'officer_id', 'notes']);

        $before = $harvest;

        $this->db->transaction(function () use ($values, $harvest, $before): void {
            $this->db->update('harvest_records', $values, 'id = :id', ['id' => $harvest['id']]);

            $this->forecaster->recordActual((int) $harvest['planting_id']);

            // A harvest already inside a batch may not shrink, or the batch
            // total would stop matching its contents.
            $batch = $this->db->selectOne(
                'SELECT batch_id FROM harvest_batch_items WHERE harvest_id = :id',
                ['id' => $harvest['id']]
            );

            if ($batch !== null
                && (float) $values['quantity_harvested'] < (float) $before['quantity_harvested']) {
                Flash::warning(
                    'This quantity was reduced after the harvest joined batch ' . $batch['batch_id'] . '. '
                    . 'The batch total has not been recalculated, so check it before the batch is sold.'
                );
            }

            Audit::log('update', 'harvest', 'harvest_record', (int) $harvest['id'], $code, $before, $values);
        });

        Flash::success('Harvest record updated.');

        return $this->redirect(url('harvest/' . $code));
    }

    public function destroy(string $code): Response
    {
        $this->assertCsrf();

        $harvest = $this->findByCodeOrFail($code);
        $this->assertScope($harvest);

        $batch = $this->db->selectOne(
            'SELECT b.batch_code FROM harvest_batch_items hbi
             INNER JOIN harvest_batches b ON b.id = hbi.batch_id
             WHERE hbi.harvest_id = :id LIMIT 1',
            ['id' => $harvest['id']]
        );

        if ($batch !== null) {
            Flash::error(
                'This harvest is part of batch ' . $batch['batch_code'] . '. Remove it from the batch first, '
                . 'otherwise the batch would contain produce that no longer exists.'
            );

            return $this->redirect(url('harvest/' . $code));
        }

        $this->db->transaction(function () use ($harvest, $code): void {
            $this->db->update('harvest_records', ['status' => 'cancelled'], 'id = :id', ['id' => $harvest['id']]);

            $this->forecaster->recordActual((int) $harvest['planting_id']);

            Audit::log('cancel', 'harvest', 'harvest_record', (int) $harvest['id'], $code, $harvest);
        });

        Flash::success('Harvest ' . $code . ' cancelled. The record is kept so the batch history stays intact.');

        return $this->redirect(url('harvest'));
    }

    /* ------------------------------------------------------------------ */
    /* Forecasting                                                         */
    /* ------------------------------------------------------------------ */

    public function forecastIndex(): Response
    {
        $params = $this->listParams();

        $values = [];
        $scope  = $this->forecastScope($values);

        $where  = ['1 = 1'] . $scope;
        $sql    = [];
        $search = $params['search'];

        if ($search !== '') {
            $sql[]      = '(p.planting_code LIKE :s OR c.name LIKE :s OR f.full_name LIKE :s)';
            $values['s'] = '%' . $search . '%';
        }

        if (($params['filters']['season_id'] ?? '') !== '') {
            $sql[]            = 'hf.season_id = :season_id';
            $values['season_id'] = $params['filters']['season_id'];
        }

        if (($params['filters']['crop_id'] ?? '') !== '') {
            $sql[]          = 'hf.crop_id = :crop_id';
            $values['crop_id'] = $params['filters']['crop_id'];
        }

        if ($sql !== []) {
            $where[] = implode(' AND ', $sql);
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total     = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM harvest_forecasts hf
             INNER JOIN plantings p ON p.id = hf.planting_id
             INNER JOIN crops c ON c.id = hf.crop_id
             INNER JOIN farmers f ON f.id = hf.farmer_id' . $whereSql,
            $values
        );
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $rows = $this->db->select(
            'SELECT hf.*, p.planting_code, p.planting_date, p.status AS planting_status,
                    c.name AS crop_name, f.full_name, f.farmer_code,
                    COALESCE((SELECT SUM(hr.quantity_harvested) FROM harvest_records hr
                              WHERE hr.planting_id = hf.planting_id AND hr.status <> \'cancelled\'), 0) AS harvested
             FROM harvest_forecasts hf
             INNER JOIN plantings p ON p.id = hf.planting_id
             INNER JOIN crops c ON c.id = hf.crop_id
             INNER JOIN farmers f ON f.id = hf.farmer_id'
            . $whereSql
            . ' ORDER BY hf.expected_production DESC
             LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('harvest/forecast', [
            'pageTitle' => 'Yield forecast',
            'rows'      => $rows,
            'paginator' => $paginator,
            'seasons'   => $this->lookups->seasonOptions('All seasons'),
            'crops'     => $this->lookups->cropOptions('All crops'),
            'summary'   => $this->forecastTotals(),
        ]);
    }

    /**
 * Backfills forecasts for a whole season.
 *
 * routes.php exposes this as a GET route, so the view submits a small GET form
 * and there is no request body to protect: a CSRF check would have nothing to
 * verify. Nothing is written until the season is chosen explicitly.
 */
    public function generateForecast(): Response
    {
        $seasonId = $this->request->int('season_id');

        if ($seasonId === 0) {
            Flash::error('Choose a season to generate forecasts for.');

            return $this->redirect(url('forecast'));
        }

        $written = $this->forecaster->generateForSeason($seasonId);

        Audit::log('generate', 'forecast', 'season', $seasonId, 'season forecast', null, ['plantings' => $written]);

        Flash::success(
            $written === 0
                ? 'No forecast could be written: no planting in that season has a yield per acre on its crop.'
                : $written . ' forecast(s) written. Existing manual revisions were kept.'
        );

        return $this->redirect(url('forecast'));
    }

    public function reviseForecast(int $id): Response
    {
        $this->assertCsrf();

        $forecast = $this->db->selectOne('SELECT * FROM harvest_forecasts WHERE id = :id', ['id' => $id]);

        if ($forecast === null) {
            throw new HttpException(404, 'That forecast could not be found.');
        }

        $perAcre = $this->request->float('revised_yield_per_acre');
        $reason  = $this->request->string('revision_reason');

        if ($perAcre <= 0) {
            Flash::error('Enter the revised yield per acre as a number greater than zero.');

            return $this->redirect(url('forecast'));
        }

        // A revision without a reason is indistinguishable from a data-entry
        // mistake later, so the reason is mandatory.
        if ($reason === '') {
            Flash::error('Give a reason for the revision. It is kept with the forecast for audit.');

            return $this->redirect(url('forecast'));
        }

        $result = $this->forecaster->manualRevision($id, $perAcre, $reason);

        if (($result['success'] ?? false) !== true) {
            Flash::error((string) ($result['message'] ?? 'The forecast could not be revised.'));

            return $this->redirect(url('forecast'));
        }

        Audit::log(
            'revise',
            'forecast',
            'harvest_forecast',
            $id,
            'forecast revision',
            $forecast,
            ['revised_yield_per_acre' => $perAcre, 'reason' => $reason]
        );

        Flash::success('Forecast revised to ' . format_number($result['production']) . ' ' . ($forecast['unit'] ?? '') . '.');

        return $this->redirect(url('forecast'));
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array<string,mixed>|null
     */
    private function plantingFrom(array $input): ?array
    {
        $plantingId = (int) ($input['planting_id'] ?? 0);

        if ($plantingId === 0) {
            return null;
        }

        return $this->db->selectOne(
            'SELECT p.*, c.primary_unit, c.name AS crop_name
             FROM plantings p
             INNER JOIN crops c ON c.id = p.crop_id
             WHERE p.id = :id',
            ['id' => $plantingId]
        );
    }

    /**
     * A planting is harvested once it has produce, and failed once a harvest
     * has been cancelled, so the planting register does not need a sweep.
     */
    private function refreshPlantingStatus(int $plantingId): void
    {
        $planting = $this->db->selectOne(
            'SELECT p.id, p.status, p.expected_harvest_date, p.acreage_planted,
                    (SELECT COUNT(*) FROM harvest_records hr
                     WHERE hr.planting_id = p.id AND hr.status <> \'cancelled\') AS harvest_count,
                    (SELECT COALESCE(SUM(hr.quantity_harvested), 0) FROM harvest_records hr
                     WHERE hr.planting_id = p.id AND hr.status <> \'cancelled\') AS harvested
             FROM plantings p WHERE p.id = :id',
            ['id' => $plantingId]
        );

        if ($planting === null || $planting['status'] === 'failed') {
            return;
        }

        if ((int) $planting['harvest_count'] === 0) {
            return;
        }

        $this->db->update('plantings', ['status' => 'harvested', 'growth_stage' => 'harvested'], 'id = :id', ['id' => $plantingId]);
    }

    /**
     * @return array<string,float|int>
     */
    private function forecastTotals(): array
    {
        $values = [];
        $scope  = $this->forecastScope($values);

        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS forecasts,
                    COALESCE(SUM(hf.expected_production), 0) AS expected,
                    COALESCE(SUM(COALESCE(hf.revised_production, hf.expected_production)), 0) AS target,
                    COALESCE(SUM(hf.actual_production), 0) AS actual
             FROM harvest_forecasts hf
             INNER JOIN plantings p ON p.id = hf.planting_id
             INNER JOIN farmers f ON f.id = hf.farmer_id' . $scope,
            $values
        ) ?? ['forecasts' => 0, 'expected' => 0, 'target' => 0, 'actual' => 0];

        return $row;
    }

    /**
     * @param array<string,mixed> $values
     */
    private function forecastScope(array &$values): string
    {
        $farmerId  = $this->auth->farmerId();
        $officerId = $this->auth->officerId();

        if ($farmerId !== null) {
            $values['scope_farmer'] = $farmerId;

            return ' AND hf.farmer_id = :scope_farmer';
        }

        if ($officerId !== null) {
            $values['scope_officer'] = $officerId;

            return ' AND hf.farmer_id IN (SELECT id FROM farmers WHERE officer_id = :scope_officer)';
        }

        return '';
    }

    /**
     * @param array<string,mixed> $values
     */
    private function scopeWhere(array &$values): string
    {
        $farmerId  = $this->auth->farmerId();
        $officerId = $this->auth->officerId();

        if ($farmerId !== null) {
            $values['scope_farmer'] = $farmerId;

            return ' AND hr.farmer_id = :scope_farmer';
        }

        if ($officerId !== null) {
            $values['scope_officer'] = $officerId;

            return ' AND hr.farmer_id IN (SELECT id FROM farmers WHERE officer_id = :scope_officer)';
        }

        return '';
    }

    private function assertScope(array $harvest): void
    {
        $farmerId = $this->auth->farmerId();

        if ($farmerId !== null && (int) $harvest['farmer_id'] !== $farmerId) {
            throw new HttpException(404, 'That harvest record could not be found.');
        }
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function yearOptions(): array
    {
        $years = $this->db->select(
            'SELECT DISTINCT YEAR(harvest_date) AS y FROM harvest_records
             UNION SELECT DISTINCT YEAR(CURDATE()) ORDER BY y DESC LIMIT 12'
        );

        return array_map(
            static fn (array $row): array => ['value' => (string) $row['y'], 'label' => (string) $row['y']],
            $years
        );
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'title'  => 'What was harvested',
                'fields' => [
                    ['name' => 'harvest_code', 'label' => 'Harvest ID', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued automatically.'],
                    ['name' => 'planting_id', 'label' => 'Planting', 'type' => Form::SELECT, 'required' => true,
                     'colspan' => 12, 'options' => $this->plantingOptions(),
                     'help' => 'The crop and plot are taken from the planting, so the origin chain cannot be broken.'],
                    ['name' => 'harvest_date', 'label' => 'Harvest date', 'type' => Form::DATE, 'required' => true,
                     'colspan' => 6, 'default' => date('Y-m-d')],
                    ['name' => 'acreage_harvested', 'label' => 'Area harvested (acres)', 'type' => Form::NUMBER,
                     'min' => 0, 'step' => 0.001, 'colspan' => 6],
                    ['name' => 'quantity_harvested', 'label' => 'Quantity harvested', 'type' => Form::NUMBER,
                     'required' => true, 'min' => 0.001, 'step' => 0.001, 'colspan' => 6],
                    ['name' => 'unit', 'label' => 'Unit', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions("'kg','tonnes','crates','bags','bunches','items',''", 'Select unit...')],
                    ['name' => 'quality', 'label' => 'Quality grade', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions("'premium','grade1','grade2','grade3','reject',''", 'Not graded')],
                    ['name' => 'grade', 'label' => 'Grading note', 'type' => Form::TEXT, 'colspan' => 6,
                     'placeholder' => 'e.g. no visible blemishes'],
                ],
            ],
            [
                'title'  => 'Condition and storage',
                'fields' => [
                    ['name' => 'moisture_content', 'label' => 'Moisture content (%)', 'type' => Form::NUMBER,
                     'min' => 0, 'max' => 100, 'step' => 0.1, 'colspan' => 6],
                    ['name' => 'losses', 'label' => 'Estimated losses', 'type' => Form::NUMBER,
                     'min' => 0, 'max' => 100, 'step' => 0.1, 'colspan' => 6],
                    ['name' => 'loss_unit', 'label' => 'Loss unit', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions("'%','kg','tonnes',''", 'Not recorded')],
                    ['name' => 'storage_location', 'label' => 'Storage location', 'type' => Form::TEXT, 'colspan' => 6,
                     'help' => 'Where the produce is being kept while it waits for a buyer.'],
                    ['name' => 'officer_id', 'label' => 'Verifying officer', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->officerOptions(), 'table' => 'field_officers'],
                    ['name' => 'notes', 'label' => 'Notes', 'type' => Form::TEXTAREA, 'colspan' => 12],
                ],
            ],
        ];
    }

    /**
     * @return array<int,array{value:int,label:string}>
     */
    private function plantingOptions(): array
    {
        $rows = $this->db->select(
            'SELECT p.id, p.planting_code, p.planting_date, p.status, c.name AS crop_name, f.full_name
             FROM plantings p
             INNER JOIN crops c ON c.id = p.crop_id
             INNER JOIN farmers f ON f.id = p.farmer_id
             WHERE p.status NOT IN (\'terminated\')
             ORDER BY p.planting_date DESC LIMIT 400'
        );

        return array_map(
            static fn (array $row): array => [
                'value' => (int) $row['id'],
                'label' => sprintf(
                    '%s — %s (%s, %s)',
                    $row['crop_name'],
                    $row['full_name'],
                    $row['planting_code'],
                    format_date((string) $row['planting_date'])
                ),
            ],
            $rows
        );
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }

    private function findByCodeOrFail(string $code): array
    {
        $harvest = $this->db->selectOne(
            'SELECT hr.*, c.name AS crop_name, c.primary_unit AS crop_unit,
                    f.full_name, f.farmer_code, g.garden_code, g.name AS garden_name,
                    p.planting_code, p.acreage_planted, p.expected_harvest_date,
                    o.full_name AS officer_name
             FROM harvest_records hr
             INNER JOIN farmers f ON f.id = hr.farmer_id
             LEFT JOIN crops c ON c.id = hr.crop_id
             LEFT JOIN gardens g ON g.id = hr.garden_id
             LEFT JOIN plantings p ON p.id = hr.planting_id
             LEFT JOIN field_officers o ON o.id = hr.officer_id
             WHERE hr.harvest_code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($harvest === null) {
            throw new HttpException(404, 'No harvest record found with the ID ' . $code . '.');
        }

        return $harvest;
    }
}