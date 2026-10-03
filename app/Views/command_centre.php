<?php
/**
 * Command Centre: the executive dashboard.
 *
 * @var Core\View $view
 * @var array<int,array{key:string,label:string,tiles:array<int,array<string,mixed>>}> $sections
 * @var array{registrations:array<int,array<string,mixed>>,cropMix:array<int,array<string,mixed>>,activityMix:array<int,array<string,mixed>>,districtProduction:array<int,array<string,mixed>>} $charts
 * @var array{district_id:?int,season_id:?int,crop_id:?int,values:array<string,mixed>} $filters
 * @var array<int,array{value:string,label:string}> $districts
 * @var array<int,array{value:string,label:string}> $seasons
 * @var array<int,array{value:string,label:string}> $crops
 * @var string $dataUrl
 */
$tones = ['green', 'blue', 'amber', 'red', 'soil'];

$registrationLabels = array_column($charts['registrations'], 'period');
$registrationData  = array_map('intval', array_column($charts['registrations'], 'total'));

$cropLabels = array_column($charts['cropMix'], 'name');
$cropData   = array_map('floatval', array_column($charts['cropMix'], 'acreage'));

$activityPeriods = [];
$activitySeries  = [];

foreach ($charts['activityMix'] as $row) {
    $period = (string) $row['period'];
    $status = (string) $row['status'];

    if (!in_array($period, $activityPeriods, true)) {
        $activityPeriods[] = $period;
    }

    if (!isset($activitySeries[$status])) {
        $activitySeries[$status] = array_fill_keys($activityPeriods, 0);
    }

    $activitySeries[$status][$period] = (int) $row['total'];
}

foreach ($activitySeries as $status => $points) {
    $activitySeries[$status] = array_values(array_map(
        static fn (string $period): int => (int) ($points[$period] ?? 0),
        $activityPeriods
    ));
}

$districtLabels = array_column($charts['districtProduction'], 'district');
$districtData   = array_map('floatval', array_column($charts['districtProduction'], 'quantity'));
?>
<div class="cahu-pagehead">
    <div>
        <h1>Command Centre</h1>
        <p class="cahu-pagehead__sub">Programme figures across farmers, land, production and markets. Read-only.</p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('map')) ?>">Open map</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('reports')) ?>">Reports</a>
    </div>
</div>

