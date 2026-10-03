<?php
/**
 * Role aware dashboard.
 *
 * @var Core\View $view
 * @var string $scopeLabel
 * @var array<string,int|float> $stats
 * @var array<string,int> $attention
 * @var array<int,array<string,mixed>> $activityMix
 * @var array<int,array<string,mixed>> $cropMix
 * @var array<int,array<string,mixed>> $registrations
 * @var array<string,mixed>|null $seasonProgress
 * @var array<int,array<string,mixed>> $recentActivity
 * @var array<int,array<string,mixed>> $recentProblems
 * @var Core\Auth $auth
 */
$isFarmer = $auth->farmerId() !== null;
$chartData = [
    'activity' => array_column($activityMix, 'total', 'status'),
    'crops'    => array_map(static fn (array $row): float => (float) $row['acreage'], $cropMix),
];
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="cahu-pagehead__sub"><?= e($scopeLabel) ?></p>
    </div>
    <div class="cahu-pagehead__actions">
        <?php if ($auth->can('farmers.create')): ?>
            <a class="btn cahu-btn-primary" href="<?= e(url('farmers/create')) ?>">Register farmer</a>
        <?php endif; ?>
        <?php if ($auth->can('activities.manage')): ?>
            <a class="btn cahu-btn-outline" href="<?= e(url('activities')) ?>">Log activity</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($seasonProgress !== null): ?>
    <div class="cahu-card mb-3">
        <div class="cahu-card__body d-flex flex-wrap align-items-center gap-4">
            <div>
                <div class="text-muted small">Current season</div>
                <div class="fw-semibold">
                    <?= e($seasonProgress['season']['season_code']) ?>
                    <span class="text-muted fw-normal">&middot; <?= e($seasonProgress['season']['name']) ?></span>
                </div>
                <div class="text-muted small">
                    <?= e(format_date($seasonProgress['season']['start_date'])) ?>
                    to <?= e(format_date($seasonProgress['season']['end_date'])) ?>
                </div>
            </div>
            <div class="vr d-none d-md-block"></div>
            <div>
                <div class="text-muted small">Plantings</div>
                <div class="fw-semibold"><?= e($seasonProgress['planted']) ?></div>
            </div>
            <div>
                <div class="text-muted small">Activities completed</div>
                <div class="fw-semibold">
                    <?= e($seasonProgress['completed']) ?>
                    <span class="text-muted fw-normal">of <?= e($seasonProgress['activities']) ?></span>
                </div>
                <div class="cahu-progress mt-1" style="width:160px">
                    <?php $done = percent((float) $seasonProgress['completed'], (float) max(1, $seasonProgress['activities']), 0); ?>
                    <span style="width:<?= e((string) $done) ?>%"></span>
                </div>
            </div>
            <div>
                <div class="text-muted small">Harvest recorded</div>
                <div class="fw-semibold"><?= e(format_quantity($seasonProgress['expected'])) ?></div>
            </div>
            <div class="ms-auto">
                <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('seasons/' . $seasonProgress['season']['id'])) ?>">Season detail</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="cahu-stats mb-3">
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">Active farmers</div>
        <div class="cahu-stat__value"><?= e(number_format((float) $stats['farmers'])) ?></div>
        <div class="cahu-stat__meta"><?= e(number_format((float) $stats['acreage'], 1)) ?> acres registered</div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Gardens</div>
        <div class="cahu-stat__value"><?= e(number_format((float) $stats['gardens'])) ?></div>
        <div class="cahu-stat__meta"><?= e(number_format((float) $stats['plantings'])) ?> plantings</div>
    </div>
    <div class="cahu-stat cahu-stat--amber">
        <div class="cahu-stat__label">Open problems</div>
        <div class="cahu-stat__value"><?= e(number_format((float) $stats['openProblems'])) ?></div>
        <div class="cahu-stat__meta"><?= e(number_format((float) $attention['criticalProblems'])) ?> critical</div>
    </div>
    <div class="cahu-stat cahu-stat--soil">
        <div class="cahu-stat__label">Harvest recorded</div>
        <div class="cahu-stat__value"><?= e(format_number((float) $stats['harvestTonnes'])) ?></div>
        <div class="cahu-stat__meta"><?= e(number_format((float) $stats['activities'])) ?> activities logged</div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-xl-4">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Needs attention</div>
            <div class="cahu-card__body">
                <ul class="list-unstyled mb-0">
                    <?php
                    $attentionItems = [
                        ['Overdue activities', $attention['overdueActivities'], url('activities?status=overdue'), 'cahu-stat--red'],
                        ['Due in the next 7 days', $attention['dueThisWeek'], url('activities?status=due'), 'cahu-stat--amber'],
                        ['Critical open problems', $attention['criticalProblems'], url('problems?severity=critical'), 'cahu-stat--red'],
                        ['Inputs at or below reorder level', $attention['lowStockInputs'], url('inputs?stock=low'), 'cahu-stat--amber'],
                        ['Documents expiring in 30 days', $attention['expiringDocuments'], url('farmers'), 'cahu-stat--blue'],
                    ];
                    ?>
                    <?php foreach ($attentionItems as [$label, $count, $link, $tone]): ?>
                        <li class="d-flex align-items-center justify-content-between py-2 border-bottom">
                            <a class="text-decoration-none <?= $count > 0 ? 'fw-semibold' : 'text-muted' ?>" href="<?= e($link) ?>">
                                <?= e($label) ?>
                            </a>
                            <span class="cahu-stat <?= e($tone) ?> d-inline-block px-2 py-1 rounded-pill <?= $count > 0 ? '' : 'opacity-50' ?>">
                                <?= e($count) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Activity status</div>
            <div class="cahu-card__body">
                <?php if ($activityMix === []): ?>
                    <p class="text-muted mb-0">No activities recorded yet.</p>
                <?php else: ?>
                    <canvas id="chart-activity" height="200"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Acreage by crop</div>
            <div class="cahu-card__body">
                <?php if ($cropMix === []): ?>
                    <p class="text-muted mb-0">No plantings recorded yet.</p>
                <?php else: ?>
                    <canvas id="chart-crops" height="200"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-4">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Farmer registrations</div>
            <div class="cahu-card__body">
                <?php if ($registrations === []): ?>
                    <p class="text-muted mb-0">No registrations in the last 12 months.</p>
                <?php else: ?>
                    <canvas id="chart-registrations" height="200"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Latest activity records</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <ul class="cahu-timeline mb-0">
                    <?php if ($recentActivity === []): ?>
                        <li class="p-3 text-muted">Nothing recorded yet.</li>
                    <?php else: ?>
                        <?php foreach ($recentActivity as $item): ?>
                            <li class="cahu-timeline__item">
                                <div class="d-flex justify-content-between gap-2">
                                    <span class="fw-semibold"><?= e(ucwords(str_replace('_', ' ', (string) $item['activity_type']))) ?></span>
                                    <?= status_badge((string) $item['status']) ?>
                                </div>
                                <div class="text-muted small">
                                    <?= e($item['full_name']) ?>
                                    <?php if (!empty($item['crop_name'])): ?> &middot; <?= e($item['crop_name']) ?><?php endif; ?>
                                </div>
                                <div class="text-muted small">
                                    <?= $item['completed_at'] !== null
                                        ? 'Completed ' . e(format_date($item['completed_at']))
                                        : 'Due ' . e(format_date($item['due_date'])) ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Recent problem reports</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <ul class="cahu-timeline mb-0">
                    <?php if ($recentProblems === []): ?>
                        <li class="p-3 text-muted">No problems reported.</li>
                    <?php else: ?>
                        <?php foreach ($recentProblems as $problem): ?>
                            <li class="cahu-timeline__item">
                                <div class="d-flex justify-content-between gap-2">
                                    <span class="fw-semibold"><?= e($problem['problem_type'] ?? 'Unclassified') ?></span>
                                    <?= status_badge((string) $problem['severity']) ?>
                                </div>
                                <div class="text-muted small">
                                    <?= e($problem['full_name']) ?>
                                    <span class="cahu-popup__code"><?= e($problem['farmer_code']) ?></span>
                                </div>
                                <div class="text-muted small">
                                    <?= e(format_date($problem['report_date'])) ?> &middot; <?= e($problem['status']) ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php
