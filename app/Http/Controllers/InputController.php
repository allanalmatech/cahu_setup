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
 * Input inventory, issuing and usage.
 *
 * The cooperative store is the single source of truth for what is on the shelf,
 * what has gone out to farmers and what has actually been applied in the field.
 * Those three facts are only reconcilable if they are written together, so every
 * stock movement, every issue and every usage record goes through InputLedger,
 * which owns the running balance. This controller supplies the screens, the
 * validation and the business rules around the ledger; it never adds two
 * numbers itself.
 */
final class InputController extends Controller
{
    /** The input_transactions.transaction_type ENUM, in ledger order. */
    private const MOVEMENT_TYPES = "'opening','receipt','issue','usage','adjustment','return','damage','expired'";

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

    /* ------------------------------------------------------------------ */
    /* Inventory register                                                   */
    /* ------------------------------------------------------------------ */

    public function index(): Response
    {
        $params = $this->listParams();

        $rows = $this->ledger->stockLevels(
            ($params['filters']['category_id'] ?? '') !== '' ? (string) $params['filters']['category_id'] : null,
            $params['search']
        );

        $paginator = new Paginator(count($rows), $params['page'], $params['per_page']);
        $rows      = array_slice($rows, $paginator->offset(), $paginator->perPage);

        return $this->render('inputs/index', [
            'pageTitle' => 'Input inventory',
            'rows'      => $rows,
            'paginator' => $paginator,
            'summary'   => $this->stockSummary(),
            'filters'   => [
                ['key' => 'category_id', 'label' => 'Category', 'options' => $this->lookups->inputCategoryOptions()],
            ],
        ]);
    }

    public function create(): Response
    {
        return $this->render('inputs/form', [
            'pageTitle' => 'Register input item',
            'record'    => null,
            'sections'  => $this->itemSections(true),
            'locked'    => false,
        ]);
    }

    public function store(): Response
    {
        $this->assertCsrf();

        $post   = $this->request->all();
        $fields = array_merge(...array_column($this->itemSections(true), 'fields'));

        $validator = Form::validate($fields, $post);
        $validator->minValue('opening_stock', 0, 'Opening stock');

        if ($validator->fails()) {
            return $this->backWithErrors(url('inputs/create'), $validator->errors(), $post);
        }

        $values = Form::extract($fields, $post, $this->nullableItemColumns());

        $opening = round((float) ($values['opening_stock'] ?? 0), 3);

        unset($values['opening_stock']);

        $values['current_stock'] = 0.0;

        try {
            $result = $this->db->transaction(function () use ($values, $opening): array {
                $values['input_code'] = Identifier::generate('input_item');

                $id = $this->db->insert('inputs', $values);

                $movement = null;

                // The opening balance is a ledger movement like any other, so the
                // item can never be saved with stock that no transaction explains.
                if ($opening > 0) {
                    $movement = $this->ledger->move($id, [
                        'type'     => 'opening',
                        'quantity' => $opening,
                        'date'     => date('Y-m-d'),
                        'notes'    => 'Opening balance recorded when the item was registered.',
                    ], $this->auth->id());

                    if (($movement['success'] ?? false) !== true) {
                        throw new \RuntimeException((string) ($movement['message'] ?? 'The opening stock could not be recorded.'));
                    }
                }

                return [
                    'id'       => $id,
                    'code'     => (string) $values['input_code'],
                    'movement' => $movement,
                ];
            });
        } catch (\RuntimeException $exception) {
            Flash::error($exception->getMessage());

            return $this->redirect(url('inputs'));
        }

        $item = $this->findByCodeOrFail($result['code']);

        Audit::log('create', 'inputs', 'input', $result['id'], $result['code'], null, $values);

        if ($result['movement'] !== null) {
            Audit::log('stock_movement', 'inputs', 'input', $result['id'], $result['code'], null, [
                'type'         => 'opening',
                'quantity'     => $opening,
                'balance'      => $result['movement']['balance'] ?? null,
                'unit'         => $item['unit'],
            ]);
        }

        $message = 'Input item ' . $result['code'] . ' has been registered.';

        if ($opening > 0) {
            $message .= ' Opening stock of ' . format_quantity($opening, (string) $item['unit']) . ' is on the ledger.';
        }

        Flash::success($message);

        return $this->redirect(url('inputs/' . $result['code']));
    }

    /**
     * The item record: what is on the shelf, the full movement ledger, and
     * everything that has gone out to farmers and been applied in the field.
     */
    public function show(string $code): Response
    {
        $input = $this->findByCodeOrFail($code);

        $transactions = $this->ledgerFor($input);

        $totalIssued = (float) $this->db->scalar(
            "SELECT COALESCE(SUM(quantity), 0) FROM input_issues
             WHERE input_id = :id AND status <> 'cancelled'",
            ['id' => $input['id']]
        );

        $totalUsage = (float) $this->db->scalar(
            'SELECT COALESCE(SUM(quantity_used), 0) FROM input_usage WHERE input_id = :id',
            ['id' => $input['id']]
        );

        $openIssues = $this->openIssues($input, 25);

        return $this->render('inputs/show', [
            'pageTitle'      => $input['name'],
            'input'          => $input,
            'lowStock'       => $this->isLowStock($input),
            'transactions'   => $transactions,
            'totalIssued'    => $totalIssued,
            'totalUsage'     => $totalUsage,
            'openIssues'     => $openIssues,
            'openIssueCount' => count($openIssues),
        ]);
    }

    public function edit(string $code): Response
    {
        $input = $this->findByCodeOrFail($code);

        return $this->render('inputs/form', [
            'pageTitle' => 'Edit ' . $input['input_code'],
            'record'    => $input,
            'sections'  => $this->itemSections(false),
            'locked'    => $this->transactionCount((int) $input['id']) > 0,
        ]);
    }

