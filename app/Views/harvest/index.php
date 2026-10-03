<?php
/**
 * Harvest register: everything that has come off the field.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $rows
 * @var Core\Paginator $paginator
 * @var array<int,array{key:string,label:string,options:array}> $filters
 * @var string $sort
 * @var string $direction
 * @var float $totalQty
 * @var int $records
 * @var bool $canManage
 */
// partial() echoes and returns void, so the helper echoes too.
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
            <th><?= $sortCell('harvest_date', 'Harvest ID') ?></th>
            <th>Crop &amp; farmer</th>
            <th>Plot</th>
            <th class="text-end"><?= $sortCell('quantity_harvested', 'Quantity') ?></th>
            <th>Quality</th>
            <th>Batch</th>
            <th><?= $sortCell('status', 'Status') ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 8,
                'title'       => 'No harvests found',
                'message'     => 'Harvests appear here once produce is recorded against a planting.',
                'actionLabel' => $canManage ? 'Record harvest' : null,
                'actionUrl'   => $canManage ? url('harvest/create') : null,
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="cahu-table__code">
                        <a href="<?= e(url('harvest/' . $row['harvest_code'])) ?>"><?= e($row['harvest_code']) ?></a>
                        <div class="text-muted small"><?= e(format_date($row['harvest_date'])) ?></div>
                    </td>
                    <td>
                        <span class="fw-semibold"><?= e($row['crop_name'] ?? '—') ?></span>
                        <div class="text-muted small">
                            <a class="text-decoration-none" href="<?= e(url('farmers/' . $row['farmer_code'])) ?>">
                                <?= e($row['full_name']) ?>
                            </a>
                        </div>
                    </td>
                    <td><?= e($row['garden_code'] ?? '—') ?></td>
                    <td class="text-end cahu-table__number">
                        <?= e(format_number($row['quantity_harvested'])) ?>
                        <span class="text-muted small"><?= e($row['unit']) ?></span>
                    </td>
                    <td><?= status_badge((string) ($row['quality'] ?: $row['grade'] ?: 'ungraded')) ?></td>
                    <td>
                        <?php if (!empty($row['batch_code'])): ?>
                            <a href="<?= e(url('batches/' . $row['batch_code'])) ?>"><?= e($row['batch_code']) ?></a>
                        <?php else: ?>
                            <span class="text-muted small">unbatched</span>
                        <?php endif; ?>
                    </td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('harvest/' . $row['harvest_code'])) ?>">Open</a>
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
    'title'             => 'Harvest records',
    'subtitle'          => sprintf(
        '%s record(s), %s units recorded in total.',
        number_format($records),
        format_number($totalQty)
    ),
    'actions'           => $canManage
        ? [['label' => 'Record harvest', 'url' => url('harvest/create'), 'icon' => '+', 'style' => 'cahu-btn-primary']]
        : [],
    'filters'           => $filters,
    'searchPlaceholder' => 'Search by harvest ID, crop or farmer...',
    'clearUrl'          => url('harvest'),
    'paginator'         => $paginator,
    'content'           => $content,
]);