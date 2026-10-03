<?php
/**
 * Export traceability sheet.
 *
 * The chain a destination country asks for, written out hop by hop:
 *
 *   Export -> Shipment -> Batch -> Harvest -> Garden -> Crop -> Farmer
 *
 * Modelled on the batch provenance sheet so both read the same way: one card
 * per hop, then the contributing farmers at the bottom with the plot
 * coordinates that back them up.
 *
 * @var array<string,mixed> $export
 * @var array<string,mixed>|null $sale
 * @var array<string,mixed> $batch
 * @var array<string,mixed>|null $harvest
 * @var array<int,array<string,mixed>> $contributors
 * @var array<string,mixed> $summary
 */
?>
<div class="cahu-pagehead">
    <div>
        <h1>Traceability <?= e($export['export_code']) ?></h1>
        <p class="cahu-pagehead__sub">
            Export <?= e($export['export_code']) ?>
            → <?= e($export['destination_country']) ?>
            <?= $export['quantity'] !== null
                ? ' · ' . e(format_quantity((float) $export['quantity'], (string) ($export['unit'] ?? '')))
                : '' ?>
            · <?= e(count($contributors)) ?> harvest(s) from
            <?= e((string) (int) ($summary['farmer_count'] ?? 0)) ?> farmer(s)
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('exports/' . $export['export_code'])) ?>">Back to export</a>
        <button class="btn cahu-btn-outline" type="button" onclick="window.print()">Print</button>
    </div>
</div>

<div class="cahu-card mb-3">
    <div class="cahu-card__head">1 &middot; Export</div>
    <div class="cahu-card__body">
        <dl class="cahu-kv">
            <dt>Export ID</dt>
            <dd class="cahu-table__code"><?= e($export['export_code']) ?></dd>

            <dt>Status</dt>
            <dd><?= status_badge((string) $export['status']) ?></dd>

            <dt>Destination</dt>
            <dd><?= e($export['destination_country']) ?></dd>

            <dt>Quantity</dt>
            <dd><?= e(format_quantity($export['quantity'], (string) ($export['unit'] ?? ''))) ?></dd>

            <dt>Buyer</dt>
            <dd><?= e($export['buyer_name'] ?: 'not recorded') ?></dd>

            <dt>Certificate</dt>
            <dd><?= e($export['certificate_no'] ?: '—') ?></dd>

            <dt>Phytosanitary</dt>
            <dd><?= e($export['phytosanitary_no'] ?: '—') ?></dd>
        </dl>
    </div>
</div>

<div class="cahu-card mb-3">
    <div class="cahu-card__head">2 &middot; Shipment</div>
    <div class="cahu-card__body">
        <dl class="cahu-kv">
            <dt>Shipment date</dt>
            <dd><?= e(format_date($export['shipment_date'])) ?></dd>

            <dt>Estimated arrival</dt>
            <dd><?= e(format_date($export['eta'])) ?></dd>

            <dt>Quality certificate</dt>
            <dd><?= e($export['quality_certificate_no'] ?: '—') ?></dd>

            <dt>Recorded by</dt>
            <dd>
                <?= e($export['created_by_name'] ?: '—') ?>
                <span class="text-muted small"><?= e(format_datetime($export['created_at'])) ?></span>
            </dd>
        </dl>
    </div>
</div>

<div class="cahu-card mb-3">
    <div class="cahu-card__head">3 &middot; Sale</div>
    <div class="cahu-card__body">
        <?php if ($sale === null): ?>
            <p class="text-muted mb-0">This consignment is not linked to a sale, so there is nothing to report here.</p>
        <?php else: ?>
            <dl class="cahu-kv">
                <dt>Sale</dt>
                <dd>
                    <a href="<?= e(url('sales/' . $sale['sale_code'])) ?>"><?= e($sale['sale_code']) ?></a>
                    <?= status_badge((string) $sale['status']) ?>
                </dd>

                <dt>Buyer</dt>
                <dd>
                    <?= e($sale['buyer_name']) ?>
                    <?= !empty($sale['buyer_country']) ? ' · ' . e($sale['buyer_country']) : '' ?>
                </dd>

                <dt>Sale date</dt>
                <dd><?= e(format_date($sale['sale_date'])) ?></dd>

                <dt>Value</dt>
                <dd><?= e(format_money((float) $sale['total_value'], (string) $sale['currency'])) ?></dd>
            </dl>
        <?php endif; ?>
    </div>
</div>

