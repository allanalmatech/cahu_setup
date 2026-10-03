<?php
/**
 * Problem report register.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $rows
 * @var Core\Paginator $paginator
 * @var string $sort
 * @var string $direction
 * @var array<int,array<string,mixed>> $filters
 * @var array<string,int> $summary
 */
$tiles = [
    ['label' => 'Total reports', 'value' => format_number($summary['total']), 'tone' => 'green'],
    ['label' => 'Still open', 'value' => format_number($summary['open']), 'tone' => 'amber'],
    ['label' => 'Critical', 'value' => format_number($summary['critical']), 'tone' => 'red'],
    ['label' => 'Waiting for an owner', 'value' => format_number($summary['unassigned']), 'tone' => 'blue'],
];

ob_start();
?>
<div class="table-responsive">
    <table class="table cahu-table align-middle mb-0">
        <thead>
        <tr>
            <th><?= $view->partial('partials/sort', ['column' => 'problem_code', 'label' => 'Report ID', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'report_date', 'label' => 'Reported', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Problem</th>
            <th>Farmer / Garden</th>
            <th><?= $view->partial('partials/sort', ['column' => 'severity', 'label' => 'Severity', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Assigned officer</th>
            <th><?= $view->partial('partials/sort', ['column' => 'status', 'label' => 'Status', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 8,
                'title'       => 'No problem reports',
                'message'     => 'A report puts a farmer\'s concern on record and makes someone accountable for it.',
                'actionLabel' => 'Report a problem',
                'actionUrl'   => url('problems/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr<?= $row['severity'] === 'critical' && $row['status'] !== 'closed' ? ' class="cahu-row--critical"' : '' ?>>
                    <td class="cahu-table__code">
                        <a href="<?= e(url('problems/' . $row['problem_code'])) ?>"><?= e($row['problem_code']) ?></a>
                        <?php if ((int) $row['photo_count'] > 0): ?>
                            <div class="text-muted small"><?= (int) $row['photo_count'] ?> photo(s)</div>
                        <?php endif; ?>
                    </td>
                    <td><?= e(format_date($row['report_date'])) ?></td>
                    <td>
                        <div class="fw-semibold"><?= e($row['problem_type_name'] ?: 'Unclassified') ?></div>
                        <div class="text-muted small">
                            <?= e(mb_strimwidth((string) $row['description'], 0, 70, '…')) ?>
                        </div>
                    </td>
                    <td>
                        <a class="text-decoration-none" href="<?= e(url('farmers/' . $row['farmer_code'])) ?>">
                            <?= e($row['farmer_name']) ?>
                        </a>
                        <div class="text-muted small"><?= e($row['garden_code'] ?: 'Whole farm') ?></div>
                    </td>
                    <td><?= status_badge((string) $row['severity']) ?></td>
                    <td>
                        <?php if ($row['officer_name'] !== null): ?>
                            <?= e($row['officer_name']) ?>
                        <?php else: ?>
                            <span class="text-muted">Unassigned</span>
                        <?php endif; ?>
                    </td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('problems/' . $row['problem_code'])) ?>">Open</a>
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
    'title'             => 'Problem reports',
    'subtitle'          => 'Everything a farmer has raised, who owns it, and what has been done about it.',
    'actions'           => [
        ['label' => 'Report a problem', 'url' => url('problems/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'           => $filters,
    'searchPlaceholder' => 'Search by report ID, farmer, garden or description...',
    'clearUrl'          => url('problems'),
    'paginator'         => $paginator,
    'content'           => $content,
    'before'            => '<div class="cahu-stats mb-3">'
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