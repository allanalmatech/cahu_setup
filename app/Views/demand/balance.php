<?php
/**
 * Supply versus demand: per crop, what is expected, what is in, what is
 * available unsold, what buyers have committed to, and the gap between the
 * last two.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,float|int> $totals
 * @var array<int,array<string,mixed>> $crops
 * @var int $cropId
 */
$shortfallCrops = count(array_filter($rows, static fn (array $row): bool => $row['has_activity'] && !$row['is_surplus']));
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="cahu-pagehead__sub">
            Expected and harvested production against the quantity already committed to buyers,
            crop by crop. A positive balance means supply covers committed demand.
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('demand')) ?>">Demand register</a>
        <a class="btn cahu-btn-primary" href="<?= e(url('demand/create')) ?>">Record demand</a>
    </div>
</div>

<div class="cahu-stats mb-3">
    <div class="cahu-stat">
        <div class="cahu-stat__label">Crops with activity</div>
        <div class="cahu-stat__value"><?= count(array_filter($rows, static fn (array $row): bool => $row['has_activity'])) ?></div>
        <div class="cahu-stat__meta">of <?= count($rows) ?> active crop(s)</div>
    </div>
    <div class="cahu-stat cahu-stat--amber">
        <div class="cahu-stat__label">Crops short of demand</div>
        <div class="cahu-stat__value"><?= (int) $shortfallCrops ?></div>
        <div class="cahu-stat__meta">Availability below committed volume</div>
    </div>
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">Total surplus</div>
        <div class="cahu-stat__value"><?= e(format_number($totals['surplus'])) ?></div>
        <div class="cahu-stat__meta">Across the crop units shown</div>
    </div>
    <div class="cahu-stat cahu-stat--red">
        <div class="cahu-stat__label">Total shortfall</div>
        <div class="cahu-stat__value"><?= e(format_number($totals['shortfall'])) ?></div>
        <div class="cahu-stat__meta">Needs sourcing or renegotiation</div>
    </div>
</div>

<form class="cahu-filters" method="get" action="<?= e(url('demand/balance')) ?>">
    <label class="visually-hidden" for="balance_crop">Crop</label>
    <select class="form-select" name="crop_id" id="balance_crop" data-autosubmit>
        <option value="">All active crops</option>
        <?php foreach ($crops as $crop): ?>
            <option value="<?= e($crop['id']) ?>" <?= (int) $cropId === (int) $crop['id'] ? 'selected' : '' ?>>
                <?= e($crop['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php if ($cropId > 0): ?>
        <a class="btn btn-link btn-sm text-decoration-none" href="<?= e(url('demand/balance')) ?>">Clear</a>
    <?php endif; ?>
</form>

<div class="cahu-card mt-3 mb-3">
    <div class="cahu-card__head">Expected, harvested, available and committed by crop</div>
    <div class="cahu-card__body">
        <div style="height:360px">
            <canvas id="supplyDemandChart"></canvas>
        </div>
    </div>
</div>

<div class="cahu-card">
    <div class="cahu-card__head">Balance by crop</div>
    <div class="cahu-card__body cahu-card__body--flush">
        <div class="table-responsive">
            <table class="table cahu-table align-middle mb-0">
                <thead>
                <tr>
                    <th>Crop</th>
                    <th class="text-end">Expected</th>
                    <th class="text-end">Harvested</th>
                    <th class="text-end">Available unsold</th>
                    <th class="text-end">Committed</th>
                    <th class="text-end">Sold</th>
                    <th class="text-end">Balance</th>
                    <th>Verdict</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <?= $view->partial('partials/empty', [
                        'columns'   => 8,
                        'title'     => 'No active crops',
                        'message'   => 'Supply and demand can only be compared once there are active crops in the catalogue.',
                        'actionUrl' => '',
                    ]) ?>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= e($row['crop']) ?></div>
                                <div class="text-muted small"><?= e($row['crop_code']) ?></div>
                            </td>
                            <td class="text-end"><?= e(format_number($row['expected'])) ?></td>
                            <td class="text-end"><?= e(format_number($row['harvested'])) ?></td>
                            <td class="text-end"><?= e(format_number($row['available'])) ?></td>
                            <td class="text-end"><?= e(format_number($row['committed'])) ?></td>
                            <td class="text-end"><?= e(format_number($row['sold'])) ?></td>
                            <td class="text-end">
                                <?php if (!$row['has_activity']): ?>
                                    <span class="text-muted">—</span>
                                <?php else: ?>
                                    <span class="badge text-bg-<?= $row['is_surplus'] ? 'success' : 'danger' ?>">
                                        <?= ($row['is_surplus'] ? '+' : '-') . e(format_number(abs((float) $row['surplus']))) ?>
                                    </span>
                                    <div class="text-muted small"><?= e($row['unit']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?php if ($row['has_activity']): ?>
                                    <?= e($row['verdict']) ?>
                                <?php else: ?>
                                    <span class="text-muted">No production or demand recorded.</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="cahu-card__foot">
        <span class="text-muted small">
            Expected production is the forecast for open seasons, using the revised figure where one has
            been recorded. Available unsold counts only batches still open or aggregated, so produce
            already committed to a sale is not counted twice.
        </span>
    </div>
</div>