    public function update(string $code): Response
    {
        $this->assertCsrf();

        $input  = $this->findByCodeOrFail($code);
        $post   = $this->request->all();
        $fields = array_merge(...array_column($this->itemSections(false), 'fields'));

        $validator = Form::validate($fields, $post);

        if ($validator->fails()) {
            return $this->backWithErrors(url('inputs/' . $code . '/edit'), $validator->errors(), $post);
        }

        $values = Form::extract($fields, $post, $this->nullableItemColumns());

        $locked = $this->lockedChanges($input, $values);

        if ($locked !== []) {
            Flash::error(
                'The ' . implode(' and ', $locked) . ' of ' . $input['input_code'] . ' cannot be changed once the item has '
                . 'stock movements: every historical ledger row is recorded in that value and would stop making sense. '
                . 'Register a new item for a different unit or category instead.'
            );

            return $this->redirect(url('inputs/' . $code . '/edit'));
        }

        $this->db->update('inputs', $values, 'id = :id', ['id' => $input['id']]);

        Audit::log('update', 'inputs', 'input', (int) $input['id'], $input['input_code'], $input, $values);

        Flash::success('Input item ' . $input['input_code'] . ' updated.');

        return $this->redirect(url('inputs/' . $code));
    }

    public function destroy(string $code): Response
    {
        $this->assertCsrf();

        $input = $this->findByCodeOrFail($code);

        $transactions = $this->transactionCount((int) $input['id']);
        $issues       = (int) $this->db->scalar('SELECT COUNT(*) FROM input_issues WHERE input_id = :id', ['id' => $input['id']]);

        if ($transactions > 0 || $issues > 0) {
            Flash::error(
                $input['input_code'] . ' already carries ' . $transactions . ' stock movement(s) and ' . $issues
                . ' issue record(s). An item with history is deactivated rather than removed so the ledger stays auditable.'
            );

            return $this->redirect(url('inputs/' . $code));
        }

        if ((string) $input['status'] === 'inactive') {
            Flash::info($input['input_code'] . ' is already inactive.');

            return $this->redirect(url('inputs'));
        }

        $this->db->update('inputs', ['status' => 'inactive'], 'id = :id', ['id' => $input['id']]);

        Audit::log('deactivate', 'inputs', 'input', (int) $input['id'], $input['input_code'], $input, ['status' => 'inactive']);

        Flash::success($input['input_code'] . ' has been deactivated. It is no longer offered on issue or usage screens.');

        return $this->redirect(url('inputs'));
    }

    /* ------------------------------------------------------------------ */
    /* Stock movements                                                      */
    /* ------------------------------------------------------------------ */

    public function stock(string $code): Response
    {
        $input = $this->findByCodeOrFail($code);

        return $this->render('inputs/stock', [
            'pageTitle'    => 'Stock — ' . $input['name'],
            'input'        => $input,
            'lowStock'     => $this->isLowStock($input),
            'transactions' => $this->ledgerFor($input),
            'movementFields' => $this->movementFields(),
        ]);
    }

    public function adjustStock(string $code): Response
    {
        $this->assertCsrf();

        $input  = $this->findByCodeOrFail($code);
        $post   = $this->request->all();
        $fields = $this->movementFields();

        $validator = Form::validate($fields, $post);
        $validator->minValue('quantity', 0.001, 'Quantity');

        if ($validator->fails()) {
            return $this->backWithErrors(url('inputs/' . $code . '/stock'), $validator->errors(), $post);
        }

        $values = Form::extract($fields, $post, ['transaction_date', 'supplier', 'reference_no', 'notes']);

        $movement = [
            'type'         => (string) $values['type'],
            'quantity'     => round((float) $values['quantity'], 3),
            'date'         => (string) ($values['transaction_date'] ?? date('Y-m-d')),
            'supplier'     => $values['supplier'] ?? null,
            'reference_no' => $values['reference_no'] ?? null,
            'notes'        => $values['notes'] ?? null,
        ];

        $result = $this->ledger->move((int) $input['id'], $movement, $this->auth->id());

        if (($result['success'] ?? false) !== true) {
            Flash::error((string) ($result['message'] ?? 'The stock movement could not be recorded.'));

            return $this->redirect(url('inputs/' . $code . '/stock'));
        }

        Audit::log('stock_movement', 'inputs', 'input', (int) $input['id'], $input['input_code'], null, [
            'transaction_code' => (string) $result['code'],
            'type'             => $movement['type'],
            'quantity'         => $movement['quantity'],
            'balance'          => $result['balance'] ?? null,
            'unit'             => $input['unit'],
        ]);

        Flash::success(sprintf(
            'Movement %s recorded. %s now stands at %s.',
            (string) $result['code'],
            $input['name'],
            format_quantity($result['balance'] ?? 0, (string) $input['unit'])
        ));

        return $this->redirect(url('inputs/' . $code . '/stock'));
    }

    /* ------------------------------------------------------------------ */
    /* Issues to farmers                                                   */
    /* ------------------------------------------------------------------ */