<form class="cahu-filters mb-3" method="get" action="<?= e(url('command-centre')) ?>">
    <select class="form-select" name="season_id" data-autosubmit aria-label="Season">
        <?php foreach ($seasons as $option): ?>
            <option value="<?= e($option['value']) ?>" <?= (string) $filters['season_id'] === (string) $option['value'] ? 'selected' : '' ?>>
                <?= e($option['label']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select class="form-select" name="district_id" data-autosubmit aria-label="District">
        <?php foreach ($districts as $option): ?>
            <option value="<?= e($option['value']) ?>" <?= (string) $filters['district_id'] === (string) $option['value'] ? 'selected' : '' ?>>
                <?= e($option['label']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select class="form-select" name="crop_id" data-autosubmit aria-label="Crop">
        <?php foreach ($crops as $option): ?>
            <option value="<?= e($option['value']) ?>" <?= (string) $filters['crop_id'] === (string) $option['value'] ? 'selected' : '' ?>>
                <?= e($option['label']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <noscript><button class="btn cahu-btn-outline btn-sm" type="submit">Apply</button></noscript>

    <a class="btn btn-link btn-sm text-decoration-none" href="<?= e(url('command-centre')) ?>">Clear</a>
</form>

<?php foreach ($sections as $section): ?>
    <div class="mb-4">
        <div class="d-flex align-items-baseline gap-2 mb-2">
            <h2 class="h6 text-uppercase text-muted mb-0"><?= e($section['label']) ?></h2>
        </div>

        <div class="cahu-stats">
            <?php foreach ($section['tiles'] as $tile): ?>
                <?php $tone = in_array($tile['tone'] ?? '', $tones, true) ? (string) $tile['tone'] : 'green'; ?>
                <div class="cahu-stat cahu-stat--<?= e($tone) ?>">
                    <div class="cahu-stat__label"><?= e($tile['label']) ?></div>
                    <div class="cahu-stat__value" data-kpi="<?= e($tile['key']) ?>">
                        <?= e(($tile['format'] ?? '') === 'number' ? format_number($tile['value']) : number_format((float) $tile['value'])) ?>
                    </div>
                    <?php if (!empty($tile['meta'])): ?>
                        <div class="cahu-stat__meta"><?= e($tile['meta']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>

<div class="row g-3">
    <div class="col-12 col-xl-6">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Farmer registrations</div>
            <div class="cahu-card__body">
                <?php if ($registrationLabels === []): ?>
                    <p class="text-muted mb-0">No registrations in the last 12 months.</p>
                <?php else: ?>
                    <canvas id="chart-registrations" height="220"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Acreage by crop</div>
            <div class="cahu-card__body">
                <?php if ($cropLabels === []): ?>
                    <p class="text-muted mb-0">No plantings recorded.</p>
                <?php else: ?>
                    <canvas id="chart-crops" height="220"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Activity status</div>
            <div class="cahu-card__body">
                <?php if ($activityPeriods === []): ?>
                    <p class="text-muted mb-0">No activities scheduled in the last 6 months.</p>
                <?php else: ?>
                    <canvas id="chart-activity" height="220"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Production by district</div>
            <div class="cahu-card__body">
                <?php if ($districtLabels === []): ?>
                    <p class="text-muted mb-0">No harvest recorded.</p>
                <?php else: ?>
                    <canvas id="chart-districts" height="220"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$booted = 'const CC_DATA_URL = ' . json_encode($dataUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';'
    . 'const CC_REGISTRATION = { labels: ' . json_encode($registrationLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)
        . ', data: ' . json_encode($registrationData) . ' };'
    . 'const CC_CROPS = { labels: ' . json_encode($cropLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)
        . ', data: ' . json_encode($cropData) . ' };'
    . 'const CC_ACTIVITY = { labels: ' . json_encode($activityPeriods, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)
        . ', series: ' . json_encode($activitySeries) . ' };'
    . 'const CC_DISTRICTS = { labels: ' . json_encode($districtLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)
        . ', data: ' . json_encode($districtData) . ' };';

$view->share('pageScripts', [
    $booted,
    <<<'JS'
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;

    var palette = ['#2f7d4f', '#3b82a0', '#d9a441', '#c0563b', '#7b8b6f', '#8a6bbf'];
    var statusColours = {
        completed: '#2f7d4f', pending: '#7d8b84', due: '#d9a441', overdue: '#c0563b', cancelled: '#e2e8e4'
    };
    var grid = 'rgba(16, 41, 28, .08)';

    var registrations = document.getElementById('chart-registrations');
    if (registrations) {
        new Chart(registrations, {
            type: 'line',
            data: {
                labels: CC_REGISTRATION.labels,
                datasets: [{
                    label: 'New farmers',
                    data: CC_REGISTRATION.data,
                    borderColor: '#2f7d4f',
                    backgroundColor: 'rgba(47, 125, 79, .15)',
                    fill: true,
                    tension: .3
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: grid } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    var crops = document.getElementById('chart-crops');
    if (crops) {
        new Chart(crops, {
            type: 'doughnut',
            data: {
                labels: CC_CROPS.labels,
                datasets: [{
                    data: CC_CROPS.data,
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

    var activity = document.getElementById('chart-activity');
    if (activity) {
        var datasets = Object.keys(CC_ACTIVITY.series).map(function (status) {
            return {
                label: status.charAt(0).toUpperCase() + status.slice(1),
                data: CC_ACTIVITY.series[status],
                backgroundColor: statusColours[status] || '#7d8b84',
                borderWidth: 0
            };
        });

        new Chart(activity, {
            type: 'bar',
            data: { labels: CC_ACTIVITY.labels, datasets: datasets },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { position: 'right', labels: { boxWidth: 10, usePointStyle: true } } },
                scales: {
                    x: { stacked: true, grid: { display: false } },
                    y: { stacked: true, beginAtZero: true, grid: { color: grid } }
                }
            }
        });
    }

    var districts = document.getElementById('chart-districts');
    if (districts) {
        new Chart(districts, {
            type: 'bar',
            data: {
                labels: CC_DISTRICTS.labels,
                datasets: [{
                    label: 'Harvest recorded',
                    data: CC_DISTRICTS.data,
                    backgroundColor: '#3b82a0',
                    borderRadius: 4
                }]
            },
            options: {
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, grid: { color: grid } },
                    y: { grid: { display: false } }
                }
            }
        });
    }

    // The tiles refresh from the sibling data endpoint, the same way the header
    // bell stays current, so a manager watching the screen sees today's figures.
    setInterval(function () {
        window.Cahu.get(CC_DATA_URL).then(function (payload) {
            (payload.sections || []).forEach(function (section) {
                (section.tiles || []).forEach(function (tile) {
                    var node = document.querySelector('[data-kpi="' + tile.key + '"]');
                    if (!node) return;

                    var value = tile.format === 'number'
                        ? Number(tile.value).toLocaleString(undefined, { maximumFractionDigits: 2 })
                        : Number(tile.value).toLocaleString();

                    if (node.textContent !== value) node.textContent = value;
                });
            });
        }).catch(function () { /* the rendered figures stand until the next poll */ });
    }, 60000);
});
JS
]);
?>