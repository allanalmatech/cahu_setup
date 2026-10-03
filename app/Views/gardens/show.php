<?php
/**
 * Garden record: the plot plus everything recorded against it.
 *
 * @var Core\View $view
 * @var array<string,mixed> $garden
 * @var array<int,array<string,mixed>> $plantings
 * @var array<int,array<string,mixed>> $activities
 * @var array<int,array<string,mixed>> $inspections
 * @var array<int,array<string,mixed>> $harvests
 * @var array<int,array<string,mixed>> $problems
 * @var array<int,array<string,mixed>> $history
 */
$totalHarvest = array_sum(array_map('floatval', array_column($harvests, 'quantity_harvested')));
$unit         = $harvests[0]['unit'] ?? null;
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($garden['name']) ?></h1>
        <p class="cahu-pagehead__sub">
            <span class="cahu-table__code"><?= e($garden['garden_code']) ?></span>
            &middot; <?= status_badge((string) $garden['status']) ?>
            &middot; <?= e(format_number($garden['acreage'])) ?> acres
            &middot; <?= e($garden['full_name']) ?>
            (<a href="<?= e(url('farmers/' . $garden['farmer_code'])) ?>"><?= e($garden['farmer_code']) ?></a>)
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('map?garden_id=' . $garden['id'])) ?>">View on map</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('gardens/' . $garden['garden_code'] . '/history')) ?>">History</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('gardens/' . $garden['garden_code'] . '/edit')) ?>">Edit</a>
        <a class="btn cahu-btn-primary" href="<?= e(url('plantings/create?garden_id=' . $garden['id'])) ?>">Record planting</a>
    </div>
</div>

