<?php
/**
 * Inspection detail: what was seen, what was found, and what happens next.
 *
 * @var Core\View $view
 * @var array<string,mixed> $inspection
 * @var array<int,array<string,mixed>> $findings
 * @var array<int,array<string,mixed>> $photos
 * @var array<string,mixed>|null $forecastBasis
 * @var array<int,array<string,mixed>> $history
 */
$severityCounts = array_count_values(array_map(
    static fn (array $finding): string => (string) $finding['severity'],
    $findings
));
?>
<div class="cahu-pagehead">
    <div>
        <h1>Inspection <?= e($inspection['inspection_code']) ?></h1>
        <p class="cahu-pagehead__sub">
            <?= e(format_date($inspection['inspection_date'])) ?>
            &middot; <?= status_badge((string) $inspection['status']) ?>
            &middot; <?= e($inspection['garden_code']) ?>
            &middot; <?= e($inspection['full_name']) ?>
            (<a href="<?= e(url('farmers/' . $inspection['farmer_code'])) ?>"><?= e($inspection['farmer_code']) ?></a>)
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('inspections')) ?>">Back to register</a>
        <?php if ($inspection['status'] === 'draft'): ?>
            <a class="btn cahu-btn-outline" href="<?= e(url('inspections/' . $inspection['inspection_code'] . '/edit')) ?>">Edit</a>
            <form method="post" class="d-inline"
                  action="<?= e(url('inspections/' . $inspection['inspection_code'] . '/delete')) ?>"
                  data-confirm="Discard draft <?= e($inspection['inspection_code']) ?>? This cannot be undone.">
                <?= csrf_field() ?>
                <button class="btn cahu-btn-outline" type="submit">Discard draft</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($inspection['status'] === 'draft'): ?>
    <div class="alert alert-warning">
        This inspection is still a draft. It is not counted in reports, the yield forecast or the farmer's
        record until it is submitted.
    </div>
<?php endif; ?>

