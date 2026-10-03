<?php
/**
 * Full-screen command map with its filter sidebar.
 *
 * @var Core\View $view
 * @var array<int,string> $layers
 * @var array<int,string> $activeLayers
 * @var array<string,mixed> $filters
 * @var int $harvestWindow
 * @var array{lat:float,lng:float,zoom:int,tileUrl:string,attribution:string} $mapConfig
 * @var int $layerLimit
 * @var array<int,array{value:string,label:string}> $districts
 * @var array<int,array{value:string,label:string}> $subCounties
 * @var array<int,array{value:string,label:string}> $crops
 * @var array<int,array{value:string,label:string}> $seasons
 * @var array<int,array{value:string,label:string}> $officers
 * @var array<int,array{value:string,label:string}> $groups
 */
$layerLabels = [
    'gardens'       => 'Gardens',
    'harvest-ready' => 'Ready to harvest',
    'farmers'       => 'Farmers',
    'officers'      => 'Field officers',
    'problems'      => 'Problem reports',
    'inspections'   => 'Inspections',
];

$dataUrl = url('map/data') . '?' . http_build_query(array_filter(
    $filters['values'],
    static fn ($value): bool => $value !== null && $value !== ''
));
?>
<div class="cahu-pagehead">
    <div>
        <h1>Farm map</h1>
        <p class="cahu-pagehead__sub">
            Mapped gardens, farmers, officers, problem reports and inspections. Click any marker to open the record behind it.
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('gardens')) ?>">Garden register</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('reports')) ?>">Reports</a>
    </div>
</div>