<div class="cahu-stats mb-3">
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">Acreage</div>
        <div class="cahu-stat__value"><?= e(format_number($garden['acreage'])) ?></div>
        <div class="cahu-stat__meta">
            <?= e($garden['land_ownership'] ? ucwords(str_replace('_', ' ', (string) $garden['land_ownership'])) : 'Ownership not set') ?>
        </div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Plantings</div>
        <div class="cahu-stat__value"><?= e(count($plantings)) ?></div>
        <div class="cahu-stat__meta"><?= e(count($inspections)) ?> inspection(s)</div>
    </div>
    <div class="cahu-stat cahu-stat--soil">
        <div class="cahu-stat__label">Harvest recorded</div>
        <div class="cahu-stat__value"><?= e(format_number($totalHarvest)) ?></div>
        <div class="cahu-stat__meta"><?= e($unit ?? 'no unit') ?></div>
    </div>
    <div class="cahu-stat cahu-stat--amber">
        <div class="cahu-stat__label">Open problems</div>
        <div class="cahu-stat__value">
            <?= e(count(array_filter($problems, static fn (array $p): bool => $p['status'] !== 'resolved' && $p['status'] !== 'closed'))) ?>
        </div>
        <div class="cahu-stat__meta"><?= e(count($problems)) ?> reported in total</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-4">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Plot details</div>
            <div class="cahu-card__body">
                <dl class="row mb-0">
                    <?php
                    $details = [
                        'Owner'         => $garden['full_name'],
                        'Region'        => $garden['region_name'],
                        'District'      => $garden['district_name'],
                        'Sub-county'    => $garden['sub_county_name'],
                        'Parish'        => $garden['parish_name'],
                        'Village'       => $garden['registered_village_name'] ?: $garden['village_name'],
                        'Site notes'    => $garden['village_name'],
                        'Soil type'     => $garden['soil_type'] ? ucfirst((string) $garden['soil_type']) : null,
                        'Irrigation'    => $garden['irrigation_status'] ? ucwords(str_replace('_', ' ', (string) $garden['irrigation_status'])) : null,
                        'Water source'  => $garden['water_source'] ? ucwords(str_replace('_', ' ', (string) $garden['water_source'])) : null,
                        'Terrain'       => $garden['terrain'] ? ucwords(str_replace('_', ' ', (string) $garden['terrain'])) : null,
                        'Altitude'      => $garden['altitude'] !== null ? format_number($garden['altitude'], 1) . ' m' : null,
                        'GPS'           => ($garden['latitude'] !== null && $garden['longitude'] !== null)
                            ? round((float) $garden['latitude'], 5) . ', ' . round((float) $garden['longitude'], 5)
                            : null,
                    ];
                    ?>
                    <?php foreach ($details as $label => $value): ?>
                        <?php if ($value === null || trim((string) $value) === '') { continue; } ?>
                        <dt class="col-5 col-sm-4 text-muted fw-normal"><?= e($label) ?></dt>
                        <dd class="col-7 col-sm-8"><?= e($value) ?></dd>
                    <?php endforeach; ?>
                </dl>
                <?php if (!empty($garden['notes'])): ?>
                    <hr>
                    <div class="text-muted small">Notes</div>
                    <p class="mb-0"><?= nl2br(e($garden['notes'])) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-8">
        <div class="cahu-card mb-3">
            <div class="cahu-card__head">Plantings</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr><th>Code</th><th>Crop</th><th>Season</th><th>Date</th><th class="text-end">Acres</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                        <?php if ($plantings === []): ?>
                            <?= $view->partial('partials/empty', [
                                'columns'     => 6,
                                'title'       => 'Nothing planted yet',
                                'message'     => 'Recording a planting generates the activity schedule for this plot.',
                                'actionLabel' => 'Record planting',
                                'actionUrl'   => url('plantings/create?garden_id=' . $garden['id']),
                            ]) ?>
                        <?php else: ?>
                            <?php foreach ($plantings as $planting): ?>
                                <tr>
                                    <td class="cahu-table__code">
                                        <a href="<?= e(url('plantings/' . $planting['planting_code'])) ?>">
                                            <?= e($planting['planting_code']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?= e($planting['crop_name']) ?>
                                        <?php if (!empty($planting['variety_name'])): ?>
                                            <div class="text-muted small"><?= e($planting['variety_name']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($planting['season_code'] ?? '—') ?></td>
                                    <td><?= e(format_date($planting['planting_date'])) ?></td>
                                    <td class="text-end"><?= e(format_number($planting['acreage_planted'])) ?></td>
                                    <td><?= status_badge((string) $planting['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="cahu-card">
            <div class="cahu-card__head">Recent inspections</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr><th>Date</th><th>Crop condition</th><th>Pests</th><th>Disease</th><th>Officer</th><th>Recommendation</th></tr>
                        </thead>
                        <tbody>
                        <?php if ($inspections === []): ?>
                            <tr><td colspan="6" class="text-muted p-3">No inspections recorded for this plot.</td></tr>
                        <?php else: ?>
                            <?php foreach ($inspections as $inspection): ?>
                                <tr>
                                    <td><?= e(format_date($inspection['inspection_date'])) ?></td>
                                    <td><?= status_badge((string) $inspection['crop_condition']) ?></td>
                                    <td><?= status_badge((string) $inspection['pest_status']) ?></td>
                                    <td><?= status_badge((string) $inspection['disease_status']) ?></td>
                                    <td><?= e($inspection['officer_name'] ?: '—') ?></td>
                                    <td class="small"><?= e($inspection['recommendations'] ?: '—') ?></td>
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

<div class="cahu-card mt-3">
    <div class="cahu-card__head">Recent activity records</div>
    <div class="cahu-card__body cahu-card__body--flush">
        <div class="table-responsive">
            <table class="table cahu-table align-middle mb-0">
                <thead>
                <tr><th>Activity</th><th>Planned</th><th>Due</th><th>Status</th><th>Completed</th></tr>
                </thead>
                <tbody>
                <?php if ($activities === []): ?>
                    <tr><td colspan="5" class="text-muted p-3">No activities yet. They are generated when a planting is recorded.</td></tr>
                <?php else: ?>
                    <?php foreach ($activities as $activity): ?>
                        <tr>
                            <td><?= e($activity['activity_name'] ?? ucwords(str_replace('_', ' ', (string) $activity['activity_type']))) ?></td>
                            <td><?= e(format_date($activity['planned_date'])) ?></td>
                            <td><?= e(format_date($activity['due_date'])) ?></td>
                            <td><?= status_badge((string) $activity['status']) ?></td>
                            <td><?= e($activity['completed_at'] !== null ? format_datetime($activity['completed_at']) : '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
