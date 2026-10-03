<?php
/**
 * Inspection register.
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
    ['label' => 'Total inspections', 'value' => format_number($summary['total']), 'tone' => 'green'],
    ['label' => 'Last 30 days', 'value' => format_number($summary['recent']), 'tone' => 'blue'],
    ['label' => 'Poor or critical', 'value' => format_number($summary['poor']), 'tone' => 'amber'],
    ['label' => 'Drafts', 'value' => format_number($summary['drafts']), 'tone' => 'soil'],
];

ob_start();
?>
<div class="table-responsive">
    <table class="table cahu-table align-middle mb-0">
        <thead>
        <tr>
            <th><?= $view->partial('partials/sort', ['column' => 'inspection_code', 'label' => 'Inspection ID', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'inspection_date', 'label' => 'Date', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Farmer / Garden</th>
            <th>Crop</th>
            <th><?= $view->partial('partials/sort', ['column' => 'crop_condition', 'label' => 'Condition', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Pests / Disease</th>
            <th>Officer</th>
            <th><?= $view->partial('partials/sort', ['column' => 'status', 'label' => 'Status', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 9,
                'title'       => 'No inspections recorded',
                'message'     => 'Inspections are the officer\'s read on a plot. Recording one feeds the yield forecast and the advice the farmer is given.',
                'actionLabel' => 'Record inspection',
                'actionUrl'   => url('inspections/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="cahu-table__code">
                        <a href="<?= e(url('inspections/' . $row['inspection_code'])) ?>"><?= e($row['inspection_code']) ?></a>
                        <?php if ((int) $row['finding_count'] > 0): ?>
                            <div class="text-muted small"><?= (int) $row['finding_count'] ?> finding(s)</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= e(format_date($row['inspection_date'])) ?>
                        <div class="text-muted small"><?= e(ucwords(str_replace('_', ' ', (string) $row['growth_stage'])) ?: 'Stage not recorded') ?></div>
                    </td>
                    <td>
                        <a class="text-decoration-none" href="<?= e(url('farmers/' . $row['farmer_code'])) ?>">
                            <?= e($row['full_name']) ?>
                        </a>
                        <div class="text-muted small"><?= e($row['garden_code'] . ' · ' . ($row['garden_name'] ?: '—')) ?></div>
                    </td>
                    <td><?= e($row['crop_name'] ?: '—') ?></td>
                    <td><?= status_badge((string) ($row['crop_condition'] ?? 'not assessed')) ?></td>
                    <td class="small">
                        <?= status_badge((string) ($row['pest_status'] ?? 'none')) ?>
                        <?= status_badge((string) ($row['disease_status'] ?? 'none')) ?>
                    </td>
                    <td><?= e($row['officer_name'] ?: '—') ?></td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('inspections/' . $row['inspection_code'])) ?>">Open</a>
                        <?php if ($row['status'] === 'draft'): ?>
                            <a class="btn btn-sm btn-link text-decoration-none"
                               href="<?= e(url('inspections/' . $row['inspection_code'] . '/edit')) ?>">Edit</a>
                        <?php endif; ?>
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
    'title'             => 'Inspections',
    'subtitle'          => 'What each plot looked like, and what was recommended from it.',
    'actions'           => [
        ['label' => 'Record inspection', 'url' => url('inspections/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'           => $filters,
    'searchPlaceholder' => 'Search by inspection ID, farmer, garden or recommendation...',
    'clearUrl'          => url('inspections'),
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