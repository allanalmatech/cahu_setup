<?php
/**
 * Planting record: the crop cycle and everything recorded against it.
 *
 * @var Core\View $view
 * @var array<string,mixed> $planting
 * @var array<int,array<string,mixed>> $activities
 * @var array<int,array<string,mixed>> $harvests
 * @var array<int,array<string,mixed>> $inputs
 * @var array<int,array<string,mixed>> $inspections
 * @var array<string,mixed>|null $forecast
 * @var float $harvested
 */
$totalTasks    = count($activities);
$completedTasks = count(array_filter($activities, static fn (array $a): bool => $a['status'] === 'completed'));
$overdueTasks  = count(array_filter($activities, static function (array $a): bool {
    return $a['status'] !== 'completed' && $a['due_date'] < date('Y-m-d');
}));
$unit          = $harvests[0]['unit'] ?? $planting['crop_unit'] ?? null;
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($planting['crop_name']) ?></h1>
        <p class="cahu-pagehead__sub">
            <span class="cahu-table__code"><?= e($planting['planting_code']) ?></span>
            <?php if (!empty($planting['variety_name'])): ?>
                &middot; <?= e($planting['variety_name']) ?>
            <?php endif; ?>
            &middot; <?= status_badge((string) $planting['status']) ?>
            &middot; <?= e($planting['full_name']) ?>
            &middot; <?= e($planting['garden_name'] ?: 'no garden') ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('plantings/' . $planting['planting_code'] . '/schedule')) ?>">Schedule</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('plantings/' . $planting['planting_code'] . '/edit')) ?>">Edit</a>
        <a class="btn cahu-btn-primary" href="<?= e(url('harvest/create?planting_code=' . $planting['planting_code'])) ?>">Record harvest</a>
    </div>
</div>

<div class="cahu-stats mb-3">
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">Area planted</div>
        <div class="cahu-stat__value"><?= e(format_number($planting['acreage_planted'])) ?></div>
        <div class="cahu-stat__meta">
            of <?= e(format_number($planting['garden_acreage'])) ?> acres in the plot
        </div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Tasks</div>
        <div class="cahu-stat__value"><?= e($completedTasks) ?>/<?= e($totalTasks) ?></div>
        <div class="cahu-stat__meta"><?= e($overdueTasks) ?> overdue</div>
    </div>
    <div class="cahu-stat cahu-stat--soil">
        <div class="cahu-stat__label">Harvested so far</div>
        <div class="cahu-stat__value"><?= e(format_number($harvested)) ?></div>
        <div class="cahu-stat__meta"><?= e($unit ?? 'unit not set') ?></div>
    </div>
    <div class="cahu-stat cahu-stat--amber">
        <div class="cahu-stat__label">Expected harvest</div>
        <div class="cahu-stat__value" style="font-size:1.1rem">
            <?= e($planting['expected_harvest_date'] !== null ? format_date($planting['expected_harvest_date']) : 'Not set') ?>
        </div>
        <div class="cahu-stat__meta"><?= e($planting['season_name'] ?: $planting['season_code'] ?: '') ?></div>
    </div>
</div>

