<?php
/**
 * Input inventory register.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $rows
 * @var Core\Paginator $paginator
 * @var array{items:int,low:int,value:float} $summary
 * @var array<int,array<string,string,mixed>> $filters
 */
$tiles = [
    ['key' => 'items', 'label' => 'Active items', 'value' => (string) $summary['items'], 'tone' => 'green'],
    ['key' => 'low', 'label' => 'At reorder level', 'value' => (string) $summary['low'], 'tone' => 'amber'],
    ['key' => 'value', 'label' => 'Stock value', 'value' => format_money($summary['value']), 'tone' => 'blue'],
];

ob_start();
?>
<div class="table-responsive">
    <table class="table cahu-table align-middle mb-0">
        <thead>
        <tr>
            <th style="width:110px">Input ID</th>
            <th>Item</th>
            <th>Category</th>
            <th class="text-end">In stock</th>
            <th class="text-end">Reorder level</th>
            <th>Last movement</th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 7,
                'title'       => 'No input items found',
                'message'     => 'Adjust the search or category filter, or register the first item on the shelf.',
                'actionLabel' => 'Register input item',
                'actionUrl'   => url('inputs/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <?php $low = (int) $row['low_stock'] === 1; ?>
                <tr>
                    <td class="cahu-table__code">
                        <a href="<?= e(url('inputs/' . $row['input_code'])) ?>"><?= e($row['input_code']) ?></a>
                    </td>
                    <td>
                        <div class="fw-semibold"><?= e($row['name']) ?></div>
                        <div class="text-muted small">
                            <?= e($row['brand'] ?: 'Brand not recorded') ?>
                            <?php if (!empty($row['crop_name'])): ?>
                                &middot; <?= e($row['crop_name']) ?> only
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><?= e($row['category_name']) ?></td>
                    <td class="text-end">
                        <div class="fw-semibold"><?= e(format_number($row['current_stock'])) ?></div>
                        <div class="text-muted small"><?= e($row['unit']) ?></div>
                        <?php if ($low): ?>
                            <div class="mt-1"><span class="badge text-bg-warning">Low stock</span></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?= e(format_number($row['reorder_level'])) ?>
                    </td>
                    <td class="text-muted small"><?= e(format_date($row['last_movement'] ?? null)) ?></td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('inputs/' . $row['input_code'] . '/stock')) ?>">Stock</a>
                        <a class="btn btn-sm cahu-btn-primary" href="<?= e(url('inputs/' . $row['input_code'])) ?>">Open</a>
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
    'title'            => 'Input inventory',
    'subtitle'         => 'Everything the cooperative holds in store, with the reorder level for each item.',
    'actions'          => [
        ['label' => 'Usage records', 'url' => url('input-usage'), 'icon' => '▤', 'style' => 'cahu-btn-outline'],
        ['label' => 'Issue input', 'url' => url('input-issues/create'), 'icon' => '↗', 'style' => 'cahu-btn-outline'],
        ['label' => 'Register item', 'url' => url('inputs/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'          => $filters,
    'searchPlaceholder' => 'Search by item name, ID or brand...',
    'clearUrl'         => url('inputs'),
    'paginator'        => $paginator,
    'content'          => $content,
    'before'           => '<div class="cahu-stats mb-3">'
        . implode('', array_map(
            static fn (array $tile): string => sprintf(
                '<div class="cahu-stat cahu-stat--%s"><div class="cahu-stat__label">%s</div>'
                . '<div class="cahu-stat__value">%s</div></div>',
                $tile['tone'],
                e($tile['label']),
                e($tile['value'])
            ),
            $tiles
        ))
        . '</div>',
]);