<div class="cahu-stats mb-3">
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">Crop condition</div>
        <div class="cahu-stat__value"><?= e($inspection['crop_condition'] ? ucfirst((string) $inspection['crop_condition']) : 'Not assessed') ?></div>
        <div class="cahu-stat__meta"><?= e($inspection['growth_stage'] ? ucwords(str_replace('_', ' ', (string) $inspection['growth_stage'])) : 'Growth stage not recorded') ?></div>
    </div>
    <div class="cahu-stat cahu-stat--amber">
        <div class="cahu-stat__label">Findings</div>
        <div class="cahu-stat__value"><?= e(format_number(count($findings))) ?></div>
        <div class="cahu-stat__meta">
            <?= e((string) ($severityCounts['high'] ?? 0)) ?> high, <?= e((string) ($severityCounts['severe'] ?? 0)) ?> severe
        </div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Estimated yield</div>
        <div class="cahu-stat__value">
            <?= e($inspection['estimated_yield_per_acre'] !== null ? format_number($inspection['estimated_yield_per_acre'], 1) : '—') ?>
        </div>
        <div class="cahu-stat__meta">per acre</div>
    </div>
    <div class="cahu-stat cahu-stat--soil">
        <div class="cahu-stat__label">Coverage</div>
        <div class="cahu-stat__value">
            <?= e($inspection['field_coverage'] !== null ? format_number($inspection['field_coverage'], 1) . '%' : '—') ?>
        </div>
        <div class="cahu-stat__meta">of the plot still standing</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-4">
        <div class="cahu-card h-100 mb-3">
            <div class="cahu-card__head">Inspection details</div>
            <div class="cahu-card__body">
                <dl class="row mb-0">
                    <?php
                    $details = [
                        'Garden'          => $inspection['garden_name'],
                        'Farmer'          => $inspection['full_name'],
                        'Crop'            => $inspection['crop_name'],
                        'Variety'         => $inspection['variety_name'],
                        'Planting'        => $inspection['planting_code'],
                        'Season'          => $inspection['season_code'],
                        'Soil condition'  => $inspection['soil_condition'],
                        'Weather'         => $inspection['weather_condition'],
                        'Pests'           => $inspection['pest_status'],
                        'Disease'         => $inspection['disease_status'],
                        'Weeds'           => $inspection['weed_status'],
                        'Officer'         => $inspection['officer_name'],
                        'Recorded by'     => $inspection['recorded_by_name'],
                        'GPS'             => ($inspection['latitude'] !== null && $inspection['longitude'] !== null)
                            ? round((float) $inspection['latitude'], 5) . ', ' . round((float) $inspection['longitude'], 5)
                            : null,
                        'Area inspected'  => $inspection['garden_acreage'] !== null
                            ? format_number($inspection['garden_acreage']) . ' acres'
                            : null,
                    ];
                    ?>
                    <?php foreach ($details as $label => $value): ?>
                        <?php
                        if ($value === null || trim((string) $value) === '') {
                            continue;
                        }

                        // Status-shaped values read better as a badge than as
                        // a bare lowercase word in a definition list.
                        $isStatus = in_array($label, ['Soil condition', 'Weather', 'Pests', 'Disease', 'Weeds'], true);
                        ?>
                        <dt class="col-5 col-sm-4 text-muted fw-normal"><?= e($label) ?></dt>
                        <dd class="col-7 col-sm-8">
                            <?php if ($isStatus): ?>
                                <?= status_badge((string) $value) ?>
                            <?php else: ?>
                                <?= e(ucwords(str_replace('_', ' ', (string) $value))) ?>
                            <?php endif; ?>
                        </dd>
                    <?php endforeach; ?>
                </dl>
            </div>
        </div>

        <?php if (!empty($inspection['remarks'])): ?>
            <div class="cahu-card">
                <div class="cahu-card__head">Remarks</div>
                <div class="cahu-card__body">
                    <p class="mb-0"><?= nl2br(e($inspection['remarks'])) ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-12 col-xl-8">
        <?php if (!empty($inspection['recommendations'])): ?>
            <div class="cahu-card mb-3">
                <div class="cahu-card__head">Recommendations</div>
                <div class="cahu-card__body">
                    <p class="mb-0"><?= nl2br(e($inspection['recommendations'])) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <div class="cahu-card mb-3">
            <div class="cahu-card__head">Findings</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Finding</th>
                            <th>Type</th>
                            <th>Severity</th>
                            <th class="text-end">Area %</th>
                            <th>Notes</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($findings === []): ?>
                            <?= $view->partial('partials/empty', [
                                'columns' => 5,
                                'title'   => 'No specific findings',
                                'message' => 'Nothing distinct was recorded beyond the overall condition scores above.',
                            ]) ?>
                        <?php else: ?>
                            <?php foreach ($findings as $finding): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($finding['name']) ?></td>
                                    <td><?= e(ucfirst((string) $finding['finding_type'])) ?></td>
                                    <td><?= status_badge((string) $finding['severity']) ?></td>
                                    <td class="text-end"><?= e($finding['affected_area'] !== null ? format_number($finding['affected_area'], 2) : '—') ?></td>
                                    <td class="small"><?= e($finding['notes'] ?: '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($photos !== []): ?>
            <div class="cahu-card mb-3">
                <div class="cahu-card__head">Photo evidence</div>
                <div class="cahu-card__body">
                    <div class="row g-3">
                        <?php foreach ($photos as $photo): ?>
                            <div class="col-6 col-md-4">
                                <?php /* The uploader stores a web-servable path, not a filesystem one. */ ?>
                                <a href="<?= e((string) $photo['path']) ?>" target="_blank" rel="noopener">
                                    <img class="cahu-evidence" src="<?= e((string) $photo['path']) ?>"
                                         alt="<?= e($photo['caption'] ?: 'Inspection evidence') ?>">
                                </a>
                                <?php if ($photo['is_geo_tagged']): ?>
                                    <div class="text-muted small mt-1">Geotagged</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($forecastBasis !== null): ?>
            <div class="cahu-card mb-3">
                <div class="cahu-card__head">Production forecast this inspection feeds</div>
                <div class="cahu-card__body">
                    <dl class="row mb-0">
                        <dt class="col-5 col-sm-4 text-muted fw-normal">Forecast</dt>
                        <dd class="col-7 col-sm-8">
                            <span class="cahu-table__code"><?= e($forecastBasis['forecast_code']) ?></span>
                        </dd>
                        <dt class="col-5 col-sm-4 text-muted fw-normal">Expected production</dt>
                        <dd class="col-7 col-sm-8">
                            <?= e(format_number($forecastBasis['expected_production'])) ?>
                            <?= e($forecastBasis['unit']) ?>
                        </dd>
                        <dt class="col-5 col-sm-4 text-muted fw-normal">Revised production</dt>
                        <dd class="col-7 col-sm-8">
                            <?= $forecastBasis['revised_production'] !== null
                                ? e(format_number($forecastBasis['revised_production'])) . ' ' . e($forecastBasis['unit'])
                                : '<span class="text-muted">Not revised from this inspection</span>' ?>
                        </dd>
                    </dl>
                </div>
            </div>
        <?php endif; ?>

        <div class="cahu-card">
            <div class="cahu-card__head">Earlier inspections of this plot</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Condition</th>
                            <th>Pests</th>
                            <th>Disease</th>
                            <th>Weeds</th>
                            <th class="text-end"></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($history === []): ?>
                            <?= $view->partial('partials/empty', [
                                'columns' => 6,
                                'title'   => 'This is the first inspection',
                                'message' => 'There is nothing earlier to compare it against.',
                            ]) ?>
                        <?php else: ?>
                            <?php foreach ($history as $past): ?>
                                <tr>
                                    <td><?= e(format_date($past['inspection_date'])) ?></td>
                                    <td><?= status_badge((string) ($past['crop_condition'] ?? 'not assessed')) ?></td>
                                    <td><?= status_badge((string) ($past['pest_status'] ?? 'none')) ?></td>
                                    <td><?= status_badge((string) ($past['disease_status'] ?? 'none')) ?></td>
                                    <td><?= status_badge((string) ($past['weed_status'] ?? 'none')) ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-link text-decoration-none"
                                           href="<?= e(url('inspections/' . $past['inspection_code'])) ?>">Open</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>