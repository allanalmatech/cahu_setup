<?php
/**
 * Input item record: shelf position, the full movement ledger, and everything
 * issued to farmers and applied in the field.
 *
 * @var Core\View $view
 * @var array<string,mixed> $input
 * @var bool $lowStock
 * @var array<int,array<string,mixed>> $transactions
 * @var float $totalIssued
 * @var float $totalUsage
 * @var array<int,array<string,mixed>> $openIssues
 * @var int $openIssueCount
 */
$code = (string) $input['input_code'];
$unit = (string) $input['unit'];
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($input['name']) ?></h1>
        <p class="cahu-pagehead__sub">
            <span class="cahu-table__code"><?= e($code) ?></span>
            &middot; <?= e($input['category_name']) ?>
            &middot; <?= status_badge((string) $input['status']) ?>
            <?php if ($lowStock): ?>
                &middot; <span class="badge text-bg-warning">Low stock</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('inputs')) ?>">Back to inventory</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('inputs/' . $code . '/edit')) ?>">Edit</a>
        <a class="btn cahu-btn-primary" href="<?= e(url('inputs/' . $code . '/stock')) ?>">Record stock movement</a>
    </div>
</div>

<?php if ($lowStock): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <div>
            <strong>Stock is at or below the reorder level.</strong>
            <?= e(format_number($input['current_stock'])) ?> <?= e($unit) ?> remain against a reorder level of
            <?= e(format_number($input['reorder_level'])) ?> <?= e($unit) ?>. Record a receipt before the next issue round.
        </div>
    </div>
<?php endif; ?>

<div class="cahu-stats mb-3">
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">In stock</div>
        <div class="cahu-stat__value"><?= e(format_number($input['current_stock'])) ?></div>
        <div class="cahu-stat__meta"><?= e($unit) ?></div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Issued to farmers</div>
        <div class="cahu-stat__value"><?= e(format_number($totalIssued)) ?></div>
        <div class="cahu-stat__meta">cancelled issues excluded</div>
    </div>
    <div class="cahu-stat cahu-stat--soil">
        <div class="cahu-stat__label">Applied in the field</div>
        <div class="cahu-stat__value"><?= e(format_number($totalUsage)) ?></div>
        <div class="cahu-stat__meta">confirmed by usage records</div>
    </div>
    <div class="cahu-stat cahu-stat--amber">
        <div class="cahu-stat__label">Open issues</div>
        <div class="cahu-stat__value"><?= e($openIssueCount) ?></div>
        <div class="cahu-stat__meta">still with the farmer</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-4">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Item details</div>
            <div class="cahu-card__body">
                <dl class="cahu-kv mb-0">
                    <?php
                    $details = [
                        'Category'          => $input['category_name'],
                        'Unit of measure'   => $input['unit'],
                        'Brand'             => $input['brand'],
                        'Active ingredient' => $input['active_ingredient'],
                        'Crop specific'     => $input['crop_name'],
                        'Reorder level'     => format_number($input['reorder_level']) . ' ' . $input['unit'],
                        'Unit cost'         => $input['unit_cost'] === null ? null : format_money($input['unit_cost']),
                        'Stock value'       => format_money((float) $input['current_stock'] * (float) ($input['unit_cost'] ?? 0)),
                        'Registered'        => format_date($input['created_at']),
                        'Last updated'      => format_datetime($input['updated_at']),
                    ];
                    ?>
                    <?php foreach ($details as $label => $value): ?>
                        <?php if ($value === null || trim((string) $value) === '' || $value === '—') { continue; } ?>
                        <dt><?= e($label) ?></dt>
                        <dd><?= e($value) ?></dd>
                    <?php endforeach; ?>
                </dl>
                <?php if (!empty($input['description'])): ?>
                    <hr>
                    <div class="text-muted small">Description</div>
                    <p class="mb-0"><?= nl2br(e($input['description'])) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-8">
        <div class="cahu-card mb-3">
            <div class="cahu-card__head">Stock movements</div>
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
                                'columns' => 6,
                                'title'   => 'No movements yet',
                                'message' => 'Every receipt, issue, return and write-off for this item appears here with its running balance.',
                            ]) ?>
                        <?php else: ?>
                            <?php foreach ($transactions as $transaction): ?>
                                <?php $outgoing = in_array((string) $transaction['transaction_type'], ['issue', 'usage', 'damage', 'expired'], true); ?>
                                <tr>
                                    <td class="text-nowrap"><?= e(format_date($transaction['transaction_date'])) ?></td>
                                    <td class="cahu-table__code"><?= e($transaction['transaction_code']) ?></td>
                                    <td>
                                        <?= e(ucwords(str_replace('_', ' ', (string) $transaction['transaction_type']))) ?>
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
            <div class="cahu-card__foot d-flex justify-content-between align-items-center">
                <span class="text-muted small">Oldest movement first; the balance column is the running balance.</span>
                <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('inputs/' . $code . '/stock')) ?>">Record a movement</a>
            </div>
        </div>

        <div class="cahu-card">
            <div class="cahu-card__head">Open issues</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Issue ID</th>
                            <th>Farmer</th>
                            <th>Issued</th>
                            <th class="text-end">Issued qty</th>
                            <th class="text-end">Used</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($openIssues === []): ?>
                            <?= $view->partial('partials/empty', [
                                'columns'     => 6,
                                'title'       => 'Nothing outstanding',
                                'message'     => 'Every issue of this item has been used in full or closed off.',
                                'actionLabel' => 'Issue this item',
                                'actionUrl'   => url('input-issues/create'),
                            ]) ?>
                        <?php else: ?>
                            <?php foreach ($openIssues as $issue): ?>
                                <tr>
                                    <td class="cahu-table__code">
                                        <a href="<?= e(url('input-issues/' . $issue['issue_code'])) ?>">
                                            <?= e($issue['issue_code']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php if (!empty($issue['farmer_code'])): ?>
                                            <a class="text-decoration-none"
                                               href="<?= e(url('farmers/' . $issue['farmer_code'])) ?>">
                                                <?= e($issue['farmer_name']) ?>
                                            </a>
                                        <?php else: ?>
                                            &mdash;
                                        <?php endif; ?>
                                        <div class="text-muted small"><?= e($issue['garden_name'] ?: 'No plot') ?></div>
                                    </td>
                                    <td class="text-nowrap"><?= e(format_date($issue['issue_date'])) ?></td>
                                    <td class="text-end text-nowrap">
                                        <?= e(format_quantity($issue['quantity'], $issue['unit'])) ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <?= e(format_quantity($issue['quantity_used'], $issue['unit'])) ?>
                                    </td>
                                    <td><?= status_badge((string) $issue['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
