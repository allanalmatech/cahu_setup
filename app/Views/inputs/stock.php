<?php
/**
 * Stock movement screen for a single item: the current balance, the movement
 * form, and the ledger those movements have built up.
 *
 * @var Core\View $view
 * @var array<string,mixed> $input
 * @var bool $lowStock
 * @var array<int,array<string,mixed>> $transactions
 * @var array<int,array<string,mixed>> $movementFields
 */
$code    = (string) $input['input_code'];
$actions = url('inputs/' . $code);
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($input['name']) ?> &middot; stock</h1>
        <p class="cahu-pagehead__sub">
            <span class="cahu-table__code"><?= e($code) ?></span>
            &middot; <?= e($input['category_name']) ?>
            &middot; <?= e(format_number($input['current_stock'])) ?> <?= e($input['unit']) ?> on the shelf
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e($actions) ?>">Back to item</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('input-issues/create')) ?>">Issue to a farmer</a>
    </div>
</div>

<?php if ($errors = \Core\Flash::errors()): ?>
    <div class="alert alert-danger">
        <strong>Please correct the following:</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($errors as $message): ?>
                <li><?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="cahu-stats mb-3">
    <div class="cahu-stat <?= $lowStock ? 'cahu-stat--amber' : 'cahu-stat--green' ?>">
        <div class="cahu-stat__label">Current balance</div>
        <div class="cahu-stat__value"><?= e(format_number($input['current_stock'])) ?></div>
        <div class="cahu-stat__meta"><?= e($input['unit']) ?></div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Reorder level</div>
        <div class="cahu-stat__value"><?= e(format_number($input['reorder_level'])) ?></div>
        <div class="cahu-stat__meta"><?= $lowStock ? 'Reached — reorder now' : 'Not yet reached' ?></div>
    </div>
    <div class="cahu-stat cahu-stat--soil">
        <div class="cahu-stat__label">Movements recorded</div>
        <div class="cahu-stat__value"><?= e(count($transactions)) ?></div>
        <div class="cahu-stat__meta">
            <?= e($transactions === [] ? 'none yet' : 'last on ' . format_date($transactions[count($transactions) - 1]['transaction_date'])) ?>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="cahu-card">
            <div class="cahu-card__head">Movement ledger</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Reference</th>
                            <th>Movement</th>
                            <th class="text-end">Quantity</th>
                            <th class="text-end">Balance</th>
                            <th>Recorded by</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($transactions === []): ?>
                            <?= $view->partial('partials/empty', [
                                'columns'     => 6,
                                'title'       => 'No movements recorded',
                                'message'     => 'Record the opening balance or the first delivery received for this item.',
                            ]) ?>
                        <?php else: ?>
                            <?php foreach ($transactions as $transaction): ?>
                                <?php $outgoing = in_array((string) $transaction['transaction_type'], ['issue', 'usage', 'damage', 'expired'], true); ?>
                                <tr>
                                    <td class="text-nowrap"><?= e(format_date($transaction['transaction_date'])) ?></td>
                                    <td class="cahu-table__code"><?= e($transaction['transaction_code']) ?></td>
                                    <td>
                                        <?= e(ucwords(str_replace('_', ' ', (string) $transaction['transaction_type']))) ?>
                                        <?php if (!empty($transaction['reference_no'])): ?>
                                            <div class="text-muted small">Ref <?= e($transaction['reference_no']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($transaction['supplier'])): ?>
                                            <div class="text-muted small"><?= e($transaction['supplier']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($transaction['notes'])): ?>
                                            <div class="text-muted small"><?= e($transaction['notes']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap <?= $outgoing ? 'text-danger' : 'text-success' ?>">
                                        <?= $outgoing ? '−' : '+' ?><?= e(format_number($transaction['quantity'])) ?>
                                    </td>
                                    <td class="text-end fw-semibold"><?= e(format_number($transaction['balance_after'])) ?></td>
                                    <td class="text-muted small"><?= e($transaction['recorded_by_name'] ?: 'System') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="cahu-card__foot">
                <span class="text-muted small">Oldest movement first; the balance column is the running balance.</span>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="cahu-card">
            <div class="cahu-card__head">Record a movement</div>
            <div class="cahu-card__body">
                <form method="post" action="<?= e(url('inputs/' . $code . '/stock')) ?>" novalidate data-validate>
                    <?= csrf_field() ?>

                    <div class="row">
                        <?php foreach ($movementFields as $field): ?>
                            <?php $view->partial('partials/field', ['field' => $field, 'record' => null]); ?>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-text mb-3">
                        Receipts, returns and opening balances add to the shelf. Issues, damage and expiry take from it.
                        A movement can never take the balance below zero.
                    </div>

                    <button class="btn cahu-btn-primary w-100" type="submit">Record movement</button>
                </form>
            </div>
        </div>
    </div>
</div>