<form class="row g-3 mb-3" id="cahu-map-filters" method="get" action="<?= e(url('map')) ?>">
    <div class="col-12 col-lg-3">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Filters</div>
            <div class="cahu-card__body">
                <div class="mb-3">
                    <label class="form-label" for="map-district">District</label>
                    <select class="form-select" name="district_id" id="map-district">
                        <?php foreach ($districts as $option): ?>
                            <option value="<?= e($option['value']) ?>"
                                <?= (string) $filters['district_id'] === (string) $option['value'] ? 'selected' : '' ?>>
                                <?= e($option['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="map-sub-county">Sub-county</label>
                    <select class="form-select" name="sub_county_id" id="map-sub-county">
                        <?php foreach ($subCounties as $option): ?>
                            <option value="<?= e($option['value']) ?>"
                                <?= (string) $filters['sub_county_id'] === (string) $option['value'] ? 'selected' : '' ?>>
                                <?= e($option['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="map-crop">Crop</label>
                    <select class="form-select" name="crop_id" id="map-crop">
                        <?php foreach ($crops as $option): ?>
                            <option value="<?= e($option['value']) ?>"
                                <?= (string) $filters['crop_id'] === (string) $option['value'] ? 'selected' : '' ?>>
                                <?= e($option['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="map-season">Season</label>
                    <select class="form-select" name="season_id" id="map-season">
                        <?php foreach ($seasons as $option): ?>
                            <option value="<?= e($option['value']) ?>"
                                <?= (string) $filters['season_id'] === (string) $option['value'] ? 'selected' : '' ?>>
                                <?= e($option['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="map-group">Farmer group</label>
                    <select class="form-select" name="group_id" id="map-group">
                        <?php foreach ($groups as $option): ?>
                            <option value="<?= e($option['value']) ?>"
                                <?= (string) $filters['group_id'] === (string) $option['value'] ? 'selected' : '' ?>>
                                <?= e($option['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="map-officer">Field officer</label>
                    <select class="form-select" name="officer_id" id="map-officer">
                        <?php foreach ($officers as $option): ?>
                            <option value="<?= e($option['value']) ?>"
                                <?= (string) $filters['officer_id'] === (string) $option['value'] ? 'selected' : '' ?>>
                                <?= e($option['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="map-problem-status">Problem status</label>
                    <select class="form-select" name="problem_status" id="map-problem-status">
                        <option value="">All problem statuses</option>
                        <?php foreach (['open', 'reported', 'assigned', 'investigating', 'action_taken', 'resolved', 'closed'] as $status): ?>
                            <option value="<?= e($status) ?>" <?= $filters['problem_status'] === $status ? 'selected' : '' ?>>
                                <?= e(ucwords(str_replace('_', ' ', $status))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="map-harvest-status">Harvest status</label>
                    <select class="form-select" name="harvest_status" id="map-harvest-status">
                        <option value="">All harvest statuses</option>
                        <?php foreach (['growing', 'harvest_ready', 'harvested'] as $status): ?>
                            <option value="<?= e($status) ?>" <?= $filters['harvest_status'] === $status ? 'selected' : '' ?>>
                                <?= e(ucwords(str_replace('_', ' ', $status))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <div class="form-label">Layers</div>
                    <?php foreach ($layers as $layer): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="layer[]"
                                   value="<?= e($layer) ?>" id="layer-<?= e($layer) ?>"
                                <?= in_array($layer, $activeLayers, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="layer-<?= e($layer) ?>">
                                <?= e($layerLabels[$layer] ?? $layer) ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button class="btn cahu-btn-primary w-100" type="submit">Apply filters</button>
                <a class="btn btn-link btn-sm w-100 text-decoration-none" href="<?= e(url('map')) ?>">Reset</a>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-9">
        <div class="cahu-card mb-3">
            <div class="cahu-card__body d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div id="cahu-map-status" class="text-muted small">Loading map data&hellip;</div>
                <div class="text-muted small">
                    Ready-to-harvest window: <?= e($harvestWindow) ?> day(s) &middot; at most <?= e($layerLimit) ?> points per layer
                </div>
            </div>
        </div>

        <div class="cahu-card">
            <div class="cahu-card__body">
                <div class="cahu-map-wrap">
                    <div class="cahu-map cahu-map--tall" id="cahu-map" role="application" aria-label="Farm map"></div>

                    <div class="cahu-map-legend" id="cahu-map-legend">
                        <?php foreach (['#2f7d4f' => 'Garden', '#d9a441' => 'Ready to harvest', '#3b82a0' => 'Farmer',
                                        '#7b5ea7' => 'Field officer', '#c0563b' => 'Problem', '#3d8f7a' => 'Inspection'] as $colour => $label): ?>
                            <div><i style="background:<?= e($colour) ?>"></i><?= e($label) ?></div>
                        <?php endforeach; ?>
                        <div><i style="background:#8c1d18"></i>Critical problem</div>
                    </div>
                </div>

                <noscript>
                    <div class="alert alert-warning mb-0">
                        The map needs JavaScript. The register lists carry the same records in a printable form.
                    </div>
                </noscript>
            </div>
        </div>
    </div>
</form>

<?php
$payload = [
    'url'    => $dataUrl,
    'config' => $mapConfig,
    'labels' => $layerLabels,
];

/**
 * Everything handed to the browser is encoded with the tag-escaping flags, so a
 * crop name or a farmer's address can never close the script block.
 */
$booted = 'const CAHU_MAP = ' . json_encode(
    $payload,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
) . ';';

$view->share('pageScripts', [
    $booted,
    <<<'JS'
document.addEventListener('DOMContentLoaded', function () {
    var host = document.getElementById('cahu-map');
    if (!host || typeof L === 'undefined') return;

    var settings = window.CAHU_MAP || {};
    var config = settings.config || {};

    var statusLine = document.getElementById('cahu-map-status');
    var form = document.getElementById('cahu-map-filters');

    var palette = {
        'gardens': '#2f7d4f',
        'harvest-ready': '#d9a441',
        'farmers': '#3b82a0',
        'officers': '#7b5ea7',
        'inspections': '#3d8f7a',
        'problems': { critical: '#8c1d18', high: '#c0563b', medium: '#d9a441', low: '#7d8b84' }
    };

    var map = L.map(host).setView([config.lat, config.lng], config.zoom || 7);

    L.tileLayer(config.tileUrl, { attribution: config.attribution, maxZoom: 19 }).addTo(map);

    var drawn = {};
    var markerLayer = L.layerGroup().addTo(map);

    function colour(properties) {
        var scheme = palette[properties.layer];
        if (typeof scheme === 'string') return scheme;
        if (scheme) return scheme[properties.severity] || scheme.medium;
        return '#7d8b84';
    }

    function radius(properties) {
        if (properties.layer === 'harvest-ready') return 9;
        if (properties.layer === 'officers') return 7;
        return 6;
    }

    function draw(collection) {
        markerLayer.clearLayers();
        drawn = {};

        (collection.features || []).forEach(function (feature) {
            if (!feature.geometry) return;

            var properties = feature.properties || {};
            var coordinates = feature.geometry.coordinates;

            var marker = L.circleMarker([coordinates[1], coordinates[0]], {
                radius: radius(properties),
                color: colour(properties),
                fillColor: colour(properties),
                fillOpacity: 0.75,
                weight: 1.5
            });

            if (properties.popup) {
                marker.bindPopup(properties.popup);
            }

            marker.addTo(markerLayer);
            drawn[properties.layer] = (drawn[properties.layer] || 0) + 1;
        });
    }

    function describe(meta) {
        var labels = settings.labels || {};
        var layers = meta.layers || {};
        var parts = [];

        Object.keys(layers).forEach(function (layer) {
            var entry = layers[layer];
            parts.push((labels[layer] || layer) + ': ' + entry.shown
                + (entry.truncated ? ' of ' + entry.total : ''));
        });

        return parts.join('  \u00b7  ');
    }

    function collection_features_total() {
        return Object.keys(drawn).reduce(function (sum, layer) { return sum + drawn[layer]; }, 0);
    }

    function refresh(url) {
        if (statusLine) statusLine.textContent = 'Loading map data...';

        window.Cahu.get(url)
            .then(function (payload) {
                var meta = payload.meta || {};

                if (meta.lat !== undefined) {
                    map.setView([meta.lat, meta.lng], meta.zoom || config.zoom);
                }

                draw(payload);

                if (statusLine) {
                    var count = collection_features_total();
                    statusLine.textContent = count + ' point(s) plotted  \u00b7  ' + describe(meta);
                }
            })
            .catch(function () {
                if (statusLine) statusLine.textContent = 'The map data could not be loaded. Try applying the filters again.';
            });
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var query = new URLSearchParams(new FormData(form)).toString();

            refresh(settings.url.split('?')[0] + '?' + query);
        });
    }

    refresh(settings.url);
});
JS
]);
?>