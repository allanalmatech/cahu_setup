<?php
/**
 * Harvest detail, including where the produce ended up.
 *
 * @var Core\View $view
 * @var array<string,mixed> $harvest
 * @var array<string,mixed>|null $batch
 * @var array<int,array<string,mixed>> $chain
 * @var array<int,array<string,mixed>> $exports
 */
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($harvest['harvest_code']) ?></h1>
        <p class="cahu-pagehead__sub">
            <?= e(format_date($harvest['harvest_date'])) ?> ·
            <?= e(format_quantity($harvest['quantity_harvested'], $harvest['unit'])) ?>
            of <?= e($harvest['crop_name'] ?? 'produce') ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('harvest')) ?>">Back to register</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('harvest/' . $harvest['harvest_code'] . '/edit')) ?>">Edit</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Harvest details</div>
            <div class="cahu-card__body">
                <dl class="cahu-deflist">
                    <dt>Status</dt>
                    <dd><?= status_badge((string) $harvest['status']) ?></dd>

                    <dt>Quantity</dt>
                    <dd><?= e(format_quantity($harvest['quantity_harvested'], $harvest['unit'])) ?></dd>

                    <?php if ((float) $harvest['acreage_harvested'] > 0): ?>
                        <dt>Area harvested</dt>
                        <dd><?= e(format_number($harvest['acreage_harvested'], 3)) ?> acres</dd>

                        <dt>Yield per acre</dt>
                        <dd>
                            <?= e(format_number(
                                (float) $harvest['quantity_harvested'] / (float) $harvest['acreage_harvested']
                            )) ?>
                            <?= e($harvest['unit']) ?>/acre
                        </dd>
                    <?php endif; ?>

                    <dt>Quality</dt>
                    <dd><?= status_badge((string) ($harvest['quality'] ?: 'ungraded')) ?></dd>

                    <?php if (!empty($harvest['grade'])): ?>
                        <dt>Grading note</dt>
                        <dd><?= e($harvest['grade']) ?></dd>
                    <?php endif; ?>

                    <?php if ($harvest['moisture_content'] !== null): ?>
                        <dt>Moisture</dt>
                        <dd><?= e(format_number($harvest['moisture_content'], 1)) ?>%</dd>
                    <?php endif; ?>

                    <?php if ($harvest['losses'] !== null): ?>
                        <dt>Estimated losses</dt>
                        <dd>
                            <?= e(format_number($harvest['losses'])) ?>
                            <?= e($harvest['loss_unit'] ?: '') ?>
                        </dd>
                    <?php endif; ?>

                    <?php if (!empty($harvest['storage_location'])): ?>
                        <dt>Storage</dt>
                        <dd><?= e($harvest['storage_location']) ?></dd>
                    <?php endif; ?>
                </dl>

                <?php if (!empty($harvest['notes'])): ?>
                    <div class="cahu-note mt-3"><?= nl2br(e($harvest['notes'])) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Origin</div>
            <div class="cahu-card__body">
                <dl class="cahu-deflist">
                    <dt>Farmer</dt>
                    <dd>
                        <a href="<?= e(url('farmers/' . $harvest['farmer_code'])) ?>"><?= e($harvest['full_name']) ?></a>
                    </dd>

                    <dt>Garden</dt>
                    <dd>
                        <?php if (!empty($harvest['garden_code'])): ?>
                            <a href="<?= e(url('gardens/' . $harvest['garden_code'])) ?>">
                                <?= e($harvest['garden_code']) ?>
                            </a>
                            <div class="text-muted small"><?= e($harvest['garden_name'] ?? '') ?></div>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </dd>

                    <dt>Planting</dt>
                    <dd>
                        <?php if (!empty($harvest['planting_code'])): ?>
                            <a href="<?= e(url('plantings/' . $harvest['planting_code'])) ?>">
                                <?= e($harvest['planting_code']) ?>
                            </a>
                            <div class="text-muted small">
                                Expected <?= e(format_date($harvest['expected_harvest_date'])) ?>
                            </div>
                        <?php else: ?>
                            <span class="text-muted">not linked</span>
                        <?php endif; ?>
                    </dd>

                    <?php if (!empty($harvest['officer_name'])): ?>
                        <dt>Verified by</dt>
                        <dd><?= e($harvest['officer_name']) ?></dd>
                    <?php endif; ?>

                    <dt>Recorded</dt>
                    <dd><?= e(format_datetime($harvest['created_at'])) ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Batch and downstream chain</div>
            <div class="cahu-card__body">
                <?php if ($batch === null): ?>
                    <p class="text-muted mb-2">This harvest is not in a batch yet.</p>
                    <p class="text-muted small mb-0">
                        Add it to a batch to aggregate produce with other farmers' harvests into a single
                        traceable lot.
                    </p>
                <?php else: ?>
                    <dl class="cahu-deflist">
                        <dt>Batch</dt>
                        <dd>
                            <a href="<?= e(url('batches/' . $batch['batch_code'])) ?>"><?= e($batch['batch_code']) ?></a>
                            <?= status_badge((string) $batch['status']) ?>
                        </dd>

                        <dt>Batch total</dt>
                        <dd><?= e(format_quantity($batch['total_quantity'], $batch['unit'])) ?></dd>

                        <dt>Contributors</dt>
                        <dd><?= e((string) ($batch['contributor_count'] ?? 0)) ?></dd>

                        <dt>Batch date</dt>
                        <dd><?= e(format_date($batch['batch_date'])) ?></dd>
                    </dl>

                    <div class="cahu-subhead mt-3">Sales from this batch</div>
                    <?php if ($chain === []): ?>
                        <p class="text-muted small mb-0">Nothing has been sold from this batch yet.</p>
                    <?php else: ?>
                        <ul class="cahu-chainlist mb-0">
                            <?php foreach ($chain as $sale): ?>
                                <li>
                                    <a href="<?= e(url('sales/' . $sale['sale_code'])) ?>"><?= e($sale['sale_code']) ?></a>
                                    <span class="text-muted small">
                                        <?= e(format_date($sale['sale_date'])) ?> ·
                                        <?= e($sale['buyer_name']) ?> ·
                                        <?= e(format_quantity($sale['quantity'], $sale['unit'])) ?>
                                    </span>
                                    <?= status_badge((string) $sale['status']) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <div class="cahu-subhead mt-3">Exports from this batch</div>
                    <?php if ($exports === []): ?>
                        <p class="text-muted small mb-0">This batch has not been exported.</p>
                    <?php else: ?>
                        <ul class="cahu-chainlist mb-0">
                            <?php foreach ($exports as $export): ?>
                                <li>
                                    <a href="<?= e(url('exports/' . $export['export_code'])) ?>">
                                        <?= e($export['export_code']) ?>
                                    </a>
                                    <span class="text-muted small">
                                        <?= e($export['destination_country']) ?> ·
                                        <?= e($export['shipment_date'] ? format_date($export['shipment_date']) : 'not shipped') ?>
                                    </span>
                                    <?= status_badge((string) $export['status']) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($harvest['status'] !== 'cancelled' && $batch === null): ?>
    <div class="cahu-card mt-3">
        <div class="cahu-card__foot cahu-form-actions">
            <a class="btn cahu-btn-outline" href="<?= e(url('harvest')) ?>">Back</a>
            <form method="post" action="<?= e(url('harvest/' . $harvest['harvest_code'] . '/delete')) ?>"
                  onsubmit="return confirm('Cancel this harvest record? The record is kept for the batch history.');">
                <?= csrf_field() ?>
                <button class="btn btn-outline-danger" type="submit">Cancel harvest</button>
            </form>
        </div>
    </div>
<?php endif; ?>