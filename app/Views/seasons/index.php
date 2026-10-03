<?php
/**
 * Season register.
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
            <th style="width:110px"><?= $view->partial('partials/sort', ['column' => 'season_code', 'label' => 'Code', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'name', 'label' => 'Season', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'start_date', 'label' => 'Starts', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'end_date', 'label' => 'Ends', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Planted</th>
            <th class="text-end">Activities</th>
            <th class="text-end">Harvests</th>
            <th><?= $view->partial('partials/sort', ['column' => 'status', 'label' => 'Status', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 9,
                'title'       => 'No seasons defined',
                'message'     => 'Every planting, activity and harvest belongs to a season, and only one season can be open at a time.',
                'actionLabel' => 'Add season',
                'actionUrl'   => url('seasons/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="cahu-table__code"><?= e($row['season_code']) ?></td>
                    <td class="fw-semibold"><?= e($row['name']) ?></td>
                    <td><?= e(format_date($row['start_date'])) ?></td>
                    <td><?= e(format_date($row['end_date'])) ?></td>
                    <td class="text-end">
                        <div><?= (int) $row['planting_count'] ?></div>
                        <div class="text-muted small"><?= e(format_number($row['planted_acreage'])) ?> ac</div>
                    </td>
                    <td class="text-end">
                        <?php if ((int) $row['activity_count'] > 0): ?>
                            <a href="<?= e(url('activities?season_id=' . $row['id'])) ?>"><?= (int) $row['activity_count'] ?></a>
                        <?php else: ?>
                            <span class="text-muted">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ((int) $row['harvest_count'] > 0): ?>
                            <a href="<?= e(url('harvest?season_id=' . $row['id'])) ?>"><?= (int) $row['harvest_count'] ?></a>
                        <?php else: ?>
                            <span class="text-muted">0</span>
                        <?php endif; ?>
                    </td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <div class="d-flex justify-content-end align-items-center gap-2">
                            <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('plantings?season_id=' . $row['id'])) ?>">Plantings</a>
                            <a class="btn btn-sm btn-link text-decoration-none"
                               href="<?= e(url('seasons/' . $row['id'] . '/edit')) ?>">Edit</a>
                            <form method="post" action="<?= e(url('seasons/' . $row['id'] . '/delete')) ?>"
                                  onsubmit="return confirm('Delete this season? A season holding any records cannot be deleted.');">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-link text-danger text-decoration-none" type="submit">Delete</button>
                            </form>
                        </div>
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
    'title'             => 'Seasons',
    'subtitle'          => 'The production calendar, and everything filed against each season.',
    'actions'           => [
        ['label' => 'Add season', 'url' => url('seasons/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'           => $filters,
    'searchPlaceholder' => 'Search by season code or name...',
    'clearUrl'          => url('seasons'),
    'paginator'         => $paginator,
    'content'           => $content,
]);
