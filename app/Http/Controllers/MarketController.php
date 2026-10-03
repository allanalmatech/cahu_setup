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

/**
 * Markets and market demand. Two related but distinct registers live here: the
 * venues the cooperative trades through (markets), and the standing demand
 * buyers place against the platform (market_requirements). The module closes
 * with the supply-versus-demand balance, which compares what the open seasons
 * are expected to yield and what has actually been harvested against the
 * quantity already committed to buyers.
 */
final class MarketController extends Controller
{
    private const MARKET_TYPES = "'local','regional','national','export'";

    private const DEMAND_STATUSES = "'open','partially_filled','fulfilled','cancelled','expired'";

    private const QUANTITY_UNITS = "'KG','Tonnes','Crates','Bags','Bunches','Items'";

    private const PRICE_UNITS = "'per kg','per tonne','per crate','per bag','per bunch','per item'";

    /** Quantity expressed in kilograms, keyed by the unit string it converts from. */
    private const MASS_TO_KG = [
        'g'      => 0.001,
        'kg'     => 1.0,
        'kgs'    => 1.0,
        'kilogram' => 1.0,
        't'      => 1000.0,
        'tonne'  => 1000.0,
        'tonnes' => 1000.0,
        'ton'    => 1000.0,
        'mt'     => 1000.0,
    ];