<div class="cahu-card mb-3">
    <div class="cahu-card__head">4 &middot; Batch</div>
    <div class="cahu-card__body">
        <?php if ($batch === []): ?>
            <p class="text-muted mb-0">No batch could be resolved for this consignment.</p>
        <?php else: ?>
            <dl class="cahu-kv">
                <dt>Batch</dt>
                <dd>
                    <a href="<?= e(url('batches/' . $batch['batch_code'])) ?>"><?= e($batch['batch_code']) ?></a>
                    <?= status_badge((string) $batch['status']) ?>
                </dd>

                <dt>Crop</dt>
                <dd>
                    <?= e($batch['crop_name'] ?? '') ?>
                    <?= !empty($batch['variety_name']) ? ' · ' . e($batch['variety_name']) : '' ?>
                </dd>

                <dt>Season</dt>
                <dd><?= e($batch['season_code'] ?: '—') ?></dd>

                <dt>Lot quantity</dt>
                <dd><?= e(format_quantity($batch['total_quantity'], $batch['unit'])) ?></dd>

                <dt>Grade</dt>
                <dd><?= e($batch['grade'] ?: 'not graded') ?></dd>

                <dt>Storage</dt>
                <dd><?= e($batch['storage_location'] ?: '—') ?></dd>
            </dl>
        <?php endif; ?>
    </div>
</div>

<div class="cahu-card mb-3">
    <div class="cahu-card__head">5 &middot; Harvest</div>
    <div class="cahu-card__body">
        <?php if ($harvest === null): ?>
            <p class="text-muted mb-0">No harvest records are attached to this lot yet.</p>
        <?php else: ?>
            <dl class="cahu-kv">
                <dt>Harvest</dt>
                <dd>
                    <a href="<?= e(url('harvest/' . $harvest['harvest_code'])) ?>"><?= e($harvest['harvest_code']) ?></a>
                </dd>

                <dt>Harvest date</dt>
                <dd><?= e(format_date($harvest['harvest_date'])) ?></dd>

                <dt>Quantity</dt>
                <dd><?= e(format_quantity($harvest['quantity_harvested'], (string) $harvest['unit'])) ?></dd>

                <dt>Quality</dt>
                <dd><?= status_badge((string) ($harvest['quality'] ?: 'ungraded')) ?></dd>

                <?php if ($harvest['moisture_content'] !== null): ?>
                    <dt>Moisture</dt>
                    <dd><?= e(format_number((float) $harvest['moisture_content'], 1)) ?>%</dd>
                <?php endif; ?>
            </dl>
            <p class="form-text mb-0">
                The lot aggregates several harvests; this is the first one in the chain. Every contributor is listed below.
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="cahu-card mb-3">
    <div class="cahu-card__head">6 &middot; Garden, crop and farmer</div>

    <?php if ($contributors === []): ?>
        <div class="cahu-card__body">
            <p class="text-muted mb-0">
                This consignment has no harvest records behind it, so there is no origin to report.
            </p>
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
                            <?php if (!empty($contributor['garden_code'])): ?>
                                <a href="<?= e(url('gardens/' . $contributor['garden_code'])) ?>">
                                    <?= e($contributor['garden_code']) ?>
                                </a>
                                <div class="text-muted small"><?= e($contributor['garden_name'] ?? '') ?></div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($contributor['latitude'] !== null && $contributor['longitude'] !== null): ?>
                                <?= e(format_number($contributor['latitude'], 5)) ?>,
                                <?= e(format_number($contributor['longitude'], 5)) ?>
                                <div class="text-muted small">
                                    <?= e($contributor['district_name'] ?? '') ?>
                                    <?= !empty($contributor['sub_county_name'])
                                        ? ' · ' . e($contributor['sub_county_name'])
                                        : '' ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">no coordinates</span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?= e($contributor['planting_date'] ? format_date((string) $contributor['planting_date']) : '—') ?>
                            <?php if (!empty($contributor['planting_code'])): ?>
                                <div class="text-muted">
                                    <a href="<?= e(url('plantings/' . $contributor['planting_code'])) ?>">
                                        <?= e($contributor['planting_code']) ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?= e($contributor['harvest_date'] ? format_date((string) $contributor['harvest_date']) : '—') ?>
                            <?php if (!empty($contributor['harvest_code'])): ?>
                                <div class="text-muted">
                                    <a href="<?= e(url('harvest/' . $contributor['harvest_code'])) ?>">
                                        <?= e($contributor['harvest_code']) ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?= e(format_number($contributor['quantity_harvested'], 3)) ?>
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

<?php if ($contributors !== []): ?>
    <div class="cahu-card">
        <div class="cahu-card__head">Chain summary</div>
        <div class="cahu-card__body">
            <dl class="cahu-kv">
                <dt>Farmers</dt>
                <dd><?= e((string) (int) ($summary['farmer_count'] ?? 0)) ?></dd>

                <dt>Gardens</dt>
                <dd><?= e((string) (int) ($summary['garden_count'] ?? 0)) ?></dd>

                <dt>Area</dt>
                <dd><?= e(format_number($summary['total_acreage'] ?? 0, 2)) ?> acres</dd>

                <dt>Harvest window</dt>
                <dd>
                    <?= e(format_date($summary['first_harvest'] ?? null)) ?>
                    to <?= e(format_date($summary['last_harvest'] ?? null)) ?>
                </dd>

                <dt>Quantity in the lot</dt>
                <dd><?= e(format_quantity($summary['total_quantity'] ?? 0, (string) ($summary['unit'] ?? ''))) ?></dd>
            </dl>
        </div>
    </div>
<?php endif; ?>