<?php if ($forecast !== null): ?>
    <?php
    $expected = (float) $forecast['expected_production'];
    $revised  = $forecast['revised_production'] !== null ? (float) $forecast['revised_production'] : null;
    $target   = $revised ?? $expected;
    $percent  = $target > 0 ? (int) min(100, round($harvested / $target * 100)) : 0;
    ?>
    <div class="cahu-card mb-3">
        <div class="cahu-card__head">
            Yield forecast
            <?php if (($forecast['generated_by'] ?? 'system') === 'manual'): ?>
                <span class="cahu-chip cahu-chip--warn ms-2">manually revised</span>
            <?php endif; ?>
        </div>
        <div class="cahu-card__body">
            <div class="row g-3 align-items-center">
                <div class="col-12 col-md-4">
                    <div class="text-muted small">Expected production</div>
                    <div class="fs-4 fw-semibold"><?= e(format_number($target)) ?> <?= e($forecast['unit'] ?? $unit ?? '') ?></div>
                    <div class="text-muted small">
                        <?= e(format_number($forecast['expected_yield_per_acre'])) ?> per acre
                        <?php if ($revised !== null): ?>
                            &middot; revised from <?= e(format_number($expected)) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-12 col-md-5">
                    <div class="d-flex justify-content-between small">
                        <span>Actual against forecast</span>
                        <span class="text-muted"><?= e($percent) ?>%</span>
                    </div>
                    <div class="cahu-progress" role="progressbar"
                         aria-valuenow="<?= e($percent) ?>" aria-valuemin="0" aria-valuemax="100"
                         aria-label="Actual against forecast">
                        <i style="width: <?= e($percent) ?>%"></i>
                    </div>
                    <div class="text-muted small mt-1">
                        Forecast dated <?= e(format_date($forecast['forecast_date'])) ?>
                        <?php if (!empty($forecast['revision_reason'])): ?>
                            &middot; <?= e($forecast['revision_reason']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-12 col-md-3 text-md-end">
                    <?php if ($harvested > 0 && $target > 0): ?>
                        <?php $variance = $harvested - $target; ?>
                        <div class="small text-muted">Variance</div>
                        <div class="fs-5 fw-semibold <?= $variance >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= $variance >= 0 ? '+' : '' ?><?= e(format_number($variance)) ?>
                        </div>
                        <div class="text-muted small">
                            <?= $variance >= 0 ? 'above' : 'below' ?> forecast
                        </div>
                    <?php else: ?>
                        <span class="text-muted small">No harvest recorded yet</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="cahu-card mb-3">
    <div class="cahu-card__head">
        Activity schedule
        <a class="btn btn-sm cahu-btn-outline ms-auto" href="<?= e(url('plantings/' . $planting['planting_code'] . '/schedule')) ?>">
            Open schedule
        </a>
    </div>
    <div class="cahu-card__body cahu-card__body--flush">
        <div class="table-responsive">
            <table class="table cahu-table align-middle mb-0">
                <thead>
                <tr><th>Activity</th><th>Planned</th><th>Due</th><th>Assigned to</th><th>Priority</th><th>Status</th><th>Completed</th><th></th></tr>
                </thead>
                <tbody>
                <?php if ($activities === []): ?>
                    <tr><td colspan="8" class="text-muted p-3">No activity template is configured for this crop.</td></tr>
                <?php else: ?>
                    <?php foreach ($activities as $activity): ?>
                        <?php
                        $late = $activity['status'] !== 'completed' && $activity['due_date'] < date('Y-m-d');
                        ?>
                        <tr<?= $late ? ' class="cahu-row--late"' : '' ?>>
                            <td>
                                <span class="fw-semibold"><?= e($activity['activity_name'] ?? \Services\Lookups::label((string) $activity['activity_type'])) ?></span>
                                <?php if (!empty($activity['description'])): ?>
                                    <div class="text-muted small"><?= e($activity['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e(format_date($activity['planned_date'])) ?></td>
                            <td>
                                <?= e(format_date($activity['due_date'])) ?>
                                <?php if ($late): ?><div class="text-danger small">overdue</div><?php endif; ?>
                            </td>
                            <td class="small">
                                <?= e($activity['assignee_type'] === 'farmer'
                                    ? $planting['full_name'] . ' (farmer)'
                                    : ($activity['officer_name'] ?: ucfirst((string) $activity['assignee_type']))) ?>
                            </td>
                            <td><?= priority_badge((string) $activity['priority']) ?></td>
                            <td><?= status_badge((string) $activity['status']) ?></td>
                            <td class="small"><?= e($activity['completed_at'] !== null ? format_datetime($activity['completed_at']) : '—') ?></td>
                            <td class="text-end">
                                <?php if ($activity['status'] !== 'completed'): ?>
                                    <?php /* /complete is a POST endpoint; the edit page carries the completion form. */ ?>
                                    <a class="btn btn-sm cahu-btn-outline"
                                       href="<?= e(url('activities/' . $activity['activity_code'] . '/edit')) ?>">Record</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-6">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Harvest records</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead><tr><th>ID</th><th>Date</th><th class="text-end">Quantity</th><th>Quality</th><th>Batch</th></tr></thead>
                        <tbody>
                        <?php if ($harvests === []): ?>
                            <tr><td colspan="5" class="text-muted p-3">Nothing harvested yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($harvests as $harvest): ?>
                                <tr>
                                    <td class="cahu-table__code">
                                        <a href="<?= e(url('harvest/' . $harvest['harvest_code'])) ?>">
                                            <?= e($harvest['harvest_code']) ?>
                                        </a>
                                    </td>
                                    <td><?= e(format_date($harvest['harvest_date'])) ?></td>
                                    <td class="text-end">
                                        <?= e(format_number($harvest['quantity_harvested'])) ?>
                                        <span class="text-muted small"><?= e($harvest['unit']) ?></span>
                                    </td>
                                    <td><?= status_badge((string) ($harvest['quality'] ?: $harvest['grade'])) ?></td>
                                    <td class="text-end">
                                        <?php if (!empty($harvest['batch_id'])): ?>
                                            <a class="btn btn-sm btn-link text-decoration-none"
                                               href="<?= e(url('batches/' . $harvest['batch_code'])) ?>">Batch</a>
                                        <?php else: ?>
                                            <span class="text-muted small">unbatched</span>
                                        <?php endif; ?>
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

    <div class="col-12 col-xl-6">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Inspections</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead><tr><th>Date</th><th>Crop</th><th>Pests</th><th>Disease</th><th class="text-end">Yield/acre</th></tr></thead>
                        <tbody>
                        <?php if ($inspections === []): ?>
                            <tr><td colspan="5" class="text-muted p-3">No inspections recorded.</td></tr>
                        <?php else: ?>
                            <?php foreach ($inspections as $inspection): ?>
                                <tr>
                                    <td><?= e(format_date($inspection['inspection_date'])) ?></td>
                                    <td><?= status_badge((string) $inspection['crop_condition']) ?></td>
                                    <td><?= status_badge((string) $inspection['pest_status']) ?></td>
                                    <td><?= status_badge((string) $inspection['disease_status']) ?></td>
                                    <td class="text-end"><?= e($inspection['estimated_yield_per_acre'] !== null ? format_number($inspection['estimated_yield_per_acre']) : '—') ?></td>
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
    <div class="cahu-card__head">Inputs applied to this plot</div>
    <div class="cahu-card__body cahu-card__body--flush">
        <div class="table-responsive">
            <table class="table cahu-table align-middle mb-0">
                <thead><tr><th>Date</th><th>Input</th><th>Category</th><th>Purpose</th><th class="text-end">Quantity</th></tr></thead>
                <tbody>
                <?php if ($inputs === []): ?>
                    <tr><td colspan="5" class="text-muted p-3">No inputs have been applied to this plot.</td></tr>
                <?php else: ?>
                    <?php foreach ($inputs as $input): ?>
                        <tr>
                            <td><?= e(format_date($input['application_date'])) ?></td>
                            <td class="fw-semibold"><?= e($input['input_name']) ?></td>
                            <td><?= e($input['category_name']) ?></td>
                            <td class="small"><?= e($input['purpose'] ?: '—') ?></td>
                            <td class="text-end">
                                <?= e(format_number($input['quantity_used'])) ?>
                                <span class="text-muted small"><?= e($input['unit']) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<details class="cahu-card mt-3">
    <summary class="cahu-card__head">Planting details</summary>
    <div class="cahu-card__body">
        <dl class="row mb-0">
            <?php
            $details = [
                'Garden'            => $planting['garden_code'] ?: null,
                'Season'            => $planting['season_name'] ?: $planting['season_code'] ?: null,
                'Planted'           => format_date($planting['planting_date']),
                'Method'            => $planting['planting_method'] ? \Services\Lookups::label((string) $planting['planting_method']) : null,
                'Growth stage'      => $planting['growth_stage'] ? \Services\Lookups::label((string) $planting['growth_stage']) : null,
                'Seed used'         => $planting['seed_quantity'] !== null
                    ? format_number($planting['seed_quantity']) . ' ' . ($planting['seed_unit'] ?? '')
                    : null,
                'Expected yield'    => $planting['expected_yield'] !== null
                    ? format_number($planting['expected_yield']) . ' ' . ($planting['yield_unit'] ?? '')
                    : null,
                'Supervising officer' => $planting['officer_name'],
                'Farmer phone'      => $planting['phone'],
            ];
            ?>
            <?php foreach ($details as $label => $value): ?>
                <?php if ($value === null || trim((string) $value) === '') { continue; } ?>
                <dt class="col-5 col-sm-4 text-muted fw-normal"><?= e($label) ?></dt>
                <dd class="col-7 col-sm-8"><?= e($value) ?></dd>
            <?php endforeach; ?>
        </dl>
        <?php if (!empty($planting['notes'])): ?>
            <hr>
            <div class="text-muted small">Notes</div>
            <p class="mb-0"><?= nl2br(e($planting['notes'])) ?></p>
        <?php endif; ?>
    </div>
</details>

<!-- Termination has its own POST endpoint; without an explicit action this
     form would post back to the update route and change nothing. -->
<form method="post" action="<?= e(url('plantings/' . $planting['planting_code'] . '/delete')) ?>"
      class="mt-3" onsubmit="return confirm('Terminate this planting? The record is kept for history.');">
    <?= csrf_field() ?>
    <button class="btn btn-sm btn-outline-danger" type="submit">Terminate planting</button>
</form>