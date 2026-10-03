<?php
/**
 * Activity register: the work queue.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $rows
 * @var Core\Paginator $paginator
 * @var string $sort
 * @var string $direction
 * @var array<string,int> $summary
 */
$tiles = [
    ['key' => 'overdue', 'label' => 'Overdue', 'tone' => 'amber'],
    ['key' => 'due', 'label' => 'Due today', 'tone' => 'blue'],
    ['key' => 'pending', 'label' => 'Upcoming', 'tone' => 'green'],
    ['key' => 'completed', 'label' => 'Completed', 'tone' => 'soil'],
];

ob_start();
?>
<div class="table-responsive">
    <table class="table cahu-table align-middle mb-0">
        <thead>
        <tr>
            <th><?= $view->partial('partials/sort', ['column' => 'activity_code', 'label' => 'Activity ID', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Activity</th>
            <th>Farmer / plot</th>
            <th><?= $view->partial('partials/sort', ['column' => 'planned_date', 'label' => 'Planned', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'due_date', 'label' => 'Due', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'priority', 'label' => 'Priority', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Assigned</th>
            <th><?= $view->partial('partials/sort', ['column' => 'status', 'label' => 'Status', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 9,
                'title'       => 'No activities found',
                'message'     => 'Activities are generated from the crop lifecycle when a planting is recorded, and can also be added by hand.',
                'actionLabel' => 'Add activity',
                'actionUrl'   => url('activities/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr<?= $row['is_late'] ? ' class="cahu-row--late"' : '' ?>>
                    <td class="cahu-table__code">
                        <a href="<?= e(url('activities/' . $row['activity_code'])) ?>"><?= e($row['activity_code']) ?></a>
                    </td>
                    <td>
                        <span class="fw-semibold"><?= e($row['activity_name'] ?? ucwords(str_replace('_', ' ', (string) $row['activity_type']))) ?></span>
                        <?php if (!empty($row['description'])): ?>
                            <div class="text-muted small"><?= e($row['description']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="text-decoration-none" href="<?= e(url('farmers/' . $row['farmer_code'])) ?>">
                            <?= e($row['full_name']) ?>
                        </a>
                        <div class="text-muted small">
                            <?= e($row['garden_code']) ?><?= $row['crop_name'] ? ' · ' . e($row['crop_name']) : '' ?>
                        </div>
                    </td>
                    <td><?= e(format_date($row['planned_date'])) ?></td>
                    <td>
                        <?= e(format_date($row['due_date'])) ?>
                        <?php if ($row['is_late']): ?>
                            <div class="text-danger small">
                                <?= e((int) ((strtotime(date('Y-m-d')) - strtotime((string) $row['due_date'])) / 86400)) ?> day(s) late
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><?= priority_badge((string) $row['priority']) ?></td>
                    <td class="small">
                        <?php if ($row['assignee_type'] === 'farmer'): ?>
                            Farmer
                        <?php else: ?>
                            <?= e($row['officer_name'] ?: ucfirst((string) $row['assignee_type'])) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <?php if ($row['status'] !== 'completed' && $row['status'] !== 'cancelled'): ?>
                            <a class="btn btn-sm cahu-btn-primary" href="<?= e(url('activities/' . $row['activity_code'] . '/edit')) ?>">Record</a>
                        <?php else: ?>
                            <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('activities/' . $row['activity_code'])) ?>">Open</a>
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
    'title'             => 'Crop activities',
    'subtitle'          => 'Every task in the crop cycle, and the evidence of the work done.',
    'actions'           => [
        ['label' => 'Calendar', 'url' => url('activities/calendar'), 'icon' => '▦', 'style' => 'cahu-btn-outline'],
        ['label' => 'Add activity', 'url' => url('activities/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'           => $filters ?? [],
    'searchPlaceholder' => 'Search by activity ID, type or farmer...',
    'clearUrl'          => url('activities'),
    'paginator'         => $paginator,
    'content'           => $content,
    'before'            => '<div class="cahu-stats mb-3">'
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