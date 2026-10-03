<?php
/**
 * The farmer's digital record: performance, gardens, crops and activity state.
 *
 * @var Core\View $view
 * @var array<string,mixed> $farmer
 * @var array{score:float,grade:string,metrics:array<string,float>,weights:array<string,float>,activities:array,gardens:array} $performance
 * @var array<int,array<string,mixed>> $gardens
 * @var array<int,array<string,mixed>> $crops
 * @var array<string,int> $activitySummary
 * @var float $expected
 * @var float $actual
 * @var array<int,array<string,mixed>> $tabs
 */
$gradeClass = match (true) {
    $performance['score'] >= 70 => 'cahu-stat--green',
    $performance['score'] >= 50 => 'cahu-stat--amber',
    default                      => 'cahu-stat--red',
};

$metricLabels = [
    'activity_completion' => 'Activity completion',
    'inspection_uptake'   => 'Inspection uptake',
    'input_usage'         => 'Input usage recorded',
    'problem_reporting'   => 'Problem reporting',
    'harvest_submission'  => 'Harvest submission',
    'field_visit'         => 'Field officer visits',
];
?>
<div class="cahu-pagehead">
    <div class="d-flex align-items-center gap-3">
        <?php if (!empty($farmer['profile_photo'])): ?>
            <img class="cahu-avatar" style="width:56px;height:56px" src="<?= e($farmer['profile_photo']) ?>" alt="">
        <?php else: ?>
            <span class="cahu-avatar" style="width:56px;height:56px;font-size:1.5rem">
                <?= e(strtoupper(substr((string) $farmer['full_name'], 0, 1))) ?>
            </span>
        <?php endif; ?>
        <div>
            <h1 class="mb-0"><?= e($farmer['full_name']) ?></h1>
            <p class="cahu-pagehead__sub mb-0">
                <span class="cahu-table__code"><?= e($farmer['farmer_code']) ?></span>
                &middot; <?= status_badge((string) $farmer['status']) ?>
                &middot; <?= e(ucwords(str_replace('_', ' ', (string) $farmer['farmer_type']))) ?>
            </p>
        </div>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('farmers/' . $farmer['farmer_code'] . '/print')) ?>" target="_blank" rel="noopener">Print record</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('farmers/' . $farmer['farmer_code'] . '/edit')) ?>">Edit</a>
        <a class="btn cahu-btn-primary" href="<?= e(url('gardens/create?farmer_id=' . $farmer['id'])) ?>">Add garden</a>
    </div>
</div>

<?= $view->partial('partials/tabs', ['tabs' => $tabs, 'currentPath' => url('farmers/' . $farmer['farmer_code'])]) ?>

