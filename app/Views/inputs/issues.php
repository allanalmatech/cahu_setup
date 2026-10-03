<?php
/**
 * Input issue register: everything handed to farmers and what became of it.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $rows
 * @var Core\Paginator $paginator
 * @var string $sort
 * @var string $direction
 * @var array<string,int> $summary
 * @var array<int,array<string,mixed>> $filters
 */
$tiles = [
    ['key' => 'issued', 'label' => 'Awaiting use', 'tone' => 'blue'],
    ['key' => 'in_use', 'label' => 'Partly used', 'tone' => 'amber'],
    ['key' => 'used_up', 'label' => 'Fully used', 'tone' => 'green'],
];

ob_start();
?>
<div class="table-responsive">
    <table class="table cahu-table align-middle mb-0">
        <thead>
        <tr>
            <th style="width:130px"><?= $view->partial('partials/sort', ['column' => 'issue_code', 'label' => 'Issue ID', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Input</th>
            <th>Farmer / plot</th>
            <th><?= $view->partial('partials/sort', ['column' => 'issue_date', 'label' => 'Issued', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end"><?= $view->partial('partials/sort', ['column' => 'quantity', 'label' => 'Quantity', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end"><?= $view->partial('partials/sort', ['column' => 'quantity_used', 'label' => 'Used', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Officer</th>
            <th><?= $view->partial('partials/sort', ['column' => 'status', 'label' => 'Status', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 9,
                'title'       => 'No inputs issued',
                'message'     => 'Handing input to a farmer creates the issue record and takes the quantity off the shelf.',
                'actionLabel' => 'Issue input',
                'actionUrl'   => url('input-issues/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="cahu-table__code">
                        <a href="<?= e(url('input-issues/' . $row['issue_code'])) ?>"><?= e($row['issue_code']) ?></a>
                    </td>
                    <td>
                        <div class="fw-semibold">
                            <a class="text-decoration-none" href="<?= e(url('inputs/' . $row['input_code'])) ?>">
                                <?= e($row['input_name']) ?>
                            </a>
                        </div>
                        <div class="text-muted small"><?= e($row['purpose'] ?: 'No purpose recorded') ?></div>
                    </td>
                    <td>
                        <?php if (!empty($row['farmer_code'])): ?>
                            <a class="text-decoration-none" href="<?= e(url('farmers/' . $row['farmer_code'])) ?>">
                                <?= e($row['farmer_name']) ?>
                            </a>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                        <div class="text-muted small"><?= e($row['garden_name'] ?: 'No plot') ?></div>
                    </td>
                    <td class="text-nowrap"><?= e(format_date($row['issue_date'])) ?></td>
                    <td class="text-end text-nowrap"><?= e(format_quantity($row['quantity'], $row['unit'])) ?></td>
                    <td class="text-end text-nowrap">
                        <?= e(format_quantity($row['quantity_used'], $row['unit'])) ?>
                        <div class="text-muted small">
                            <?= e(format_number(
                                (float) $row['quantity'] > 0
                                    ? (float) $row['quantity_used'] / (float) $row['quantity'] * 100
                                    : 0,
                                0
                            )) ?>%
                        </div>
                    </td>
                    <td class="text-muted small"><?= e($row['officer_name'] ?: '—') ?></td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-primary" href="<?= e(url('input-issues/' . $row['issue_code'])) ?>">Open</a>
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
    'title'            => 'Issued inputs',
    'subtitle'         => 'Every handover of cooperative input to a farmer, and how much has been applied since.',
    'actions'          => [
        ['label' => 'Inventory', 'url' => url('inputs'), 'icon' => '▦', 'style' => 'cahu-btn-outline'],
        ['label' => 'Issue input', 'url' => url('input-issues/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'          => $filters,
    'searchPlaceholder' => 'Search by issue ID, input or farmer...',
    'clearUrl'         => url('input-issues'),
    'paginator'        => $paginator,
    'content'          => $content,
    'before'           => '<div class="cahu-stats mb-3">'
        . implode('', array_map(
            static fn (array $tile): string => sprintf(
                '<div class="cahu-stat cahu-stat--%s"><div class="cahu-stat__label">%s</div>'
                . '<div class="cahu-stat__value">%s</div></div>',
                $tile['tone'],
                e($tile['label']),
                e((string) ($summary[$tile['key']] ?? 0))
            ),
            $tiles
        ))
        . '</div>',
]);