    private Lookups $lookups;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups = new Lookups($db);
    }

    /* ------------------------------------------------------------------ */
    /* Markets                                                             */
    /* ------------------------------------------------------------------ */

    public function index(): Response
    {
        $params = $this->listParams();

        $params['filters']['market_type'] = $this->request->string('market_type');

        $where  = ['1 = 1'];
        $values = [];

        if ($params['search'] !== '') {
            // Emulated prepares are off, so a named placeholder may not be
            // reused across the statement; each column gets its own binding.
            $where[] = '(m.name LIKE :s1 OR m.market_code LIKE :s2 OR m.contact_person LIKE :s3 OR m.phone LIKE :s4)';
            $values['s1'] = '%' . $params['search'] . '%';
            $values['s2'] = '%' . $params['search'] . '%';
            $values['s3'] = '%' . $params['search'] . '%';
            $values['s4'] = '%' . $params['search'] . '%';
        }

        foreach ([
            'status'      => 'm.status',
            'market_type' => 'm.market_type',
            'district_id' => 'm.district_id',
        ] as $key => $column) {
            if (($params['filters'][$key] ?? '') !== '') {
                $where[]      = $column . ' = :' . $key;
                $values[$key] = $params['filters'][$key];
            }
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total     = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM markets m LEFT JOIN districts d ON d.id = m.district_id' . $whereSql,
            $values
        );
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $rows = $this->db->select(
            'SELECT m.id, m.market_code, m.name, m.market_type, m.address, m.contact_person, m.phone,
                    m.status, m.created_at, d.name AS district_name,
                    (SELECT COUNT(*) FROM market_requirements mr
                      WHERE mr.market_id = m.id AND mr.status IN (\'open\', \'partially_filled\')) AS open_requirements,
                    (SELECT COALESCE(SUM(mr.quantity_required), 0) FROM market_requirements mr
                      WHERE mr.market_id = m.id AND mr.status IN (\'open\', \'partially_filled\')) AS demanded_volume
             FROM markets m
             LEFT JOIN districts d ON d.id = m.district_id'
            . $whereSql
            . ' ORDER BY ' . $this->marketSortClause($params['sort'], $params['direction'])
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('markets/index', [
            'pageTitle' => 'Markets',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $params['sort'],
            'direction' => $params['direction'],
            'filters'   => [
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions("'active','inactive'", 'All statuses')],
                ['key' => 'market_type', 'label' => 'Type', 'options' => Form::enumOptions(self::MARKET_TYPES, 'All types')],
                ['key' => 'district_id', 'label' => 'District', 'options' => $this->lookups->districtOptions()],
            ],
        ]);
    }

    private function marketSortClause(string $sort, string $direction): string
    {
        $allowed = ['name', 'market_code', 'created_at', 'status', 'market_type'];
        $column  = in_array($sort, $allowed, true) ? $sort : 'created_at';
        $order   = $direction === 'asc' ? 'ASC' : 'DESC';

        return 'm.' . $column . ' ' . $order;
    }

    public function create(): Response
    {
        return $this->render('markets/form', [
            'pageTitle' => 'Register market',
            'record'    => null,
            'sections'  => $this->marketSections(),
        ]);
    }

    public function store(): Response
    {
        $this->assertCsrf();

        $sections = $this->marketSections();
        $fields   = array_merge(...array_column($sections, 'fields'));
        $input    = $this->request->all();

        $validator = Form::validate($fields, $input);

        if ($validator->fails()) {
            return $this->backWithErrors(url('markets/create'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, [
            'region_id', 'district_id', 'address', 'contact_person', 'phone', 'capacity_notes',
        ]);

        $result = $this->db->transaction(function () use ($values): array {
            // Minted inside the transaction that inserts the row so a failed
            // insert never burns a number, and returned out because $values is
            // captured by value and cannot be amended here.
            $values['market_code'] = Identifier::generate('market');
            $id                    = $this->db->insert('markets', $values);

            return ['id' => $id, 'code' => $values['market_code']];
        });

        Audit::log('create', 'markets', 'market', $result['id'], $result['code'], null, $values);

        Flash::success('Market ' . $result['code'] . ' (' . $values['name'] . ') has been registered.');

        return $this->redirect(url('markets?search=' . rawurlencode((string) $result['code'])));
    }

    public function edit(string $code): Response
    {
        $market = $this->findMarketByCodeOrFail($code);

        return $this->render('markets/form', [
            'pageTitle' => 'Edit ' . $market['market_code'],
            'record'    => $market,
            'sections'  => $this->marketSections(),
        ]);
    }

    public function update(string $code): Response
    {
        $this->assertCsrf();

        $market = $this->findMarketByCodeOrFail($code);
        $input  = $this->request->all();

        $sections = $this->marketSections();
        $fields   = array_merge(...array_column($sections, 'fields'));

        $validator = Form::validate($fields, $input);

        if ($validator->fails()) {
            return $this->backWithErrors(url('markets/' . $code . '/edit'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, [
            'region_id', 'district_id', 'address', 'contact_person', 'phone', 'capacity_notes',
        ]);

        $this->db->update('markets', $values, 'id = :id', ['id' => $market['id']]);

        Audit::log('update', 'markets', 'market', (int) $market['id'], (string) $market['market_code'], $market, $values);

        Flash::success('Market record updated.');

        return $this->redirect(url('markets?search=' . rawurlencode((string) $market['market_code'])));
    }

    /**
     * A market with live demand against it is deactivated rather than removed,
     * because sales and requirements already reference it.
     */
    public function destroy(string $code): Response
    {
        $this->assertCsrf();

        $market = $this->findMarketByCodeOrFail($code);
        $id     = (int) $market['id'];

        $requirements = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM market_requirements WHERE market_id = :id AND status IN ('open', 'partially_filled')",
            ['id' => $id]
        );

        $sales = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM sales WHERE market_id = :id AND status <> 'cancelled'",
            ['id' => $id]
        );

        if ($requirements > 0) {
            Flash::error(
                'This market still carries ' . $requirements . ' open requirement(s). Close or cancel '
                . 'them before the market is withdrawn, so committed demand is not lost.'
            );

            return $this->redirect(url('markets?search=' . rawurlencode((string) $market['market_code'])));
        }

        $changes = ['status' => 'inactive'];

        $this->db->update('markets', $changes, 'id = :id', ['id' => $id]);

        Audit::log(
            $sales > 0 ? 'deactivate' : 'delete',
            'markets',
            'market',
            $id,
            (string) $market['market_code'],
            $market,
            $changes
        );

        Flash::success(
            'Market ' . $market['market_code'] . ' has been deactivated. '
            . ($sales > 0
                ? 'It carries sales history, so the record is kept and the identifier is not reused.'
                : 'The identifier is retained and will never be reused.')
        );

        return $this->redirect(url('markets'));
    }

    /* ------------------------------------------------------------------ */
    /* Market demand register                                               */
    /* ------------------------------------------------------------------ */

    public function demandIndex(): Response
    {
        $params = $this->listParams();

        $where  = ['1 = 1'];
        $values = [];

        if ($params['search'] !== '') {
            // Emulated prepares are off, so a named placeholder may not be
            // reused across the statement; each column gets its own binding.
            $where[] = '(b.name LIKE :s1 OR c.name LIKE :s2 OR mr.delivery_location LIKE :s3 OR mr.requirement_code LIKE :s4)';
            $values['s1'] = '%' . $params['search'] . '%';
            $values['s2'] = '%' . $params['search'] . '%';
            $values['s3'] = '%' . $params['search'] . '%';
            $values['s4'] = '%' . $params['search'] . '%';
        }

        foreach ([
            'status'   => 'mr.status',
            'crop_id'  => 'mr.crop_id',
            'buyer_id' => 'mr.buyer_id',
        ] as $key => $column) {
            if (($params['filters'][$key] ?? '') !== '') {
                $where[]      = $column . ' = :' . $key;
                $values[$key] = $params['filters'][$key];
            }
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total     = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM market_requirements mr
             INNER JOIN buyers b ON b.id = mr.buyer_id
             INNER JOIN crops c ON c.id = mr.crop_id' . $whereSql,
            $values
        );
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $rows = $this->db->select(
            'SELECT mr.id, mr.requirement_code, mr.quantity_required, mr.unit, mr.quality_required,
                    mr.delivery_location, mr.required_date, mr.offered_price, mr.price_unit, mr.status,
                    mr.created_at, b.buyer_code, b.name AS buyer_name, c.name AS crop_name,
                    v.name AS variety_name, m.name AS market_name
             FROM market_requirements mr
             INNER JOIN buyers b ON b.id = mr.buyer_id
             INNER JOIN crops c ON c.id = mr.crop_id
             LEFT JOIN crop_varieties v ON v.id = mr.variety_id
             LEFT JOIN markets m ON m.id = mr.market_id'
            . $whereSql
            . ' ORDER BY ' . $this->demandSortClause($params['sort'], $params['direction'])
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('demand/index', [
            'pageTitle' => 'Market demand',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $params['sort'],
            'direction' => $params['direction'],
            'filters'   => [
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions(self::DEMAND_STATUSES, 'All statuses')],
                ['key' => 'crop_id', 'label' => 'Crop', 'options' => $this->lookups->cropOptions('All crops')],
                ['key' => 'buyer_id', 'label' => 'Buyer', 'options' => $this->buyerOptions('All buyers')],
            ],
        ]);
    }

    private function demandSortClause(string $sort, string $direction): string
    {
        $allowed = ['required_date', 'created_at', 'quantity_required', 'status'];
        $column  = in_array($sort, $allowed, true) ? $sort : 'required_date';
        $order   = $direction === 'asc' ? 'ASC' : 'DESC';

        return 'mr.' . $column . ' ' . $order;
    }

    public function demandCreate(): Response
    {
        // A prefill record carries only the keys the query string supplied, so
        // the form's value lookup falls back to each field's own default.
        $record = [];

        $buyer = $this->request->int('buyer_id');
        $crop  = $this->request->int('crop_id');

        if ($buyer > 0) {
            $record['buyer_id'] = $buyer;
        }

        if ($crop > 0) {
            $record['crop_id'] = $crop;
        }

        return $this->render('demand/form', [
            'pageTitle' => 'Record market demand',
            'record'    => $record === [] ? null : $record,
            'isEdit'    => false,
            'sections'  => $this->demandSections(),
        ]);
    }

    public function demandStore(): Response
    {
        $this->assertCsrf();

        $sections = $this->demandSections();
        $fields   = array_merge(...array_column($sections, 'fields'));
        $input    = $this->request->all();

        $validator = Form::validate($fields, $input);

        if ($validator->fails()) {
            return $this->backWithErrors(url('demand/create'), $validator->errors(), $input);
        }

        // A buyer's own country and region are irrelevant to the market they buy
        // into: an exporter in Kampala may deliver to a market in Gulu, and a
        // requirement is not tied to any garden. Only the referenced records
        // themselves are checked, so no cross-entity geography is enforced here.
        $values = Form::extract($fields, $input, [
            'market_id', 'variety_id', 'quality_required', 'delivery_location', 'offered_price',
            'price_unit', 'contract_terms',
        ]);

        $values['quantity_required'] = (float) $values['quantity_required'];
        $values['buyer_id']          = (int) $values['buyer_id'];
        $values['crop_id']           = (int) $values['crop_id'];
        $values['market_id']         = isset($values['market_id']) && $values['market_id'] !== null
            ? (int) $values['market_id']
            : null;
        $values['variety_id']        = isset($values['variety_id']) && $values['variety_id'] !== null
            ? (int) $values['variety_id']
            : null;

        $requiredDate = (string) $values['required_date'];

        $result = $this->db->transaction(function () use ($values, $requiredDate): array {
            $values['requirement_code'] = Identifier::generate(
                'requirement',
                \date_create_from_format('Y-m-d', $requiredDate) ?: null
            );
            $values['created_by'] = $this->auth->id();

            $id = $this->db->insert('market_requirements', $values);

            return ['id' => $id, 'code' => $values['requirement_code']];
        });

        Audit::log('create', 'markets', 'requirement', $result['id'], $result['code'], null, $values);

        Flash::success('Demand ' . $result['code'] . ' has been recorded.');

        return $this->redirect(url('demand?search=' . rawurlencode((string) $result['code'])));
    }

    public function demandEdit(string $code): Response
    {
        $requirement = $this->findRequirementOrFail($code);

        return $this->render('demand/form', [
            'pageTitle' => 'Edit ' . $requirement['requirement_code'],
            'record'    => $requirement,
            'isEdit'    => true,
            'sections'  => $this->demandSections(),
        ]);
    }

    public function demandUpdate(string $code): Response
    {
        $this->assertCsrf();

        $requirement = $this->findRequirementOrFail($code);
        $input       = $this->request->all();

        $sections = $this->demandSections();
        $fields   = array_merge(...array_column($sections, 'fields'));

        $validator = Form::validate($fields, $input);

        if ($validator->fails()) {
            return $this->backWithErrors(url('demand/' . $code . '/edit'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, [
            'market_id', 'variety_id', 'quality_required', 'delivery_location', 'offered_price',
            'price_unit', 'contract_terms',
        ]);

        $values['quantity_required'] = (float) $values['quantity_required'];
        $values['buyer_id']          = (int) $values['buyer_id'];
        $values['crop_id']           = (int) $values['crop_id'];
        $values['market_id']         = isset($values['market_id']) && $values['market_id'] !== null
            ? (int) $values['market_id']
            : null;
        $values['variety_id']        = isset($values['variety_id']) && $values['variety_id'] !== null
            ? (int) $values['variety_id']
            : null;

        $this->db->update('market_requirements', $values, 'id = :id', ['id' => $requirement['id']]);

        Audit::log(
            'update',
            'markets',
            'requirement',
            (int) $requirement['id'],
            (string) $requirement['requirement_code'],
            $requirement,
            $values
        );

        Flash::success('Demand ' . $requirement['requirement_code'] . ' has been updated.');

        return $this->redirect(url('demand?search=' . rawurlencode((string) $requirement['requirement_code'])));
    }

    /**
     * A requirement that has been partly committed to cannot be withdrawn
     * without a trace, so it is cancelled instead of deleted. One that nothing
     * has happened to is simply removed.
     */
    public function demandDestroy(string $code): Response
    {
        $this->assertCsrf();

        $requirement = $this->findRequirementOrFail($code);
        $id          = (int) $requirement['id'];

        $committed = (string) $requirement['status'] === 'partially_filled'
            || (string) $requirement['status'] === 'fulfilled';

        if ($committed) {
            $changes = ['status' => 'cancelled'];

            $this->db->update('market_requirements', $changes, 'id = :id', ['id' => $id]);

            Audit::log(
                'cancel',
                'markets',
                'requirement',
                $id,
                (string) $requirement['requirement_code'],
                $requirement,
                $changes
            );

            Flash::warning(
                'Demand ' . $requirement['requirement_code'] . ' had already been committed against, '
                . 'so it has been cancelled rather than deleted. The record stays for the audit trail.'
            );

            return $this->redirect(url('demand?search=' . rawurlencode((string) $requirement['requirement_code'])));
        }

        $this->db->delete('market_requirements', 'id = :id', ['id' => $id]);

        Audit::log('delete', 'markets', 'requirement', $id, (string) $requirement['requirement_code'], $requirement);

        Flash::success('Demand ' . $requirement['requirement_code'] . ' has been removed.');

        return $this->redirect(url('demand'));
    }

    /* ------------------------------------------------------------------ */
    /* Supply versus demand                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Per-crop supply against committed demand: what the open seasons forecast,
     * what has actually been harvested, what is still sitting in an unsold
     * batch, what buyers have already committed to, and what has been sold.
     * The gap between available stock and committed demand is the number the
     * whole screen exists to answer.
     */
    public function balance(): Response
    {
        $cropId = $this->request->int('crop_id');

        $cropWhere  = "c.status = 'active'";
        $cropValues = [];

        if ($cropId > 0) {
            $cropWhere       .= ' AND c.id = :crop_id';
            $cropValues['crop_id'] = $cropId;
        }

        $crops = $this->db->select(
            'SELECT c.id, c.name, c.crop_code, c.primary_unit
             FROM crops c
             WHERE ' . $cropWhere . '
             ORDER BY c.name',
            $cropValues
        );

        $expected = $this->quantityByCropAndUnit(
            "SELECT hf.crop_id, hf.unit,
                    SUM(CASE WHEN hf.revised_production IS NOT NULL THEN hf.revised_production
                             ELSE hf.expected_production END) AS quantity
             FROM harvest_forecasts hf
             INNER JOIN seasons s ON s.id = hf.season_id
             WHERE s.status = 'open'
             GROUP BY hf.crop_id, hf.unit"
        );

        $harvested = $this->quantityByCropAndUnit(
            "SELECT hr.crop_id, hr.unit, SUM(hr.quantity_harvested) AS quantity
             FROM harvest_records hr
             WHERE hr.status <> 'cancelled'
             GROUP BY hr.crop_id, hr.unit"
        );

        $available = $this->quantityByCropAndUnit(
            "SELECT hb.crop_id, hb.unit, SUM(hb.total_quantity) AS quantity
             FROM harvest_batches hb
             WHERE hb.status IN ('open', 'aggregated')
             GROUP BY hb.crop_id, hb.unit"
        );

        $committed = $this->quantityByCropAndUnit(
            "SELECT mr.crop_id, mr.unit, SUM(mr.quantity_required) AS quantity
             FROM market_requirements mr
             WHERE mr.status IN ('open', 'partially_filled')
             GROUP BY mr.crop_id, mr.unit"
        );

        $sold = $this->quantityByCropAndUnit(
            "SELECT si.crop_id, si.unit, SUM(si.quantity) AS quantity
             FROM sale_items si
             INNER JOIN sales s ON s.id = si.sale_id
             WHERE s.status <> 'cancelled'
             GROUP BY si.crop_id, si.unit"
        );

        $byUnit = [
            'expected'  => $expected,
            'harvested' => $harvested,
            'available' => $available,
            'committed' => $committed,
            'sold'      => $sold,
        ];

        $rows   = [];
        $totals = ['surplus' => 0.0, 'shortfall' => 0.0, 'crops_short' => 0];

        foreach ($crops as $crop) {
            $key  = (int) $crop['id'];
            $unit = $this->displayUnit($crop);

            $row = [
                'crop_id'   => $key,
                'crop'      => (string) $crop['name'],
                'crop_code' => (string) $crop['crop_code'],
                'unit'      => $unit,
            ];

            foreach (['expected', 'harvested', 'available', 'committed', 'sold'] as $measure) {
                $row[$measure] = round($this->combine($byUnit[$measure][$key] ?? [], $unit), 3);
            }

            $surplus      = round($row['available'] - $row['committed'], 3);
            $hasMovement  = $row['available'] > 0.0 || $row['committed'] > 0.0;

            $row['surplus']     = $surplus;
            $row['is_surplus']  = $surplus >= 0.0;
            $row['has_activity'] = $hasMovement;
            $row['verdict']     = $this->verdict($surplus, $row['committed'], $unit);

            if ($hasMovement) {
                if ($surplus >= 0.0) {
                    $totals['surplus'] += $surplus;
                } else {
                    $totals['shortfall'] += abs($surplus);
                    $totals['crops_short']++;
                }
            }

            $rows[] = $row;
        }

        return $this->render('demand/balance', [
            'pageTitle'  => 'Supply versus demand',
            'rows'       => $rows,
            'totals'     => $totals,
            'crops'      => $this->lookups->crops(),
            'cropId'     => $cropId,
            // The layout reads these, not the template, so they are supplied
            // here: a variable assigned inside the template is scoped to it.
            'chartJs'    => true,
            'pageScripts' => [$this->balanceChartScript($rows)],
        ]);
    }

    /**
     * The grouped bar chart of expected, harvested, available and committed
     * quantity per crop. Data is embedded with the HEX flags so no value can
     * close the script element or introduce markup.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function balanceChartScript(array $rows): string
    {
        $payload = json_encode(
            [
                'labels' => array_map(static fn (array $row): string => (string) $row['crop'], $rows),
                'series' => [
                    'Expected'  => array_map(static fn (array $row): float => (float) $row['expected'], $rows),
                    'Harvested' => array_map(static fn (array $row): float => (float) $row['harvested'], $rows),
                    'Available' => array_map(static fn (array $row): float => (float) $row['available'], $rows),
                    'Committed' => array_map(static fn (array $row): float => (float) $row['committed'], $rows),
                ],
            ],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return <<<JS
        (function () {
            var canvas = document.getElementById('supplyDemandChart');
            if (!canvas || typeof Chart === 'undefined') { return; }

            var data = {$payload};

            var palette = {
                Expected:  'rgba(61, 143, 209, .85)',
                Harvested: 'rgba(78, 140, 74, .85)',
                Available: 'rgba(122, 106, 62, .85)',
                Committed: 'rgba(214, 137, 16, .85)'
            };

            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: Object.keys(data.series).map(function (key) {
                        return {
                            label: key,
                            data: data.series[key],
                            backgroundColor: palette[key],
                            borderRadius: 3,
                            maxBarThickness: 26
                        };
                    })
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 } },
                        x: { ticks: { autoSkip: true, maxRotation: 45 } }
                    },
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    return context.dataset.label + ': ' + context.parsed.y.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        })();
        JS;
    }

    /**
     * One sentence a reader can act on, rather than a bare number.
     */
    private function verdict(float $surplus, float $committed, string $unit): string
    {
        if ($surplus >= 0.0) {
            if ($committed <= 0.0) {
                return 'No committed demand; ' . format_number($surplus) . ' ' . $unit . ' is unallocated.';
            }

            return 'Surplus of ' . format_number($surplus) . ' ' . $unit . ' over committed demand.';
        }

        return 'Shortfall of ' . format_number(abs($surplus)) . ' ' . $unit . ' against committed demand.';
    }

    /* ------------------------------------------------------------------ */
    /* Supporting data                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function marketSections(): array
    {
        return [
            [
                'title'  => 'Market identity',
                'fields' => [
                    ['name' => 'market_code', 'label' => 'Market code', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued automatically when the record is saved.'],
                    ['name' => 'name', 'label' => 'Market name', 'type' => Form::TEXT, 'required' => true, 'colspan' => 8],
                    ['name' => 'market_type', 'label' => 'Market type', 'type' => Form::SELECT, 'required' => true, 'colspan' => 4,
                     'options' => Form::enumOptions(self::MARKET_TYPES, '')],
                    ['name' => 'status', 'label' => 'Status', 'type' => Form::SELECT, 'required' => true, 'colspan' => 4,
                     'options' => Form::enumOptions("'active','inactive'", '')],
                ],
            ],
            [
                'title'  => 'Location and contact',
                'fields' => [
                    ['name' => 'region_id', 'label' => 'Region', 'type' => Form::SELECT, 'colspan' => 4,
                     'options' => Form::options($this->lookups->regions(), 'id', 'name', 'Select region...')],
                    ['name' => 'district_id', 'label' => 'District', 'type' => Form::SELECT, 'colspan' => 4,
                     'options' => $this->lookups->districtOptions('Select district...')],
                    ['name' => 'address', 'label' => 'Address', 'type' => Form::TEXT, 'colspan' => 4],
                    ['name' => 'contact_person', 'label' => 'Contact person', 'type' => Form::TEXT, 'colspan' => 6],
                    ['name' => 'phone', 'label' => 'Phone number', 'type' => Form::TEL, 'colspan' => 6],
                    ['name' => 'capacity_notes', 'label' => 'Capacity and handling notes', 'type' => Form::TEXTAREA, 'colspan' => 12,
                     'help' => 'Throughput, storage, opening days, any handling requirement.'],
                ],
            ],
        ];
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function demandSections(): array
    {
        return [
            [
                'title'  => 'Who is asking',
                'fields' => [
                    ['name' => 'requirement_code', 'label' => 'Requirement ID', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued automatically when the demand is recorded.'],
                    ['name' => 'buyer_id', 'label' => 'Buyer', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => $this->buyerOptions()],
                    ['name' => 'market_id', 'label' => 'Market', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->marketOptions()],
                ],
            ],
            [
                'title'  => 'What is needed',
                'fields' => [
                    ['name' => 'crop_id', 'label' => 'Crop', 'type' => Form::SELECT, 'required' => true, 'colspan' => 4,
                     'options' => $this->lookups->cropOptions()],
                    ['name' => 'variety_id', 'label' => 'Variety', 'type' => Form::SELECT, 'colspan' => 4,
                     'options' => $this->lookups->varietyOptions('Any variety')],
                    ['name' => 'unit', 'label' => 'Quantity unit', 'type' => Form::SELECT, 'required' => true, 'colspan' => 4,
                     'options' => Form::enumOptions(self::QUANTITY_UNITS, ''), 'default' => 'KG'],
                    ['name' => 'quantity_required', 'label' => 'Quantity required', 'type' => Form::NUMBER, 'required' => true,
                     'min' => 0.001, 'step' => '0.001', 'colspan' => 6],
                    ['name' => 'required_date', 'label' => 'Required by', 'type' => Form::DATE, 'required' => true, 'colspan' => 6],
                    ['name' => 'quality_required', 'label' => 'Quality required', 'type' => Form::TEXT, 'colspan' => 12,
                     'help' => 'Grade, moisture, size or certification the buyer insists on.'],
                    ['name' => 'delivery_location', 'label' => 'Delivery location', 'type' => Form::TEXT, 'colspan' => 12],
                ],
            ],
            [
                'title'  => 'Commercial terms',
                'fields' => [
                    ['name' => 'offered_price', 'label' => 'Offered price', 'type' => Form::NUMBER, 'min' => 0, 'step' => '0.01', 'colspan' => 6],
                    ['name' => 'price_unit', 'label' => 'Price unit', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions(self::PRICE_UNITS, 'Not set')],
                    ['name' => 'status', 'label' => 'Status', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions(self::DEMAND_STATUSES, ''), 'default' => 'open'],
                    ['name' => 'contract_terms', 'label' => 'Contract terms', 'type' => Form::TEXTAREA, 'colspan' => 12],
                ],
            ],
        ];
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function buyerOptions(string $placeholder = 'Select buyer...'): array
    {
        return Form::options(
            $this->db->select(
                "SELECT b.id, b.buyer_code, b.name, d.name AS district_name
                 FROM buyers b
                 LEFT JOIN districts d ON d.id = b.district_id
                 WHERE b.deleted_at IS NULL AND b.status <> 'suspended'
                 ORDER BY b.name"
            ),
            'id',
            'name',
            $placeholder
        );
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function marketOptions(string $placeholder = 'No specific market'): array
    {
        return Form::options(
            $this->db->select(
                "SELECT id, name, market_type FROM markets WHERE status = 'active' ORDER BY name"
            ),
            'id',
            'name',
            $placeholder
        );
    }

    /**
     * Runs an aggregate grouped by crop and unit and normalises the result to a
     * flat map keyed by crop id.
     *
     * @return array<int,array<string,float>>
     */
    private function quantityByCropAndUnit(string $sql): array
    {
        $rows   = $this->db->select($sql);
        $result = [];

        foreach ($rows as $row) {
            $cropKey = (int) $row['crop_id'];
            $unit    = (string) ($row['unit'] ?? '');

            $result[$cropKey][$unit] = ($result[$cropKey][$unit] ?? 0.0) + (float) $row['quantity'];
        }

        return $result;
    }

    /**
     * Converts a per-unit quantity map into one figure expressed in the crop's
     * display unit. Masses are converted through kilograms; a countable unit
     * that the display unit does not share is carried through unscaled, because
     * inventing a conversion factor would produce a confidently wrong total.
     *
     * @param array<string,float> $byUnit
     */
    private function combine(array $byUnit, string $displayUnit): float
    {
        $quantity = 0.0;

        if ($byUnit === []) {
            return $quantity;
        }

        $target     = strtolower(trim($displayUnit));
        $targetFact = $this->massFactor($target);

        foreach ($byUnit as $unit => $value) {
            $factor = $this->massFactor(strtolower(trim((string) $unit)));

            if ($factor !== null && $targetFact !== null) {
                $quantity += $value * $factor / $targetFact;

                continue;
            }

            $quantity += $value;
        }

        return $quantity;
    }

    private function massFactor(string $unit): ?float
    {
        return self::MASS_TO_KG[$unit] ?? null;
    }

    /**
     * The unit the balance is expressed in. A crop's own primary unit is used
     * when it is set, otherwise kilograms.
     *
     * @param array<string,mixed> $crop
     */
    private function displayUnit(array $crop): string
    {
        $unit = trim((string) ($crop['primary_unit'] ?? ''));

        return $unit === '' ? 'KG' : $unit;
    }

    private function findMarketByCodeOrFail(string $code): array
    {
        $market = $this->db->selectOne(
            'SELECT m.*, r.name AS region_name, d.name AS district_name
             FROM markets m
             LEFT JOIN regions r ON r.id = m.region_id
             LEFT JOIN districts d ON d.id = m.district_id
             WHERE m.market_code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($market === null) {
            throw new HttpException(404, 'No market found with the code ' . $code . '.');
        }

        return $market;
    }

    private function findRequirementOrFail(string $code): array
    {
        $requirement = $this->db->selectOne(
            'SELECT mr.*, b.buyer_code, b.name AS buyer_name, c.name AS crop_name, v.name AS variety_name,
                    m.name AS market_name
             FROM market_requirements mr
             INNER JOIN buyers b ON b.id = mr.buyer_id
             INNER JOIN crops c ON c.id = mr.crop_id
             LEFT JOIN crop_varieties v ON v.id = mr.variety_id
             LEFT JOIN markets m ON m.id = mr.market_id
             WHERE mr.requirement_code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($requirement === null) {
            throw new HttpException(404, 'No market requirement found with the ID ' . $code . '.');
        }

        return $requirement;
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }
}