<div class="cahu-stats mb-3">
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">Participation score</div>
        <div class="cahu-stat__value"><?= e(format_number($performance['score'], 1)) ?>%</div>
        <div class="cahu-stat__meta"><?= e($performance['grade']) ?></div>
    </div>
    <div class="cahu-stat">
        <div class="cahu-stat__label">Gardens</div>
        <div class="cahu-stat__value"><?= e(count($gardens)) ?></div>
        <div class="cahu-stat__meta"><?= e(format_number(array_sum(array_map('floatval', array_column($gardens, 'acreage'))))) ?> acres</div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Crops this season</div>
        <div class="cahu-stat__value"><?= e(count($crops)) ?></div>
        <div class="cahu-stat__meta"><?= e($currentSeason['season_code'] ?? 'No open season') ?></div>
    </div>
    <div class="cahu-stat cahu-stat--amber">
        <div class="cahu-stat__label">Activities completed</div>
        <div class="cahu-stat__value"><?= e($activitySummary['completed']) ?></div>
        <div class="cahu-stat__meta">
            <?= (int) $activitySummary['overdue'] ?> overdue &middot; <?= (int) $activitySummary['due'] ?> due
        </div>
    </div>
    <div class="cahu-stat cahu-stat--soil">
        <div class="cahu-stat__label">Harvest vs forecast</div>
        <div class="cahu-stat__value"><?= e(format_quantity($actual)) ?></div>
        <div class="cahu-stat__meta">of <?= e(format_quantity($expected)) ?> expected</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-4">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Score breakdown</div>
            <div class="cahu-card__body">
                <?php foreach ($performance['metrics'] as $key => $metric): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small">
                            <span><?= e($metricLabels[$key] ?? $key) ?></span>
                            <span class="text-muted">
                                <?= e(format_number($metric, 0)) ?>%
                                <span class="ms-1">(<?= e(format_number((float) $performance['weights'][$key], 0)) ?> pts)</span>
                            </span>
                        </div>
                        <div class="cahu-progress mt-1">
                            <span style="width:<?= e((string) max(0, min(100, $metric))) ?>%"></span>
                        </div>
                    </div>
                <?php endforeach; ?>
                <p class="text-muted small mb-0">
                    The score is calculated from recorded participation, not from a single inspection, and is
                    used to target extension support. Weights are set in system settings.
                </p>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-8">
        <div class="cahu-card mb-3">
            <div class="cahu-card__head">Details</div>
            <div class="cahu-card__body">
                <div class="row g-3">
                    <?php
                    $details = [
                        'Phone'            => $farmer['phone'],
                        'Alternative phone' => $farmer['alt_phone'],
                        'Email'            => $farmer['email'],
                        'National ID'      => $farmer['national_id'],
                        'Gender'           => $farmer['gender'] ? ucfirst((string) $farmer['gender']) : null,
                        'Date of birth'    => format_date($farmer['date_of_birth']),
                        'Region'           => $farmer['region_name'],
                        'District'         => $farmer['district_name'],
                        'Sub-county'       => $farmer['sub_county_name'],
                        'Parish'           => $farmer['parish_name'],
                        'Village'          => $farmer['registered_village_name'] ?: $farmer['village_name'],
                        'Address'          => $farmer['address'],
                        'GPS'              => ($farmer['latitude'] !== null && $farmer['longitude'] !== null)
                            ? round((float) $farmer['latitude'], 5) . ', ' . round((float) $farmer['longitude'], 5)
                            : null,
                        'Group'            => $farmer['group_name'],
                        'Field officer'    => $farmer['officer_name'],
                        'Programme'        => $farmer['project_code'],
                        'Education'        => $farmer['education_level'] ? ucfirst((string) $farmer['education_level']) : null,
                        'Household size'   => $farmer['household_size'],
                        'Bank'             => $farmer['bank_name'] . ' ' . $farmer['bank_account'],
                        'Mobile money'     => $farmer['mobile_money'],
                        'Registered on'    => format_date($farmer['registration_date']),
                    ];
                    ?>
                    <?php foreach ($details as $label => $value): ?>
                        <?php if ($value === null || $value === '' || trim((string) $value) === ''): continue; endif; ?>
                        <div class="col-6 col-md-4">
                            <div class="text-muted small"><?= e($label) ?></div>
                            <div class="fw-semibold"><?= e($value) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($farmer['notes'])): ?>
                    <hr>
                    <div class="text-muted small">Notes</div>
                    <p class="mb-0"><?= nl2br(e($farmer['notes'])) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="cahu-card mt-3">
    <div class="cahu-card__head">Gardens</div>
    <div class="cahu-card__body cahu-card__body--flush">
        <div class="table-responsive">
            <table class="table cahu-table align-middle mb-0">
                <thead>
                <tr>
                    <th>Garden</th>
                    <th>District</th>
                    <th class="text-end">Acres</th>
                    <th>Ownership</th>
                    <th>Irrigation</th>
                    <th>Soil</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($gardens === []): ?>
                    <?= $view->partial('partials/empty', [
                        'columns'     => 8,
                        'title'       => 'No gardens yet',
                        'message'     => 'Every farm record needs at least one garden before crops can be recorded.',
                        'actionLabel' => 'Add garden',
                        'actionUrl'   => url('gardens/create?farmer_id=' . $farmer['id']),
                    ]) ?>
                <?php else: ?>
                    <?php foreach ($gardens as $garden): ?>
                        <tr>
                            <td>
                                <a class="cahu-table__code" href="<?= e(url('gardens/' . $garden['garden_code'])) ?>">
                                    <?= e($garden['garden_code']) ?>
                                </a>
                                <div class="text-muted small"><?= e($garden['name']) ?></div>
                            </td>
                            <td><?= e($garden['district_name'] ?: '—') ?></td>
                            <td class="text-end"><?= e(format_number($garden['acreage'])) ?></td>
                            <td><?= e($garden['land_ownership'] ? ucwords(str_replace('_', ' ', (string) $garden['land_ownership'])) : '—') ?></td>
                            <td><?= e($garden['irrigation_status'] ? ucwords(str_replace('_', ' ', (string) $garden['irrigation_status'])) : '—') ?></td>
                            <td><?= e($garden['soil_type'] ? ucwords(str_replace('_', ' ', (string) $garden['soil_type'])) : '—') ?></td>
                            <td><?= status_badge((string) $garden['status']) ?></td>
                            <td class="text-end cahu-table__actions">
                                <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('gardens/' . $garden['garden_code'])) ?>">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="cahu-card mt-3">
    <div class="cahu-card__head">Crops and plantings</div>
    <div class="cahu-card__body cahu-card__body--flush">
        <div class="table-responsive">
            <table class="table cahu-table align-middle mb-0">
                <thead>
                <tr>
                    <th>Planting</th>
                    <th>Crop</th>
                    <th>Garden</th>
                    <th>Season</th>
                    <th>Growth stage</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($crops === []): ?>
                    <?= $view->partial('partials/empty', [
                        'columns'     => 7,
                        'title'       => 'No crops recorded',
                        'message'     => 'Record a planting against one of this farmer\'s gardens to start the season.',
                        'actionLabel' => 'Record planting',
                        'actionUrl'   => url('plantings/create?farmer_id=' . $farmer['id']),
                    ]) ?>
                <?php else: ?>
                    <?php foreach ($crops as $crop): ?>
                        <tr>
                            <td class="cahu-table__code">
                                <a href="<?= e(url('plantings/' . $crop['planting_code'])) ?>"><?= e($crop['planting_code']) ?></a>
                                <div class="text-muted small"><?= e(format_date($crop['planting_date'])) ?></div>
                            </td>
                            <td>
                                <?= e($crop['crop_name']) ?>
                                <?php if (!empty($crop['variety_name'])): ?>
                                    <div class="text-muted small"><?= e($crop['variety_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e($crop['garden_name']) ?></td>
                            <td><?= e($crop['season_code']) ?></td>
                            <td><?= e($crop['growth_stage'] ? ucwords(str_replace('_', ' ', (string) $crop['growth_stage'])) : '—') ?></td>
                            <td><?= status_badge((string) $crop['status']) ?></td>
                            <td class="text-end cahu-table__actions">
                                <a class="btn btn-sm cahu-btn-outline" href="<?= e(url('plantings/' . $crop['planting_code'])) ?>">Open</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
