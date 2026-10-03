<?php
/**
 * Sales register.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $rows
 * @var Core\Paginator $paginator
 * @var string $sort
 * @var string $direction
 * @var array<string,mixed> $totals
 * @var array<int,array<string,mixed>> $filters
 */
ob_start();
?>
<div class="table-responsive">
    <table class="table cahu-table align-middle mb-0">
        <thead>
        <tr>
            <th><?= $view->partial('partials/sort', ['column' => 'sale_code', 'label' => 'Sale ID', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'sale_date', 'label' => 'Sale date', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Buyer</th>
            <th class="text-end">Lines</th>
            <th class="text-end">Quantity</th>
            <th class="text-end"><?= $view->partial('partials/sort', ['column' => 'total_value', 'label' => 'Value', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end"><?= $view->partial('partials/sort', ['column' => 'amount_paid', 'label' => 'Received', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Payment</th>
            <th>Delivery</th>
            <th>Sale status</th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 11,
                'title'       => 'No sales found',
                'message'     => 'Adjust the filters, or record the first sale from an aggregated batch.',
                'actionLabel' => 'Record sale',
                'actionUrl'   => url('sales/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="cahu-table__code">
                        <a href="<?= e(url('sales/' . $row['sale_code'])) ?>"><?= e($row['sale_code']) ?></a>
                    </td>
                    <td class="small"><?= e(format_date($row['sale_date'])) ?></td>
                    <td>
                        <div class="fw-semibold"><?= e($row['buyer_name']) ?></div>
                        <div class="text-muted small">
                            <?= e($row['buyer_type'] ? ucwords(str_replace('_', ' ', (string) $row['buyer_type'])) : 'buyer') ?>
                            <?= $row['market_name'] ? ' · ' . e($row['market_name']) : '' ?>
                        </div>
                    </td>
                    <td class="text-end"><?= e((string) (int) $row['item_count']) ?></td>
                    <td class="text-end"><?= e(format_number($row['quantity'], 3)) ?></td>
                    <td class="text-end"><?= e(format_money($row['total_value'], (string) $row['currency'])) ?></td>
                    <td class="text-end"><?= e(format_money($row['amount_paid'], (string) $row['currency'])) ?></td>
                    <td><?= status_badge((string) $row['payment_status']) ?></td>
                    <td>
                        <?php if ((int) $row['delivery_count'] === 0): ?>
                            <span class="text-muted small">Not dispatched</span>
                        <?php else: ?>
                            <?= status_badge((string) $row['delivery_status']) ?>
                            <div class="text-muted small"><?= e((string) (int) $row['delivery_count']) ?> delivery(s)</div>
                        <?php endif; ?>
                    </td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('sales/' . $row['sale_code'])) ?>">Open</a>
                        <a class="btn btn-sm btn-link text-decoration-none"
                           href="<?= e(url('sales/' . $row['sale_code'] . '/edit')) ?>">Edit</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
$content = ob_get_clean();

ob_start();
?>
<div class="cahu-stats mb-3">
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">Sales in view</div>
        <div class="cahu-stat__value"><?= e((string) (int) $totals['sales']) ?></div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Value</div>
        <div class="cahu-stat__value"><?= e(format_money($totals['value'], $rows[0]['currency'] ?? 'UGX')) ?></div>
    </div>
    <div class="cahu-stat cahu-stat--amber">
        <div class="cahu-stat__label">Received</div>
        <div class="cahu-stat__value"><?= e(format_money($totals['received'], $rows[0]['currency'] ?? 'UGX')) ?></div>
    </div>
    <div class="cahu-stat cahu-stat--red">
        <div class="cahu-stat__label">Outstanding</div>
        <div class="cahu-stat__value"><?= e(format_money((float) $totals['value'] - (float) $totals['received'], $rows[0]['currency'] ?? 'UGX')) ?></div>
    </div>
</div>
<form class="cahu-filters" method="get" action="<?= e(url('sales')) ?>" role="search" aria-label="Sale date range">
    <?php foreach (['search', 'sort', 'direction', 'status', 'payment_status', 'buyer_id'] as $carry): ?>
        <?php if (!empty($_GET[$carry])): ?>
            <input type="hidden" name="<?= e($carry) ?>" value="<?= e((string) $_GET[$carry]) ?>">
        <?php endif; ?>
    <?php endforeach; ?>
    <div class="cahu-filters__search">
        <span>
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg>
        </span>
        <input class="form-control" type="date" name="date_from" value="<?= e((string) ($_GET['date_from'] ?? '')) ?>"
               data-autosubmit aria-label="Sold from">
    </div>
    <div class="cahu-filters__search">
        <input class="form-control" type="date" name="date_to" value="<?= e((string) ($_GET['date_to'] ?? '')) ?>"
               data-autosubmit aria-label="Sold to">
    </div>
    <noscript><button class="btn cahu-btn-outline btn-sm" type="submit">Apply</button></noscript>
</form>
<?php
$before = ob_get_clean();

$view->partial('partials/register', [
    'title'             => 'Sales',
    'subtitle'          => 'Every consignment sold to a buyer, with what is still owed on it.',
    'actions'           => [
        ['label' => 'Deliveries', 'url' => url('deliveries'), 'icon' => '→', 'style' => 'cahu-btn-outline'],
        ['label' => 'Record sale', 'url' => url('sales/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'before'            => $before,
    'filters'           => $filters,
    'searchPlaceholder' => 'Search by sale ID, buyer or note...',
    'clearUrl'          => url('sales'),
    'paginator'         => $paginator,
    'content'           => $content,
]);