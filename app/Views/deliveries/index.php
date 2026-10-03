<?php
/**
 * Delivery register.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $rows
 * @var Core\Paginator $paginator
 * @var string $sort
 * @var string $direction
 * @var array<int,array<string,mixed>> $filters
 */
ob_start();
?>
<div class="table-responsive">
    <table class="table cahu-table align-middle mb-0">
        <thead>
        <tr>
            <th><?= $view->partial('partials/sort', ['column' => 'delivery_code', 'label' => 'Delivery ID', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Sale / buyer</th>
            <th><?= $view->partial('partials/sort', ['column' => 'dispatch_date', 'label' => 'Dispatched', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Expected</th>
            <th><?= $view->partial('partials/sort', ['column' => 'delivered_date', 'label' => 'Delivered', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Location</th>
            <th>Transport</th>
            <th><?= $view->partial('partials/sort', ['column' => 'status', 'label' => 'Status', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 9,
                'title'       => 'No deliveries found',
                'message'     => 'Adjust the filters, or dispatch a confirmed sale from its record.',
                'actionLabel' => 'Go to sales',
                'actionUrl'   => url('sales'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="cahu-table__code">
                        <a href="<?= e(url('deliveries/' . $row['delivery_code'] . '/edit')) ?>">
                            <?= e($row['delivery_code']) ?>
                        </a>
                    </td>
                    <td>
                        <a class="text-decoration-none" href="<?= e(url('sales/' . $row['sale_code'])) ?>">
                            <?= e($row['sale_code']) ?>
                        </a>
                        <div class="text-muted small"><?= e($row['buyer_name']) ?></div>
                    </td>
                    <td class="small"><?= e(format_date($row['dispatch_date'])) ?></td>
                    <td class="small"><?= e(format_date($row['expected_date'])) ?></td>
                    <td class="small"><?= e(format_date($row['delivered_date'])) ?></td>
                    <td class="small"><?= e($row['delivery_location'] ?: '—') ?></td>
                    <td class="small">
                        <?= e($row['transporter_name'] ?: '—') ?>
                        <?php if (!empty($row['vehicle_number'])): ?>
                            <div class="text-muted"><?= e($row['vehicle_number']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($row['tracking_number'])): ?>
                            <div class="text-muted">Tracking <?= e($row['tracking_number']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('deliveries/' . $row['delivery_code'] . '/edit')) ?>">
                            Update
                        </a>
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
<form class="cahu-filters" method="get" action="<?= e(url('deliveries')) ?>" role="search" aria-label="Sale date range">
    <?php foreach (['search', 'sort', 'direction', 'status', 'buyer_id'] as $carry): ?>
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
               data-autosubmit aria-label="Sale from">
    </div>
    <div class="cahu-filters__search">
        <input class="form-control" type="date" name="date_to" value="<?= e((string) ($_GET['date_to'] ?? '')) ?>"
               data-autosubmit aria-label="Sale to">
    </div>
    <noscript><button class="btn cahu-btn-outline btn-sm" type="submit">Apply</button></noscript>
</form>
<?php
$before = ob_get_clean();

$view->partial('partials/register', [
    'title'             => 'Deliveries',
    'subtitle'          => 'Every consignment dispatched to a buyer, and where it has got to.',
    'actions'           => [
        ['label' => 'Sales', 'url' => url('sales'), 'icon' => '←', 'style' => 'cahu-btn-outline'],
    ],
    'before'            => $before,
    'filters'           => $filters,
    'searchPlaceholder' => 'Search by delivery ID, sale, buyer, transporter, vehicle or tracking number...',
    'clearUrl'          => url('deliveries'),
    'paginator'         => $paginator,
    'content'           => $content,
]);