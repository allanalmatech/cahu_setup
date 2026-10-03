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
 * Buyers: the counterparties the cooperative sells to, and the demand they place
 * on the platform. This controller owns the buyer register, the buyer record
 * (contact block, open demand, sales and delivery history) and the lifecycle of
 * the buyer record itself. Sales, deliveries and exports are owned elsewhere;
 * this controller only reads them so the buyer profile is complete.
 */
final class BuyerController extends Controller
{
    private const BUYER_TYPES = "'local','regional','processor','exporter','wholesaler','retailer','cooperative','institutional'";

    private const PRICE_UNITS = "'per kg','per tonne','per crate','per bag','per bunch','per item'";

    private const NULLABLE_BUYER_COLUMNS = [
        'country', 'region_id', 'district_id', 'address', 'contact_person', 'phone', 'email',
        'quality_requirements', 'default_price', 'price_unit', 'contract_ref', 'notes',
    ];

    private Lookups $lookups;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
        $this->lookups = new Lookups($db);
    }

    public function index(): Response
    {
        $params = $this->listParams();

        $params['filters']['buyer_type'] = $this->request->string('buyer_type');

        $where  = ['b.deleted_at IS NULL'];
        $values = [];

        if ($params['search'] !== '') {
            // Emulated prepares are off, so a named placeholder may not be
            // reused across the statement; each column gets its own binding.
            $where[]      = '(b.name LIKE :s1 OR b.buyer_code LIKE :s2 OR b.contact_person LIKE :s3
                          OR b.phone LIKE :s4 OR b.email LIKE :s5 OR b.contract_ref LIKE :s6)';
            $values['s1'] = '%' . $params['search'] . '%';
            $values['s2'] = '%' . $params['search'] . '%';
            $values['s3'] = '%' . $params['search'] . '%';
            $values['s4'] = '%' . $params['search'] . '%';
            $values['s5'] = '%' . $params['search'] . '%';
            $values['s6'] = '%' . $params['search'] . '%';
        }

        foreach ([
            'status'      => 'b.status',
            'buyer_type'  => 'b.buyer_type',
            'district_id' => 'b.district_id',
        ] as $key => $column) {
            if (($params['filters'][$key] ?? '') !== '') {
                $where[]      = $column . ' = :' . $key;
                $values[$key] = $params['filters'][$key];
            }
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total     = (int) $this->db->scalar('SELECT COUNT(*) FROM buyers b' . $whereSql, $values);
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $rows = $this->db->select(
            'SELECT b.id, b.buyer_code, b.name, b.buyer_type, b.country, b.contact_person, b.phone,
                    b.email, b.quality_requirements, b.contract_ref, b.status, b.created_at,
                    d.name AS district_name,
                    (SELECT COUNT(*) FROM market_requirements mr
                      WHERE mr.buyer_id = b.id AND mr.status IN (\'open\', \'partially_filled\')) AS open_demand
             FROM buyers b
             LEFT JOIN districts d ON d.id = b.district_id'
            . $whereSql
            . ' ORDER BY ' . $this->sortClause($params['sort'], $params['direction'])
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('buyers/index', [
            'pageTitle' => 'Buyers',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $params['sort'],
            'direction' => $params['direction'],
            'filters'   => [
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions("'active','inactive','suspended'", 'All statuses')],
                ['key' => 'buyer_type', 'label' => 'Type', 'options' => Form::enumOptions(self::BUYER_TYPES, 'All types')],
                ['key' => 'district_id', 'label' => 'District', 'options' => $this->lookups->districtOptions()],
            ],
        ]);
    }

    private function sortClause(string $sort, string $direction): string
    {
        $allowed = ['name', 'buyer_code', 'created_at', 'status', 'buyer_type'];
        $column  = in_array($sort, $allowed, true) ? $sort : 'created_at';
        $order   = $direction === 'asc' ? 'ASC' : 'DESC';

        return 'b.' . $column . ' ' . $order;
    }

    public function create(): Response
    {
        return $this->render('buyers/form', [
            'pageTitle' => 'Register buyer',
            'record'    => null,
            'sections'  => $this->sections(),
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
            return $this->backWithErrors(url('buyers/create'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, self::NULLABLE_BUYER_COLUMNS);

        $result = $this->db->transaction(function () use ($values): array {
            // The identifier is minted inside the transaction that inserts the
            // row, so a failed insert never burns a number. It is returned
            // rather than read back off $values, which the closure received by
            // value and cannot amend.
            $values['buyer_code'] = Identifier::generate('buyer');
            $id                   = $this->db->insert('buyers', $values);

            return ['id' => $id, 'code' => $values['buyer_code']];
        });

        Audit::log('create', 'buyers', 'buyer', $result['id'], $result['code'], null, $values);

        Flash::success('Buyer ' . $result['code'] . ' (' . $values['name'] . ') has been registered.');

        return $this->redirect(url('buyers/' . $result['code']));
    }

    public function show(string $code): Response
    {
        $buyer = $this->findByCodeOrFail($code);
        $id    = (int) $buyer['id'];

        $requirements = $this->db->select(
            "SELECT mr.requirement_code, mr.quantity_required, mr.unit, mr.quality_required,
                    mr.delivery_location, mr.required_date, mr.offered_price, mr.price_unit, mr.status,
                    c.name AS crop_name, v.name AS variety_name, m.name AS market_name
             FROM market_requirements mr
             INNER JOIN crops c ON c.id = mr.crop_id
             LEFT JOIN crop_varieties v ON v.id = mr.variety_id
             LEFT JOIN markets m ON m.id = mr.market_id
             WHERE mr.buyer_id = :id AND mr.status IN ('open', 'partially_filled')
             ORDER BY mr.required_date ASC",
            ['id' => $id]
        );

        $sales = $this->db->select(
            'SELECT s.sale_code, s.sale_date, s.total_value, s.currency, s.amount_paid,
                    s.payment_status, s.status, m.name AS market_name
             FROM sales s
             LEFT JOIN markets m ON m.id = s.market_id
             WHERE s.buyer_id = :id
             ORDER BY s.sale_date DESC, s.id DESC
             LIMIT 50',
            ['id' => $id]
        );

        $deliveries = $this->db->select(
            'SELECT d.delivery_code, d.dispatch_date, d.expected_date, d.delivered_date,
                    d.delivery_location, d.status, s.sale_code
             FROM deliveries d
             INNER JOIN sales s ON s.id = d.sale_id
             WHERE s.buyer_id = :id
             ORDER BY COALESCE(d.dispatch_date, d.expected_date) DESC, d.id DESC
             LIMIT 50',
            ['id' => $id]
        );

        $totals = [
            'sales_value'  => (float) $this->db->scalar(
                "SELECT COALESCE(SUM(s.total_value), 0) FROM sales s WHERE s.buyer_id = :id AND s.status <> 'cancelled'",
                ['id' => $id]
            ),
            'amount_paid'  => (float) $this->db->scalar(
                "SELECT COALESCE(SUM(s.amount_paid), 0) FROM sales s WHERE s.buyer_id = :id AND s.status <> 'cancelled'",
                ['id' => $id]
            ),
            'open_demand'  => (int) $this->db->scalar(
                "SELECT COUNT(*) FROM market_requirements WHERE buyer_id = :id AND status IN ('open', 'partially_filled')",
                ['id' => $id]
            ),
            'open_volume'  => (float) $this->db->scalar(
                "SELECT COALESCE(SUM(quantity_required), 0) FROM market_requirements
                 WHERE buyer_id = :id AND status IN ('open', 'partially_filled')",
                ['id' => $id]
            ),
            'sale_count'   => (int) $this->db->scalar(
                "SELECT COUNT(*) FROM sales WHERE buyer_id = :id AND status <> 'cancelled'",
                ['id' => $id]
            ),
        ];

        return $this->render('buyers/show', [
            'pageTitle'    => $buyer['name'],
            'buyer'        => $buyer,
            'requirements' => $requirements,
            'sales'        => $sales,
            'deliveries'   => $deliveries,
            'totals'       => $totals,
        ]);
    }

    public function edit(string $code): Response
    {
        $buyer = $this->findByCodeOrFail($code);

        return $this->render('buyers/form', [
            'pageTitle' => 'Edit ' . $buyer['buyer_code'],
            'record'    => $buyer,
            'sections'  => $this->sections(),
        ]);
    }

    public function update(string $code): Response
    {
        $this->assertCsrf();

        $buyer = $this->findByCodeOrFail($code);
        $input = $this->request->all();

        $sections = $this->sections();
        $fields   = array_merge(...array_column($sections, 'fields'));

        $validator = Form::validate($fields, $input);

        if ($validator->fails()) {
            return $this->backWithErrors(url('buyers/' . $code . '/edit'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, self::NULLABLE_BUYER_COLUMNS);

        $this->db->update('buyers', $values, 'id = :id', ['id' => $buyer['id']]);

        Audit::log('update', 'buyers', 'buyer', (int) $buyer['id'], (string) $buyer['buyer_code'], $buyer, $values);

        Flash::success('Buyer record updated.');

        return $this->redirect(url('buyers/' . $buyer['buyer_code']));
    }

    public function destroy(string $code): Response
    {
        $this->assertCsrf();

        $buyer = $this->findByCodeOrFail($code);
        $id    = (int) $buyer['id'];

        $sales = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM sales WHERE buyer_id = :id AND status <> 'cancelled'",
            ['id' => $id]
        );

        if ($sales > 0) {
            Flash::error(
                'This buyer has ' . $sales . ' recorded sale(s). A buyer that has traded cannot be '
                . 'removed, because the sales history must stay intact. Suspend the buyer instead.'
            );

            return $this->redirect(url('buyers/' . $buyer['buyer_code']));
        }

        $changes = [
            'status'     => 'inactive',
            'deleted_at' => date('Y-m-d H:i:s'),
        ];

        $this->db->update('buyers', $changes, 'id = :id', ['id' => $id]);

        Audit::log('delete', 'buyers', 'buyer', $id, (string) $buyer['buyer_code'], $buyer, $changes);

        Flash::success(
            'Buyer ' . $buyer['buyer_code'] . ' has been deactivated. The identifier is retained and '
            . 'will never be reused.'
        );

        return $this->redirect(url('buyers'));
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'title'  => 'Buyer identity',
                'fields' => [
                    ['name' => 'buyer_code', 'label' => 'Buyer ID', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued automatically when the record is saved.'],
                    ['name' => 'name', 'label' => 'Buyer or organisation name', 'type' => Form::TEXT, 'required' => true, 'colspan' => 8],
                    ['name' => 'buyer_type', 'label' => 'Buyer type', 'type' => Form::SELECT, 'required' => true, 'colspan' => 4,
                     'options' => Form::enumOptions(self::BUYER_TYPES, '')],
                    ['name' => 'status', 'label' => 'Status', 'type' => Form::SELECT, 'required' => true, 'colspan' => 4,
                     'options' => Form::enumOptions("'active','inactive','suspended'", '')],
                    ['name' => 'contract_ref', 'label' => 'Contract reference', 'type' => Form::TEXT, 'colspan' => 8,
                     'help' => 'The agreement or purchase order this buyer trades under.'],
                ],
            ],
            [
                'title'  => 'Location',
                'fields' => [
                    ['name' => 'country', 'label' => 'Country', 'type' => Form::TEXT, 'colspan' => 4],
                    ['name' => 'region_id', 'label' => 'Region', 'type' => Form::SELECT, 'colspan' => 4,
                     'options' => Form::options($this->lookups->regions(), 'id', 'name', 'Select region...')],
                    ['name' => 'district_id', 'label' => 'District', 'type' => Form::SELECT, 'colspan' => 4,
                     'options' => $this->lookups->districtOptions('Select district...')],
                    ['name' => 'address', 'label' => 'Address', 'type' => Form::TEXT, 'colspan' => 12],
                ],
            ],
            [
                'title'  => 'Contact',
                'fields' => [
                    ['name' => 'contact_person', 'label' => 'Contact person', 'type' => Form::TEXT, 'colspan' => 6],
                    ['name' => 'phone', 'label' => 'Phone number', 'type' => Form::TEL, 'colspan' => 6,
                     'help' => 'Used for SMS confirmations on orders and deliveries.'],
                    ['name' => 'email', 'label' => 'Email address', 'type' => Form::EMAIL, 'colspan' => 12],
                ],
            ],
            [
                'title'  => 'Trading terms',
                'fields' => [
                    ['name' => 'quality_requirements', 'label' => 'Quality requirements', 'type' => Form::TEXT, 'colspan' => 12,
                     'help' => 'Grade, moisture, size or certification this buyer insists on.'],
                    ['name' => 'default_price', 'label' => 'Default price', 'type' => Form::NUMBER, 'min' => 0, 'step' => '0.01', 'colspan' => 6,
                     'help' => 'Indicative price only. Each order records the price actually agreed.'],
                    ['name' => 'price_unit', 'label' => 'Price unit', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => Form::enumOptions(self::PRICE_UNITS, 'Not set')],
                    ['name' => 'notes', 'label' => 'Notes', 'type' => Form::TEXTAREA, 'colspan' => 12],
                ],
            ],
        ];
    }

    private function findByCodeOrFail(string $code): array
    {
        $buyer = $this->db->selectOne(
            'SELECT b.*, r.name AS region_name, d.name AS district_name
             FROM buyers b
             LEFT JOIN regions r ON r.id = b.region_id
             LEFT JOIN districts d ON d.id = b.district_id
             WHERE b.buyer_code = :code AND b.deleted_at IS NULL LIMIT 1',
            ['code' => $code]
        );

        if ($buyer === null) {
            throw new HttpException(404, 'No buyer found with the ID ' . $code . '.');
        }

        return $buyer;
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }
}