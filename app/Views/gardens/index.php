<?php
/**
 * Garden register.
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
            <th><?= $view->partial('partials/sort', ['column' => 'garden_code', 'label' => 'Garden ID', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'name', 'label' => 'Garden', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Farmer</th>
            <th>Location</th>
            <th class="text-end"><?= $view->partial('partials/sort', ['column' => 'acreage', 'label' => 'Acres', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Last planting</th>
            <th><?= $view->partial('partials/sort', ['column' => 'status', 'label' => 'Status', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 8,
                'title'       => 'No gardens found',
                'message'     => 'Gardens are the plots where crops are recorded. Add one to start a farm record.',
                'actionLabel' => 'Add garden',
                'actionUrl'   => url('gardens/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="cahu-table__code">
                        <a href="<?= e(url('gardens/' . $row['garden_code'])) ?>"><?= e($row['garden_code']) ?></a>
                    </td>
                    <td class="fw-semibold"><?= e($row['name']) ?></td>
                    <td>
                        <a class="text-decoration-none" href="<?= e(url('farmers/' . $row['farmer_code'])) ?>">
                            <?= e($row['full_name']) ?>
                        </a>
                        <div class="text-muted small"><?= e($row['farmer_code']) ?></div>
                    </td>
                    <td>
                        <?= e($row['village_name'] ?: '—') ?>
                        <div class="text-muted small"><?= e($row['district_name'] ?: 'District not set') ?></div>
                    </td>
                    <td class="text-end"><?= e(format_number($row['acreage'])) ?></td>
                    <td>
                        <?php if ($row['last_planting'] !== null): ?>
                            <?= e(format_date($row['last_planting'])) ?>
                            <div class="text-muted small"><?= (int) $row['planting_count'] ?> planting(s)</div>
                        <?php else: ?>
                            <span class="text-muted">Never planted</span>
                        <?php endif; ?>
                    </td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('gardens/' . $row['garden_code'])) ?>">Open</a>
                        <a class="btn btn-sm btn-link text-decoration-none"
                           href="<?= e(url('gardens/' . $row['garden_code'] . '/edit')) ?>">Edit</a>
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
    'title'             => 'Gardens',
    'subtitle'          => 'Every plot, its acreage and the farmer who works it.',
    'actions'           => [
        ['label' => 'Add garden', 'url' => url('gardens/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'           => $filters,
    'searchPlaceholder' => 'Search by garden ID, name, farmer or phone...',
    'clearUrl'          => url('gardens'),
    'paginator'         => $paginator,
    'content'           => $content,
]);