// Page scripts are shared with the layout rather than left as template locals,
// so the layout can print them after Chart.js has loaded.
$labels = [
    'completed' => 'Completed',
    'due'       => 'Due',
    'pending'   => 'Pending',
    'overdue'   => 'Overdue',
    'skipped'   => 'Skipped',
];

$booted = 'const ACTIVITY_LABELS = ' . json_encode(
    array_map(
        static fn (string $status): string => $labels[$status] ?? ucwords(str_replace('_', ' ', $status)),
        array_keys($activityMix)
    ),
    JSON_UNESCAPED_UNICODE
) . ';'
. 'const ACTIVITY_DATA = ' . json_encode(array_map('intval', array_column($activityMix, 'total'))) . ';'
. 'const CROP_LABELS = ' . json_encode(array_column($cropMix, 'name'), JSON_UNESCAPED_UNICODE) . ';'
. 'const CROP_DATA = ' . json_encode($chartData['crops']) . ';'
. 'const REGISTRATION_LABELS = ' . json_encode(array_column($registrations, 'period'), JSON_UNESCAPED_UNICODE) . ';'
. 'const REGISTRATION_DATA = ' . json_encode(array_map('intval', array_column($registrations, 'total'))) . ';';

$view->share('pageScripts', [
    $booted,
    <<<'JS'
document.addEventListener('DOMContentLoaded', function () {
    const palette = ['#2f7d4f', '#3b82a0', '#d9a441', '#c0563b', '#7b8b6f', '#8a6bbf'];
    const grid = 'rgba(16, 41, 28, .08)';

    const activity = document.getElementById('chart-activity');
    if (activity) {
        new Chart(activity, {
            type: 'doughnut',
            data: {
                labels: ACTIVITY_LABELS,
                datasets: [{
                    data: ACTIVITY_DATA,
                    backgroundColor: palette,
                    borderWidth: 0
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { position: 'right', labels: { boxWidth: 10, usePointStyle: true } } }
            }
        });
    }

    const crops = document.getElementById('chart-crops');
    if (crops) {
        new Chart(crops, {
            type: 'bar',
            data: {
                labels: CROP_LABELS,
                datasets: [{
                    label: 'Acres',
                    data: CROP_DATA,
                    backgroundColor: '#2f7d4f',
                    borderRadius: 4
                }]
            },
            options: {
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: { x: { grid: { color: grid } }, y: { grid: { display: false } } }
            }
        });
    }

    const registrations = document.getElementById('chart-registrations');
    if (registrations) {
        new Chart(registrations, {
            type: 'line',
            data: {
                labels: REGISTRATION_LABELS,
                datasets: [{
                    label: 'New farmers',
                    data: REGISTRATION_DATA,
                    borderColor: '#2f7d4f',
                    backgroundColor: 'rgba(47, 125, 79, .15)',
                    fill: true,
                    tension: .3
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, grid: { color: grid } }, x: { grid: { display: false } } }
            }
        });
    }
});
JS
]);