    public function issueIndex(): Response
    {
        $params = $this->listParams();

        $values = [];
        $scope  = $this->scopeWhere($values);
        $where  = ['1 = 1' . $scope];

        if ($params['search'] !== '') {
            $where[]      = '(ii.issue_code LIKE :s OR i.name LIKE :s OR f.full_name LIKE :s OR f.farmer_code LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        foreach (['status' => 'ii.status', 'season_id' => 'ii.season_id', 'crop_id' => 'ii.crop_id'] as $key => $column) {
            if (($params['filters'][$key] ?? '') !== '') {
                $where[]      = $column . ' = :' . $key;
                $values[$key] = $params['filters'][$key];
            }
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total     = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM input_issues ii
             INNER JOIN inputs i ON i.id = ii.input_id
             LEFT JOIN farmers f ON f.id = ii.farmer_id' . $whereSql,
            $values
        );
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $sortable = ['issue_code', 'issue_date', 'quantity', 'quantity_used', 'status'];
        $column   = in_array($params['sort'], $sortable, true) ? $params['sort'] : 'issue_date';
        $order    = $params['direction'] === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->db->select(
            'SELECT ii.*, i.name AS input_name, i.input_code, i.unit AS input_unit,
                    f.full_name AS farmer_name, f.farmer_code, g.garden_code, g.name AS garden_name,
                    s.season_code, c.name AS crop_name,
                    u.first_name AS issued_by_name, o.full_name AS officer_name
             FROM input_issues ii
             INNER JOIN inputs i ON i.id = ii.input_id
             LEFT JOIN farmers f ON f.id = ii.farmer_id
             LEFT JOIN gardens g ON g.id = ii.garden_id
             LEFT JOIN seasons s ON s.id = ii.season_id
             LEFT JOIN crops c ON c.id = ii.crop_id
             LEFT JOIN users u ON u.id = ii.issued_by
             LEFT JOIN field_officers o ON o.id = ii.officer_id'
            . $whereSql
            . ' ORDER BY ii.' . $column . ' ' . $order
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('inputs/issues', [
            'pageTitle' => 'Issued inputs',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $params['sort'],
            'direction' => $params['direction'],
            'summary'   => [
                'issued'    => (int) $this->db->scalar("SELECT COUNT(*) FROM input_issues WHERE status = 'issued'"),
                'in_use'    => (int) $this->db->scalar("SELECT COUNT(*) FROM input_issues WHERE status = 'partially_used'"),
                'used_up'   => (int) $this->db->scalar("SELECT COUNT(*) FROM input_issues WHERE status = 'fully_used'"),
            ],
            'filters'   => [
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions(
                    "'issued','partially_used','fully_used','returned','cancelled'",
                    'All statuses'
                )],
                ['key' => 'season_id', 'label' => 'Season', 'options' => $this->lookups->seasonOptions('All seasons')],
            ],
        ]);
    }

    public function issueCreate(): Response
    {
        return $this->render('inputs/issue_form', [
            'pageTitle' => 'Issue input to a farmer',
            'record'    => null,
            'sections'  => $this->issueSections(),
        ]);
    }

    public function issueStore(): Response
    {
        $this->assertCsrf();

        $post   = $this->request->all();
        $fields = array_merge(...array_column($this->issueSections(), 'fields'));

        $validator = Form::validate($fields, $post);
        $validator->minValue('quantity', 0.001, 'Quantity');

        if ($validator->fails()) {
            return $this->backWithErrors(url('input-issues/create'), $validator->errors(), $post);
        }

        $values = Form::extract($fields, $post, [
            'garden_id', 'crop_id', 'season_id', 'officer_id', 'reference_no', 'purpose',
        ]);

        if (!$this->gardenBelongsToFarmer($values)) {
            return $this->backWithErrors(
                url('input-issues/create'),
                ['garden_id' => 'That garden does not belong to the selected farmer. Pick the farmer who owns the plot.'],
                $post
            );
        }

        $result = $this->ledger->issue([
            'input_id'     => (int) $values['input_id'],
            'quantity'     => round((float) $values['quantity'], 3),
            'farmer_id'    => (int) $values['farmer_id'],
            'garden_id'    => $values['garden_id'] ?? null,
            'crop_id'      => $values['crop_id'] ?? null,
            'season_id'    => $values['season_id'] ?? null,
            'issue_date'   => (string) ($values['issue_date'] ?? date('Y-m-d')),
            'reference_no' => $values['reference_no'] ?? null,
            'purpose'      => $values['purpose'] ?? null,
        ], $this->auth->id(), isset($values['officer_id']) ? (int) $values['officer_id'] : null);

        if (($result['success'] ?? false) !== true) {
            return $this->backWithErrors(
                url('input-issues/create'),
                ['quantity' => (string) ($result['message'] ?? 'The issue could not be recorded.')],
                $post
            );
        }

        $issue = $this->findIssueByCodeOrFail((string) $result['code']);

        Audit::log('issue', 'inputs', 'input_issue', (int) $result['id'], (string) $result['code'], null, $values);

        $this->notifier->notify([
            'type'        => 'input_issued',
            'title'       => 'Input issued',
            'message'     => sprintf(
                '%s of %s was issued to you under %s. Record what you apply so the cooperative can support you properly.',
                format_quantity((float) $issue['quantity'], (string) $issue['unit']),
                (string) $issue['input_name'],
                (string) $issue['issue_code']
            ),
            'farmer_id'   => (int) $issue['farmer_id'],
            'link'        => url('input-issues/' . $issue['issue_code']),
            'entity_type' => 'input_issue',
            'entity_id'   => (int) $issue['id'],
        ]);

        Flash::success(sprintf(
            'Issued %s of %s to %s under %s.',
            format_number($issue['quantity']),
            (string) $issue['input_name'],
            (string) ($issue['farmer_name'] ?? 'the farmer'),
            (string) $issue['issue_code']
        ));

        return $this->redirect(url('input-issues/' . $issue['issue_code']));
    }

