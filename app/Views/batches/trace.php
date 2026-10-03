<?php
/**
 * Printable provenance sheet for one batch.
 *
 * This is the page that answers "where did this come from" for a buyer,
 * auditor or exporter, so it states the lot facts, then every contributing
 * farmer with the plot coordinates that back them up.
 *
 * @var array<string,mixed> $batch
 * @var array<string,mixed> $chain
 */
$contributors = $chain['contributors'] ?? [];
$origin      = $chain['batch'] ?? [];
?>
<div class="cahu-printhead">
    <h1>Batch <?= e($batch['batch_code']) ?></h1>
    <p>
        <?= e($batch['crop_name'] ?? '') ?>
        <?= $batch['variety_name'] ? ' (' . e($batch['variety_name']) . ')' : '' ?>
        · <?= e($batch['season_name'] ?? '') ?>
        · batched <?= e(format_date($batch['batch_date'])) ?>
        · <?= e(format_quantity($batch['total_quantity'], $batch['unit'])) ?>
    </p>
    <div class="cahu-printactions">
        <a class="btn cahu-btn-outline" href="<?= e(url('batches/' . $batch['batch_code'])) ?>">Back to batch</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('batches/' . $batch['batch_code'] . '/trace?format=csv')) ?>">
            Download origin CSV
        </a>
        <button class="btn cahu-btn-outline" type="button" onclick="window.print()">Print</button>
    </div>
</div>

<div class="cahu-card mb-3">
    <div class="cahu-card__head">Lot summary</div>
    <div class="cahu-card__body">
        <dl class="cahu-deflist cahu-deflist--wide">
            <dt>Batch ID</dt>
            <dd><?= e($batch['batch_code']) ?></dd>

            <dt>Status</dt>
            <dd><?= status_badge((string) $batch['status']) ?></dd>

            <dt>Crop</dt>
            <dd><?= e($batch['crop_name'] ?? '—') ?></dd>

            <dt>Variety</dt>
            <dd><?= e($batch['variety_name'] ?: 'not specified') ?></dd>

            <dt>Season</dt>
            <dd><?= e($batch['season_name'] ?? '—') ?></dd>

            <dt>Quantity</dt>
            <dd><?= e(format_quantity($batch['total_quantity'], $batch['unit'])) ?></dd>

            <dt>Contributors</dt>
            <dd><?= e((string) (int) ($batch['contributor_count'] ?? 0)) ?></dd>

            <dt>Grade</dt>
            <dd><?= e($batch['grade'] ?: 'not graded') ?></dd>

            <dt>Storage</dt>
            <dd><?= e($batch['storage_location'] ?: '—') ?></dd>
        </dl>
    </div>
</div>

<div class="cahu-card mb-3">
    <div class="cahu-card__head">Contributing farmers and plots</div>

    <?php if ($contributors === []): ?>
        <div class="cahu-card__body">
            <p class="text-muted mb-0">This batch has no harvests in it yet, so there is no origin to report.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table cahu-table align-middle mb-0">
                <thead>
                <tr>
                    <th>Farmer</th>
                    <th>Contact</th>
                    <th>Garden</th>
                    <th>Plot location</th>
                    <th>Planted</th>
                    <th>Harvested</th>
                    <th class="text-end">Quantity</th>
                    <th>Verified by</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($contributors as $contributor): ?>
                    <tr>
                        <td>
                            <span class="fw-semibold"><?= e($contributor['full_name']) ?></span>
                            <div class="text-muted small"><?= e($contributor['farmer_code']) ?></div>
                        </td>
                        <td class="small"><?= e($contributor['phone'] ?: '—') ?></td>
                        <td>
                            <?= e($contributor['garden_code'] ?? '—') ?>
                            <div class="text-muted small"><?= e($contributor['garden_name'] ?? '') ?></div>
                        </td>
                        <td class="small">
                            <?php if ($contributor['latitude'] !== null && $contributor['longitude'] !== null): ?>
                                <?= e(format_number($contributor['latitude'], 5)) ?>,
                                <?= e(format_number($contributor['longitude'], 5)) ?>
                                <div class="text-muted small"><?= e($contributor['district_name'] ?? '') ?></div>
                            <?php else: ?>
                                <span class="text-muted">no coordinates</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= e($contributor['planting_date'] ? format_date($contributor['planting_date']) : '—') ?></td>
                        <td class="small"><?= e($contributor['harvest_date'] ? format_date($contributor['harvest_date']) : '—') ?></td>
                        <td class="text-end cahu-table__number">
                            <?= e(format_number($contributor['quantity_harvested'])) ?>
                            <span class="text-muted small"><?= e($contributor['unit']) ?></span>
                        </td>
                        <td class="small"><?= e($contributor['officer_name'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($chain['sales']) || !empty($chain['exports'])): ?>
    <div class="cahu-card">
        <div class="cahu-card__head">Where this lot went</div>
        <div class="cahu-card__body">
            <?php foreach (($chain['sales'] ?? []) as $sale): ?>
                <div class="cahu-chainrow">
                    <span class="badge text-bg-light">Sale</span>
                    <a href="<?= e(url('sales/' . ($sale['sale_code'] ?? ''))) ?>"><?= e($sale['sale_code'] ?? '') ?></a>
                    <span class="text-muted small">
                        <?= e($sale['buyer_name'] ?? '') ?>
                        <?= $sale['sale_date'] ?? '' ? ' · ' . e(format_date($sale['sale_date'])) : '' ?>
                    </span>
                    <?= status_badge((string) ($sale['status'] ?? '')) ?>
                </div>
            <?php endforeach; ?>

            <?php foreach (($chain['exports'] ?? []) as $export): ?>
                <div class="cahu-chainrow">
                    <span class="badge text-bg-light">Export</span>
                    <a href="<?= e(url('exports/' . ($export['export_code'] ?? ''))) ?>">
                        <?= e($export['export_code'] ?? '') ?>
                    </a>
                    <span class="text-muted small">
                        <?= e($export['destination_country'] ?? '') ?>
                        <?= !empty($export['shipment_date']) ? ' · ' . e(format_date($export['shipment_date'])) : '' ?>
                    </span>
                    <?= status_badge((string) ($export['status'] ?? '')) ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($batch['notes'])): ?>
    <div class="cahu-card mt-3">
        <div class="cahu-card__head">Notes on the lot</div>
        <div class="cahu-card__body"><?= nl2br(e($batch['notes'])) ?></div>
    </div>
<?php endif; ?>