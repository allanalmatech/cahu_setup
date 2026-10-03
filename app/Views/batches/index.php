<?php
/**
 * Batch register: the aggregated lots available to buy or ship.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $rows
 * @var Core\Paginator $paginator
 * @var array<int,array{key:string,label:string,options:array}> $filters
 * @var string $sort
 * @var string $direction
 * @var array<string,mixed> $totals
 * @var bool $canManage
 */
$sortCell = static function (string $column, string $label) use ($view, $sort, $direction): void {
    $view->partial('partials/sort', [
        'column'           => $column,
        'label'            => $label,
        'currentSort'      => $sort,
        'currentDirection' => $direction,
    ]);
};

ob_start();
?>
<div class="table-responsive">
    <table class="table cahu-table align-middle mb-0">
        <thead>
        <tr>
            <th><?= $sortCell('batch_date', 'Batch ID') ?></th>
            <th>Crop &amp; season</th>
            <th class="text-end"><?= $sortCell('total_quantity', 'Quantity') ?></th>
            <th class="text-end"><?= $sortCell('contributor_count', 'Farmers') ?></th>
            <th>Storage</th>
            <th>Downstream</th>
            <th><?= $sortCell('status', 'Status') ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 8,
                'title'       => 'No batches yet',
                'message'     => 'A batch gathers harvests of the same crop into one lot that a buyer can purchase as a unit.',
                'actionLabel' => $canManage ? 'Create batch' : null,
                'actionUrl'   => $canManage ? url('batches/create') : null,
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="cahu-table__code">
                        <a href="<?= e(url('batches/' . $row['batch_code'])) ?>"><?= e($row['batch_code']) ?></a>
                        <div class="text-muted small"><?= e(format_date($row['batch_date'])) ?></div>
                    </td>
                    <td>
                        <span class="fw-semibold"><?= e($row['crop_name'] ?? '—') ?></span>
                        <div class="text-muted small"><?= e($row['season_name'] ?? '') ?></div>
                    </td>
                    <td class="text-end cahu-table__number">
                        <?= e(format_number($row['total_quantity'])) ?>
                        <span class="text-muted small"><?= e($row['unit']) ?></span>
                    </td>
                    <td class="text-end cahu-table__number"><?= e((string) (int) $row['contributor_count']) ?></td>
                    <td class="small"><?= e($row['storage_location'] ?: '—') ?></td>
                    <td class="small">
                        <?php $saleCount = (int) $row['sale_lines']; $exportCount = (int) $row['export_lines']; ?>
                        <?php if ($saleCount === 0 && $exportCount === 0): ?>
                            <span class="text-muted">none</span>
                        <?php else: ?>
                            <?php if ($saleCount > 0): ?>
                                <span class="badge text-bg-light"><?= e((string) $saleCount) ?> sale line(s)</span>
                            <?php endif; ?>
                            <?php if ($exportCount > 0): ?>
                                <span class="badge text-bg-light"><?= e((string) $exportCount) ?> export(s)</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('batches/' . $row['batch_code'])) ?>">Open</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
$content = ob_get_clean();

$view->partial('partials/register', [
    'title'             => 'Batches',
    'subtitle'          => sprintf(
        '%s batch(es) holding %s units from %s contributing farmer(s).',
        number_format((int) $totals['batches']),
        format_number($totals['quantity']),
        number_format((int) $totals['contributors'])
    ),
    'actions'           => $canManage
        ? [['label' => 'Create batch', 'url' => url('batches/create'), 'icon' => '+', 'style' => 'cahu-btn-primary']]
        : [],
    'filters'           => $filters,
    'searchPlaceholder' => 'Search by batch ID, crop or storage location...',
    'clearUrl'          => url('batches'),
    'paginator'         => $paginator,
    'content'           => $content,
]);