    /**
     * One issue with the usage recorded against it, the amount still sitting
     * with the farmer, and the form to record a further application.
     */
    public function issueShow(string $code): Response
    {
        $issue = $this->findIssueByCodeOrFail($code);

        $usages = $this->db->select(
            'SELECT u.*, ' . Lookups::activityNameSql('a') . ' AS activity_name, g.name AS garden_name
             FROM input_usage u
             LEFT JOIN crop_activities a ON a.id = u.activity_id
             LEFT JOIN gardens g ON g.id = u.garden_id
             WHERE u.issue_id = :id ORDER BY u.application_date DESC, u.id DESC',
            ['id' => $issue['id']]
        );

        $remaining = max(0.0, round((float) $issue['quantity'] - (float) $issue['quantity_used'], 3));

        $record = [
            'issue_id'         => $issue['id'],
            'input_id'         => $issue['input_id'],
            'farmer_id'        => $issue['farmer_id'],
            'garden_id'        => $issue['garden_id'],
            'crop_id'          => $issue['crop_id'],
            'season_id'        => $issue['season_id'],
            'unit'             => $issue['unit'],
        ];

        return $this->render('inputs/issue_show', [
            'pageTitle' => 'Issue ' . $issue['issue_code'],
            'issue'     => $issue,
            'usages'    => $usages,
            'remaining' => $remaining,
            'record'    => $record,
            'sections'  => $this->usageSections(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Usage                                                                */
    /* ------------------------------------------------------------------ */

    public function usageIndex(): Response
    {
        $params = $this->listParams();

        $values = [];
        $scope  = $this->scopeWhere($values);
        $where  = ['1 = 1' . $scope];

        if ($params['search'] !== '') {
            $where[]      = '(u.usage_code LIKE :s OR i.name LIKE :s OR f.full_name LIKE :s OR f.farmer_code LIKE :s)';
            $values['s'] = '%' . $params['search'] . '%';
        }

        foreach (['crop_id' => 'u.crop_id', 'season_id' => 'u.season_id'] as $key => $column) {
            if (($params['filters'][$key] ?? '') !== '') {
                $where[]      = $column . ' = :' . $key;
                $values[$key] = $params['filters'][$key];
            }
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total     = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM input_usage u
             INNER JOIN inputs i ON i.id = u.input_id
             LEFT JOIN farmers f ON f.id = u.farmer_id' . $whereSql,
            $values
        );
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $sortable = ['usage_code', 'application_date', 'quantity_used', 'input_name', 'farmer_name'];
        $column   = in_array($params['sort'], $sortable, true) ? $params['sort'] : 'application_date';
        $order    = $params['direction'] === 'asc' ? 'ASC' : 'DESC';

        $join = match ($column) {
            'input_name'  => 'i.name',
            'farmer_name' => 'f.full_name',
            default       => 'u.' . $column,
        };

        $rows = $this->db->select(
            'SELECT u.*, i.name AS input_name, i.input_code,
                    f.full_name AS farmer_name, f.farmer_code,
                    g.garden_code, g.name AS garden_name,
                    c.name AS crop_name, s.season_code,
                    ii.issue_code, ru.username AS recorded_by_name
             FROM input_usage u
             INNER JOIN inputs i ON i.id = u.input_id
             LEFT JOIN farmers f ON f.id = u.farmer_id
             LEFT JOIN gardens g ON g.id = u.garden_id
             LEFT JOIN crops c ON c.id = u.crop_id
             LEFT JOIN seasons s ON s.id = u.season_id
             LEFT JOIN input_issues ii ON ii.id = u.issue_id
             LEFT JOIN users ru ON ru.id = u.recorded_by'
            . $whereSql
            . ' ORDER BY ' . $join . ' ' . $order
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('inputs/usage', [
            'pageTitle' => 'Input usage',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $params['sort'],
            'direction' => $params['direction'],
            'filters'   => [
                ['key' => 'crop_id', 'label' => 'Crop', 'options' => $this->lookups->cropOptions('All crops')],
                ['key' => 'season_id', 'label' => 'Season', 'options' => $this->lookups->seasonOptions('All seasons')],
            ],
        ]);
    }

    public function usageCreate(): Response
    {
        return $this->render('inputs/usage_form', [
            'pageTitle' => 'Record input usage',
            'record'    => null,
            'sections'  => $this->usageSections(),
            'remaining' => null,
        ]);
    }

    public function usageStore(): Response
    {
        $this->assertCsrf();

        $post   = $this->request->all();
        $fields = array_merge(...array_column($this->usageSections(), 'fields'));

        $validator = Form::validate($fields, $post);
        $validator->minValue('quantity_used', 0.001, 'Quantity used');

        if ($validator->fails()) {
            return $this->backWithErrors(url('input-usage/create'), $validator->errors(), $post);
        }

        $values = Form::extract($fields, $post, [
            'issue_id', 'garden_id', 'crop_id', 'season_id', 'activity_id', 'purpose',
        ]);

        $issueCode = null;

        if (($values['issue_id'] ?? null) !== null) {
            $issue = $this->findIssueOrFail((int) $values['issue_id']);

            // An issue fixes what was given, to whom and where: only the amount
            // applied and when it was applied can vary from the issue itself.
            $values['input_id']  = (string) $issue['input_id'];
            $values['farmer_id'] = (string) $issue['farmer_id'];
            $issueCode           = (string) $issue['issue_code'];

            if (($values['garden_id'] ?? null) === null && $issue['garden_id'] !== null) {
                $values['garden_id'] = (string) $issue['garden_id'];
            }

            $remaining = round((float) $issue['quantity'] - (float) $issue['quantity_used'], 3);

            if ((float) $values['quantity_used'] > $remaining + 0.0001) {
                return $this->backWithErrors(
                    url('input-usage/create'),
                    ['quantity_used' => sprintf(
                        'Only %s remains on issue %s. Record a return instead if the farmer gave some back.',
                        format_quantity($remaining, (string) $issue['unit']),
                        (string) $issue['issue_code']
                    )],
                    $post
                );
            }
        } elseif (!$this->gardenBelongsToFarmer($values)) {
            return $this->backWithErrors(
                url('input-usage/create'),
                ['garden_id' => 'That garden does not belong to the selected farmer.'],
                $post
            );
        }

        $result = $this->ledger->recordUsage([
            'input_id'         => (int) $values['input_id'],
            'quantity_used'    => round((float) $values['quantity_used'], 3),
            'issue_id'         => $values['issue_id'] ?? null,
            'farmer_id'        => (int) $values['farmer_id'],
            'garden_id'        => $values['garden_id'] ?? null,
            'crop_id'          => $values['crop_id'] ?? null,
            'season_id'        => $values['season_id'] ?? null,
            'activity_id'      => $values['activity_id'] ?? null,
            'application_date' => (string) ($values['application_date'] ?? date('Y-m-d')),
            'purpose'          => $values['purpose'] ?? null,
        ], $this->auth->id());

        if (($result['success'] ?? false) !== true) {
            return $this->backWithErrors(
                url('input-usage/create'),
                ['quantity_used' => (string) ($result['message'] ?? 'The usage could not be recorded.')],
                $post
            );
        }

        Audit::log('record', 'inputs', 'input_usage', (int) $result['id'], (string) $result['code'], null, $values);

        Flash::success('Usage ' . $result['code'] . ' recorded.');

        return $this->redirect($issueCode === null ? url('input-usage') : url('input-issues/' . $issueCode));
    }

    public function usageEdit(string $code): Response
    {
        $usage = $this->findUsageByCodeOrFail($code);

        return $this->render('inputs/usage_form', [
            'pageTitle' => 'Edit usage ' . $usage['usage_code'],
            'record'    => $usage,
            'sections'  => $this->usageSections(),
            'remaining' => $this->remainingOnIssue($usage),
        ]);
    }

    public function usageUpdate(string $code): Response
    {
        $this->assertCsrf();

        $usage  = $this->findUsageByCodeOrFail($code);
        $post   = $this->request->all();
        $fields = array_merge(...array_column($this->usageSections(), 'fields'));

        $validator = Form::validate($fields, $post);
        $validator->minValue('quantity_used', 0.001, 'Quantity used');

        if ($validator->fails()) {
            return $this->backWithErrors(url('input-usage/' . $code . '/edit'), $validator->errors(), $post);
        }

        $values = Form::extract($fields, $post, [
            'issue_id', 'garden_id', 'crop_id', 'season_id', 'activity_id', 'purpose',
        ]);

        $values['unit'] = $this->unitFor((int) ($values['input_id'] ?? $usage['input_id']));

        $previousIssueId = $usage['issue_id'] === null ? null : (int) $usage['issue_id'];
        $nextIssueId     = ($values['issue_id'] ?? null) === null ? null : (int) $values['issue_id'];

        if ($nextIssueId !== null) {
            $issue = $this->findIssueOrFail($nextIssueId);

            $values['input_id']  = (string) $issue['input_id'];
            $values['farmer_id'] = (string) $issue['farmer_id'];
            $values['unit']      = (string) $issue['unit'];

            if (($values['garden_id'] ?? null) === null && $issue['garden_id'] !== null) {
                $values['garden_id'] = (string) $issue['garden_id'];
            }

            $available = (float) $this->db->scalar(
                'SELECT COALESCE(SUM(quantity_used), 0) FROM input_usage WHERE issue_id = :issue AND id <> :id',
                ['issue' => $nextIssueId, 'id' => $usage['id']]
            );

            $remaining = round((float) $issue['quantity'] - $available, 3);

            if ((float) $values['quantity_used'] > $remaining + 0.0001) {
                return $this->backWithErrors(
                    url('input-usage/' . $code . '/edit'),
                    ['quantity_used' => sprintf(
                        'Only %s remains on issue %s once this record is taken into account.',
                        format_quantity($remaining, (string) $issue['unit']),
                        (string) $issue['issue_code']
                    )],
                    $post
                );
            }
        } elseif (!$this->gardenBelongsToFarmer($values)) {
            return $this->backWithErrors(
                url('input-usage/' . $code . '/edit'),
                ['garden_id' => 'That garden does not belong to the selected farmer.'],
                $post
            );
        }

        // An issue's quantity_used and status are a roll-up of its usage rows, so
        // both the row it left and the row it joined are recomputed here rather
        // than being patched, and the two can never disagree.
        $this->db->transaction(function () use ($usage, $values, $previousIssueId, $nextIssueId): void {
            $this->db->update('input_usage', $values, 'id = :id', ['id' => $usage['id']]);

            $this->recomputeIssue($previousIssueId);

            if ($nextIssueId !== $previousIssueId) {
                $this->recomputeIssue($nextIssueId);
            }
        });

        Audit::log('update', 'inputs', 'input_usage', (int) $usage['id'], $usage['usage_code'], $usage, $values);

        Flash::success('Usage record ' . $usage['usage_code'] . ' updated.');

        return $this->redirect($usage['issue_code'] === null
            ? url('input-usage')
            : url('input-issues/' . $usage['issue_code']));
    }

    public function usageDelete(string $code): Response
    {
        $this->assertCsrf();

        $usage      = $this->findUsageByCodeOrFail($code);
        $issueId    = $usage['issue_id'] === null ? null : (int) $usage['issue_id'];
        $issueCode  = $usage['issue_code'] === null ? null : (string) $usage['issue_code'];

        $this->db->transaction(function () use ($usage, $issueId): void {
            $this->db->delete('input_usage', 'id = :id', ['id' => $usage['id']]);

            $this->recomputeIssue($issueId);
        });

        Audit::log('delete', 'inputs', 'input_usage', (int) $usage['id'], $usage['usage_code'], $usage);

        Flash::success(
            'Usage record ' . $usage['usage_code'] . ' removed. The parent issue has been brought back into line with '
            . 'its remaining usage rows.'
        );

        return $this->redirect($issueCode === null ? url('input-usage') : url('input-issues/' . $issueCode));
    }

    /* ------------------------------------------------------------------ */
    /* Field definitions                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function itemSections(bool $withOpeningStock): array
    {
        $sections = [
            [
                'title'  => 'Item',
                'fields' => [
                    ['name' => 'input_code', 'label' => 'Input ID', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued automatically when the item is saved.'],
                    ['name' => 'category_id', 'label' => 'Category', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => $this->lookups->inputCategoryOptions('Select category...'), 'table' => 'input_categories'],
                    ['name' => 'name', 'label' => 'Item name', 'type' => Form::TEXT, 'required' => true, 'colspan' => 6,
                     'placeholder' => 'e.g. NPK 15-15-15, DAP, Fungicide (Copper)'],
                    ['name' => 'unit', 'label' => 'Unit of measure', 'type' => Form::TEXT, 'required' => true, 'colspan' => 6,
                     'placeholder' => 'KG, L, bags, packets...',
                     'help' => 'Locked once the item has stock movements.'],
                    ['name' => 'brand', 'label' => 'Brand / manufacturer', 'type' => Form::TEXT, 'colspan' => 6],
                    ['name' => 'active_ingredient', 'label' => 'Active ingredient', 'type' => Form::TEXT, 'colspan' => 6,
                     'help' => 'For agrochemicals this drives the pre-harvest interval advice.'],
                    ['name' => 'crop_id', 'label' => 'Crop specific input', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->cropOptions('Applies to any crop'), 'table' => 'crops'],
                ],
            ],
            [
                'title'  => 'Stock control',
                'fields' => [
                    ['name' => 'reorder_level', 'label' => 'Reorder level', 'type' => Form::NUMBER, 'colspan' => 6,
                     'min' => 0, 'step' => 0.001,
                     'help' => 'The store is flagged low when stock falls to this figure.'],
                    ['name' => 'unit_cost', 'label' => 'Unit cost', 'type' => Form::NUMBER, 'colspan' => 6,
                     'min' => 0, 'step' => 0.01],
                    ['name' => 'status', 'label' => 'Status', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions("'active','inactive'", '')],
                ],
            ],
            [
                'title'  => 'Notes',
                'fields' => [
                    ['name' => 'description', 'label' => 'Description', 'type' => Form::TEXTAREA, 'colspan' => 12],
                ],
            ],
        ];

        if ($withOpeningStock) {
            $sections[1]['fields'][] = [
                'name' => 'opening_stock', 'label' => 'Opening stock', 'type' => Form::NUMBER, 'colspan' => 6,
                'min' => 0, 'step' => 0.001,
                'help' => 'What is physically on the shelf today. Recorded as an opening movement on the ledger.',
            ];
        }

        return $sections;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function movementFields(): array
    {
        return [
            ['name' => 'type', 'label' => 'Movement', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
             'options' => Form::enumOptions(self::MOVEMENT_TYPES, '')],
            ['name' => 'quantity', 'label' => 'Quantity', 'type' => Form::NUMBER, 'required' => true, 'colspan' => 6,
             'min' => 0.001, 'step' => 0.001],
            ['name' => 'transaction_date', 'label' => 'Movement date', 'type' => Form::DATE, 'required' => true, 'colspan' => 6,
             'default' => date('Y-m-d')],
            ['name' => 'supplier', 'label' => 'Supplier', 'type' => Form::TEXT, 'colspan' => 6,
             'help' => 'Relevant to receipts and returns.'],
            ['name' => 'reference_no', 'label' => 'Reference number', 'type' => Form::TEXT, 'colspan' => 6,
             'help' => 'Invoice, delivery note or receipt number.'],
            ['name' => 'notes', 'label' => 'Notes', 'type' => Form::TEXTAREA, 'colspan' => 12],
        ];
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function issueSections(): array
    {
        return [
            [
                'title'  => 'What is being issued',
                'fields' => [
                    ['name' => 'input_id', 'label' => 'Input item', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => $this->inputOptions(), 'table' => 'inputs',
                     'help' => 'Only active items with stock on the shelf are listed.'],
                    ['name' => 'quantity', 'label' => 'Quantity issued', 'type' => Form::NUMBER, 'required' => true, 'colspan' => 6,
                     'min' => 0.001, 'step' => 0.001,
                     'help' => 'Deducted from the store balance and locked to this farmer until it is used or returned.'],
                    ['name' => 'farmer_id', 'label' => 'Farmer', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => $this->lookups->farmerOptions(), 'table' => 'farmers'],
                    ['name' => 'garden_id', 'label' => 'Garden', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->gardenOptions(), 'table' => 'gardens',
                     'help' => 'The garden is checked against the farmer when the issue is saved.'],
                    ['name' => 'crop_id', 'label' => 'Crop', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->cropOptions('Not crop specific'), 'table' => 'crops'],
                    ['name' => 'season_id', 'label' => 'Season', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->seasonOptions('Not season specific')],
                    ['name' => 'issue_date', 'label' => 'Issue date', 'type' => Form::DATE, 'required' => true, 'colspan' => 6,
                     'default' => date('Y-m-d')],
                ],
            ],
            [
                'title'  => 'Handover',
                'fields' => [
                    ['name' => 'officer_id', 'label' => 'Issuing officer', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->officerOptions(), 'table' => 'field_officers'],
                    ['name' => 'reference_no', 'label' => 'Reference number', 'type' => Form::TEXT, 'colspan' => 6,
                     'help' => 'Voucher or receipt number.'],
                    ['name' => 'purpose', 'label' => 'Purpose', 'type' => Form::TEXT, 'colspan' => 12,
                     'placeholder' => 'e.g. Season A top dressing, pest control for coffee'],
                ],
            ],
        ];
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function usageSections(): array
    {
        return [
            [
                'title'  => 'Where the input went',
                'fields' => [
                    ['name' => 'issue_id', 'label' => 'Against issue', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->openIssueOptions(), 'table' => 'input_issues',
                     'help' => 'Optional. Choosing an issue fixes the item, farmer and garden, and caps the amount at what is left.'],
                    ['name' => 'input_id', 'label' => 'Input item', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => $this->inputOptions(), 'table' => 'inputs'],
                    ['name' => 'farmer_id', 'label' => 'Farmer', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => $this->lookups->farmerOptions(), 'table' => 'farmers'],
                    ['name' => 'garden_id', 'label' => 'Garden', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->gardenOptions(), 'table' => 'gardens',
                     'help' => 'The garden is checked against the farmer when the record is saved.'],
                    ['name' => 'crop_id', 'label' => 'Crop', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->cropOptions('Not crop specific'), 'table' => 'crops'],
                    ['name' => 'season_id', 'label' => 'Season', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->lookups->seasonOptions('Not season specific')],
                    ['name' => 'activity_id', 'label' => 'Linked activity', 'type' => Form::SELECT, 'colspan' => 6,
                     'options' => $this->activityOptions(), 'table' => 'crop_activities'],
                ],
            ],
            [
                'title'  => 'What was applied',
                'fields' => [
                    ['name' => 'quantity_used', 'label' => 'Quantity used', 'type' => Form::NUMBER, 'required' => true, 'colspan' => 6,
                     'min' => 0.001, 'step' => 0.001],
                    ['name' => 'unit', 'label' => 'Unit', 'type' => Form::TEXT, 'readonly' => true, 'colspan' => 6,
                     'help' => 'Taken from the input item.'],
                    ['name' => 'application_date', 'label' => 'Application date', 'type' => Form::DATE, 'required' => true, 'colspan' => 6,
                     'default' => date('Y-m-d')],
                    ['name' => 'purpose', 'label' => 'Purpose', 'type' => Form::TEXT, 'colspan' => 12,
                     'placeholder' => 'e.g. First top dressing, weed control'],
                ],
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function nullableItemColumns(): array
    {
        return ['brand', 'active_ingredient', 'description', 'unit_cost', 'crop_id', 'reorder_level'];
    }

    /* ------------------------------------------------------------------ */
    /* Lookups and guards                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function inputOptions(): array
    {
        $rows = $this->db->select(
            "SELECT id, name, unit, current_stock FROM inputs
             WHERE status = 'active' ORDER BY name"
        );

        $options = [['value' => '', 'label' => 'Select input item...']];

        foreach ($rows as $row) {
            $options[] = [
                'value' => (string) $row['id'],
                'label' => sprintf(
                    '%s (%s) — %s in stock',
                    (string) $row['name'],
                    (string) $row['unit'],
                    format_number($row['current_stock'])
                ),
            ];
        }

        return $options;
    }

    /**
     * Issues that still have input in the farmer's hands, for the usage form.
     *
     * @return array<int,array{value:string,label:string}>
     */
    private function openIssueOptions(): array
    {
        $rows = $this->db->select(
            "SELECT ii.id, ii.issue_code, ii.quantity, ii.quantity_used, ii.unit, i.name AS input_name,
                    f.full_name AS farmer_name
             FROM input_issues ii
             INNER JOIN inputs i ON i.id = ii.input_id
             LEFT JOIN farmers f ON f.id = ii.farmer_id
             WHERE ii.status IN ('issued', 'partially_used')
             ORDER BY ii.issue_date DESC LIMIT 300"
        );

        $options = [['value' => '', 'label' => 'Not against an issue']];

        foreach ($rows as $row) {
            $options[] = [
                'value' => (string) $row['id'],
                'label' => sprintf(
                    '%s — %s to %s, %s left',
                    (string) $row['issue_code'],
                    (string) $row['input_name'],
                    (string) ($row['farmer_name'] ?? 'unassigned'),
                    format_number((float) $row['quantity'] - (float) $row['quantity_used'])
                ),
            ];
        }

        return $options;
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function activityOptions(): array
    {
        $rows = $this->db->select(
            'SELECT a.id, a.activity_code, a.activity_type
             FROM crop_activities a ORDER BY a.due_date DESC LIMIT 300'
        );

        $options = [['value' => '', 'label' => 'Not linked to an activity']];

        foreach ($rows as $row) {
            $options[] = [
                'value' => (string) $row['id'],
                'label' => (string) $row['activity_code'] . ' — ' . Lookups::label((string) $row['activity_type']),
            ];
        }

        return $options;
    }

    /**
     * A garden is only meaningful with its owner, so a mismatch is refused
     * rather than quietly filed against the wrong farmer.
     *
     * @param array<string,mixed> $values
     */
    private function gardenBelongsToFarmer(array $values): bool
    {
        if (($values['garden_id'] ?? null) === null || ($values['farmer_id'] ?? null) === null) {
            return true;
        }

        $garden = $this->db->selectOne(
            'SELECT farmer_id FROM gardens WHERE id = :id AND deleted_at IS NULL',
            ['id' => (int) $values['garden_id']]
        );

        return $garden !== null && (int) $garden['farmer_id'] === (int) $values['farmer_id'];
    }

    /**
     * Unit and category are written into every historical ledger row, so once
     * the item has moved stock they are fixed.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $values
     * @return array<int,string>
     */
    private function lockedChanges(array $input, array $values): array
    {
        if ($this->transactionCount((int) $input['id']) === 0) {
            return [];
        }

        $locked = [];

        if (strcasecmp(trim((string) ($values['unit'] ?? '')), trim((string) $input['unit'])) !== 0) {
            $locked[] = 'unit of measure';
        }

        if ((string) ($values['category_id'] ?? '') !== (string) $input['category_id']) {
            $locked[] = 'category';
        }

        return $locked;
    }

    private function transactionCount(int $inputId): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM input_transactions WHERE input_id = :id', ['id' => $inputId]);
    }

    private function isLowStock(array $input): bool
    {
        return (float) $input['reorder_level'] > 0 && (float) $input['current_stock'] <= (float) $input['reorder_level'];
    }

    private function unitFor(int $inputId): string
    {
        $unit = $this->db->scalar('SELECT unit FROM inputs WHERE id = :id', ['id' => $inputId]);

        return $unit === null ? 'KG' : (string) $unit;
    }

    /**
     * The movement ledger for one item, oldest first, so the stored
     * balance_after on each row reads as a running balance.
     *
     * @param array<string,mixed> $input
     * @return array<int,array<string,mixed>>
     */
    private function ledgerFor(array $input): array
    {
        return $this->db->select(
            'SELECT t.*, u.username AS recorded_by_name
             FROM input_transactions t
             LEFT JOIN users u ON u.id = t.created_by
             WHERE t.input_id = :id
             ORDER BY t.transaction_date ASC, t.id ASC',
            ['id' => $input['id']]
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array<int,array<string,mixed>>
     */
    private function openIssues(array $input, int $limit): array
    {
        return $this->db->select(
            "SELECT ii.*, f.full_name AS farmer_name, f.farmer_code, g.garden_code, g.name AS garden_name
             FROM input_issues ii
             LEFT JOIN farmers f ON f.id = ii.farmer_id
             LEFT JOIN gardens g ON g.id = ii.garden_id
             WHERE ii.input_id = :id AND ii.status IN ('issued', 'partially_used')
             ORDER BY ii.issue_date DESC LIMIT " . $limit,
            ['id' => $input['id']]
        );
    }

    /**
     * What is still available on the issue a usage row belongs to, with that
     * row's own contribution discounted so the figure is honest while editing.
     *
     * @param array<string,mixed> $usage
     */
    private function remainingOnIssue(array $usage): ?float
    {
        if ($usage['issue_id'] === null) {
            return null;
        }

        $issued = (float) $this->db->scalar('SELECT quantity FROM input_issues WHERE id = :id', ['id' => $usage['issue_id']]);
        $used   = (float) $this->db->scalar(
            'SELECT COALESCE(SUM(quantity_used), 0) FROM input_usage WHERE issue_id = :issue AND id <> :id',
            ['issue' => $usage['issue_id'], 'id' => $usage['id']]
        );

        return max(0.0, round($issued - $used, 3));
    }

    /**
     * Rebuilds an issue's quantity_used and status from its usage rows. The two
     * are a roll-up, never an independently edited value.
     */
    private function recomputeIssue(?int $issueId): void
    {
        if ($issueId === null || $issueId <= 0) {
            return;
        }

        $issue = $this->db->selectOne('SELECT quantity, status FROM input_issues WHERE id = :id', ['id' => $issueId]);

        if ($issue === null || in_array((string) $issue['status'], ['returned', 'cancelled'], true)) {
            return;
        }

        $used   = (float) $this->db->scalar(
            'SELECT COALESCE(SUM(quantity_used), 0) FROM input_usage WHERE issue_id = :id',
            ['id' => $issueId]
        );
        $issued = (float) $issue['quantity'];

        $status = match (true) {
            $used <= 0.0001                => 'issued',
            $used >= $issued - 0.0001      => 'fully_used',
            default                        => 'partially_used',
        };

        $this->db->update('input_issues', [
            'quantity_used' => round($used, 3),
            'status'        => $status,
        ], 'id = :id', ['id' => $issueId]);
    }

    /**
     * @return array{items:int,low:int,value:float}
     */
    private function stockSummary(): array
    {
        return [
            'items' => (int) $this->db->scalar("SELECT COUNT(*) FROM inputs WHERE status = 'active'"),
            'low'   => (int) $this->db->scalar(
                "SELECT COUNT(*) FROM inputs WHERE status = 'active' AND reorder_level > 0 AND current_stock <= reorder_level"
            ),
            'value' => (float) $this->db->scalar(
                "SELECT COALESCE(SUM(current_stock * COALESCE(unit_cost, 0)), 0) FROM inputs WHERE status = 'active'"
            ),
        ];
    }

    private function findByCodeOrFail(string $code): array
    {
        $input = $this->db->selectOne(
            'SELECT i.*, c.name AS category_name, c.code AS category_code, cr.name AS crop_name
             FROM inputs i
             INNER JOIN input_categories c ON c.id = i.category_id
             LEFT JOIN crops cr ON cr.id = i.crop_id
             WHERE i.input_code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($input === null) {
            throw new HttpException(404, 'No input item found with the ID ' . $code . '.');
        }

        return $input;
    }

    private function findIssueByCodeOrFail(string $code): array
    {
        $issue = $this->db->selectOne(
            'SELECT ii.*, i.name AS input_name, i.input_code, f.full_name AS farmer_name, f.farmer_code, f.phone AS farmer_phone,
                    g.garden_code, g.name AS garden_name, c.name AS crop_name, s.season_code, s.name AS season_name,
                    o.full_name AS officer_name, u.username AS issued_by_name
             FROM input_issues ii
             INNER JOIN inputs i ON i.id = ii.input_id
             LEFT JOIN farmers f ON f.id = ii.farmer_id
             LEFT JOIN gardens g ON g.id = ii.garden_id
             LEFT JOIN crops c ON c.id = ii.crop_id
             LEFT JOIN seasons s ON s.id = ii.season_id
             LEFT JOIN field_officers o ON o.id = ii.officer_id
             LEFT JOIN users u ON u.id = ii.issued_by
             WHERE ii.issue_code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($issue === null) {
            throw new HttpException(404, 'No input issue found with the ID ' . $code . '.');
        }

        return $issue;
    }

    private function findIssueOrFail(int $id): array
    {
        $issue = $this->db->selectOne('SELECT * FROM input_issues WHERE id = :id', ['id' => $id]);

        if ($issue === null) {
            throw new HttpException(404, 'The selected input issue could not be found.');
        }

        return $issue;
    }

    private function findUsageByCodeOrFail(string $code): array
    {
        $usage = $this->db->selectOne(
            'SELECT u.*, i.name AS input_name, i.input_code, f.full_name AS farmer_name, f.farmer_code,
                    g.garden_code, g.name AS garden_name, c.name AS crop_name, s.season_code,
                    ii.issue_code, ru.username AS recorded_by_name
             FROM input_usage u
             INNER JOIN inputs i ON i.id = u.input_id
             LEFT JOIN farmers f ON f.id = u.farmer_id
             LEFT JOIN gardens g ON g.id = u.garden_id
             LEFT JOIN crops c ON c.id = u.crop_id
             LEFT JOIN seasons s ON s.id = u.season_id
             LEFT JOIN input_issues ii ON ii.id = u.issue_id
             LEFT JOIN users ru ON ru.id = u.recorded_by
             WHERE u.usage_code = :code LIMIT 1',
            ['code' => $code]
        );

        if ($usage === null) {
            throw new HttpException(404, 'No usage record found with the ID ' . $code . '.');
        }

        return $usage;
    }

    /**
     * Restricts the issue and usage registers to what the signed-in user is
     * responsible for.
     *
     * The fragment is anchored on the joined farmers table rather than on the
     * register itself, because the row a farmer owns may be recorded by an
     * officer on the farmer's behalf. It is also the one alias both the COUNT
     * and the SELECT query share, so the summary strip and the page it sits
     * above can never disagree.
     *
     * A farmer sees only their own records. An officer sees the records of the
     * farmers assigned to them.
     *
     * @param array<string,mixed> $values
     */
    private function scopeWhere(array &$values): string
    {
        $farmerId  = $this->auth->farmerId();
        $officerId = $this->auth->officerId();

        if ($farmerId !== null) {
            $values['scope_farmer'] = $farmerId;

            return ' AND f.id = :scope_farmer';
        }

        if ($officerId !== null) {
            $values['scope_officer'] = $officerId;

            return ' AND f.officer_id = :scope_officer';
        }

        return '';
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }
}
