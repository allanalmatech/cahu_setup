<?php
/**
 * Planting register.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $rows
 * @var Core\Paginator $paginator
 * @var string $sort
 * @var string $direction
 */
ob_start();
?>
<div class="table-responsive">
    <table class="table cahu-table align-middle mb-0">
        <thead>
        <tr>
            <th><?= $view->partial('partials/sort', ['column' => 'planting_code', 'label' => 'Planting ID', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th><?= $view->partial('partials/sort', ['column' => 'planting_date', 'label' => 'Planted', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Crop</th>
            <th>Farmer / garden</th>
            <th>Season</th>
            <th class="text-end"><?= $view->partial('partials/sort', ['column' => 'acreage_planted', 'label' => 'Acres', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Schedule</th>
            <th>Harvested</th>
            <th><?= $view->partial('partials/sort', ['column' => 'status', 'label' => 'Status', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 10,
                'title'       => 'No plantings recorded',
                'message'     => 'Recording a planting generates the activity schedule and the yield forecast automatically.',
                'actionLabel' => 'Record planting',
                'actionUrl'   => url('plantings/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <?php
                $done      = (int) $row['activity_done'];
                $total     = (int) $row['activity_count'];
                $percent   = $total > 0 ? (int) round($done / $total * 100) : 0;
                $harvested = (float) $row['harvested'];
                $forecast  = $row['expected_yield'] !== null ? (float) $row['expected_yield'] : null;
                ?>
                <tr>
                    <td class="cahu-table__code">
                        <a href="<?= e(url('plantings/' . $row['planting_code'])) ?>"><?= e($row['planting_code']) ?></a>
                    </td>
                    <td><?= e(format_date($row['planting_date'])) ?></td>
                    <td>
                        <span class="fw-semibold"><?= e($row['crop_name']) ?></span>
                        <?php if (!empty($row['variety_name'])): ?>
                            <div class="text-muted small"><?= e($row['variety_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="text-decoration-none" href="<?= e(url('farmers/' . $row['farmer_code'])) ?>">
                            <?= e($row['full_name']) ?>
                        </a>
                        <div class="text-muted small"><?= e($row['garden_code'] ?: 'No garden linked') ?></div>
                    </td>
                    <td><?= e($row['season_code'] ?: '—') ?></td>
                    <td class="text-end"><?= e(format_number($row['acreage_planted'])) ?></td>
                    <td style="min-width:9rem">
                        <?php if ($total === 0): ?>
                            <span class="text-muted small">No schedule</span>
                        <?php else: ?>
                            <div class="d-flex justify-content-between small">
                                <span><?= e($done) ?>/<?= e($total) ?> done</span>
                                <span class="text-muted"><?= e($percent) ?>%</span>
                            </div>
                            <div class="cahu-progress" role="progressbar"
                                 aria-valuenow="<?= e($percent) ?>" aria-valuemin="0" aria-valuemax="100"
                                 aria-label="Activities completed">
                                <i style="width: <?= e($percent) ?>%"></i>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($harvested > 0): ?>
                            <span class="fw-semibold"><?= e(format_number($harvested)) ?></span>
                            <?php if ($forecast !== null && $forecast > 0): ?>
                                <div class="text-muted small">
                                    <?= e((int) min(999, round($harvested / $forecast * 100))) ?>% of <?= e(format_number($forecast)) ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('plantings/' . $row['planting_code'])) ?>">Open</a>
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
    'title'             => 'Crops and plantings',
    'subtitle'          => 'Every crop committed to a plot, with the tasks and harvest it has produced.',
    'actions'           => [
        ['label' => 'Record planting', 'url' => url('plantings/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'           => $filters ?? [],
    'searchPlaceholder' => 'Search by planting ID, crop or farmer...',
    'clearUrl'          => url('plantings'),
    'paginator'         => $paginator,
    'content'           => $content,
]);