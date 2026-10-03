<?php
/**
 * Export register.
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
            <th><?= $view->partial('partials/sort', ['column' => 'export_code', 'label' => 'Export ID', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Sale / batch</th>
            <th>Buyer</th>
            <th><?= $view->partial('partials/sort', ['column' => 'destination_country', 'label' => 'Destination', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end"><?= $view->partial('partials/sort', ['column' => 'quantity', 'label' => 'Quantity', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'shipment_date', 'label' => 'Shipped', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>ETA</th>
            <th><?= $view->partial('partials/sort', ['column' => 'status', 'label' => 'Status', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 9,
                'title'       => 'No exports recorded',
                'message'     => 'Record a consignment against a sale to produce the traceability chain a buyer abroad will ask for.',
                'actionLabel' => 'Record export',
                'actionUrl'   => url('exports/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="cahu-table__code">
                        <a href="<?= e(url('exports/' . $row['export_code'])) ?>"><?= e($row['export_code']) ?></a>
                    </td>
                    <td class="small">
                        <?php if (!empty($row['sale_code'])): ?>
                            <a class="text-decoration-none" href="<?= e(url('sales/' . $row['sale_code'])) ?>">
                                <?= e($row['sale_code']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">no sale</span>
                        <?php endif; ?>
                        <?php if (!empty($row['batch_code'])): ?>
                            <div class="text-muted"><?= e($row['batch_code']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= e($row['buyer_name'] ?: '—') ?></td>
                    <td><?= e($row['destination_country']) ?></td>
                    <td class="text-end">
                        <?= e(format_number($row['quantity'], 3)) ?>
                        <span class="text-muted small"><?= e((string) ($row['unit'] ?? '')) ?></span>
                    </td>
                    <td class="small"><?= e(format_date($row['shipment_date'])) ?></td>
                    <td class="small"><?= e(format_date($row['eta'])) ?></td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('exports/' . $row['export_code'])) ?>">Open</a>
                        <a class="btn btn-sm btn-link text-decoration-none"
                           href="<?= e(url('exports/' . $row['export_code'] . '/trace')) ?>">Trace</a>
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
        <div class="cahu-stat__label">Consignments in view</div>
        <div class="cahu-stat__value"><?= e((string) (int) $totals['consignments']) ?></div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Quantity shipped</div>
        <div class="cahu-stat__value"><?= e(format_number($totals['quantity'], 3)) ?></div>
    </div>
</div>
<form class="cahu-filters" method="get" action="<?= e(url('exports')) ?>" role="search" aria-label="Shipment date range">
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
               data-autosubmit aria-label="Shipped from">
    </div>
    <div class="cahu-filters__search">
        <input class="form-control" type="date" name="date_to" value="<?= e((string) ($_GET['date_to'] ?? '')) ?>"
               data-autosubmit aria-label="Shipped to">
    </div>
    <noscript><button class="btn cahu-btn-outline btn-sm" type="submit">Apply</button></noscript>
</form>
<?php
$before = ob_get_clean();

$view->partial('partials/register', [
    'title'             => 'Exports',
    'subtitle'          => 'Consignments leaving the country, each traceable to the farmers who grew them.',
    'actions'           => [
        ['label' => 'Record export', 'url' => url('exports/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'before'            => $before,
    'filters'           => $filters,
    'searchPlaceholder' => 'Search by export ID, destination or certificate number...',
    'clearUrl'          => url('exports'),
    'paginator'         => $paginator,
    'content'           => $content,
]);