<?php
/**
 * Export detail: the consignment record and a summary of its shipment chain.
 *
 * @var Core\View $view
 * @var array<string,mixed> $export
 * @var array<string,mixed> $batch
 * @var array<int,array<string,mixed>> $contributors
 * @var array<string,mixed> $summary
 */
?>
<div class="cahu-pagehead">
    <div>
        <h1>Export <?= e($export['export_code']) ?></h1>
        <p class="cahu-pagehead__sub">
            <?= e($export['destination_country']) ?>
            <?= $export['quantity'] !== null
                ? ' · ' . e(format_quantity((float) $export['quantity'], (string) ($export['unit'] ?? '')))
                : '' ?>
            <?= $export['shipment_date']
                ? ' · shipped ' . e(format_date((string) $export['shipment_date']))
                : ' · not shipped yet' ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('exports')) ?>">Back to register</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('exports/' . $export['export_code'] . '/trace')) ?>">
            Full traceability chain
        </a>
    </div>
</div>

<div class="cahu-stats mb-3">
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">Quantity shipped</div>
        <div class="cahu-stat__value">
            <?= e(format_number($export['quantity'], 3)) ?>
            <small><?= e((string) ($export['unit'] ?? '')) ?></small>
        </div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Farmers of origin</div>
        <div class="cahu-stat__value"><?= e((string) (int) ($summary['farmer_count'] ?? 0)) ?></div>
        <div class="cahu-stat__meta">across <?= e((string) (int) ($summary['garden_count'] ?? 0)) ?> garden(s)</div>
    </div>
    <div class="cahu-stat cahu-stat--soil">
        <div class="cahu-stat__label">Area of origin</div>
        <div class="cahu-stat__value"><?= e(format_number($summary['total_acreage'] ?? 0, 2)) ?></div>
        <div class="cahu-stat__meta">acres harvested</div>
    </div>
    <div class="cahu-stat cahu-stat--amber">
        <div class="cahu-stat__label">ETA</div>
        <div class="cahu-stat__value" style="font-size:1.05rem">
            <?= e(format_date($export['eta'])) ?>
        </div>
        <div class="cahu-stat__meta"><?= e((string) $export['status']) ?></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-5">
        <div class="cahu-card">
            <div class="cahu-card__head">Consignment</div>
            <div class="cahu-card__body">
                <dl class="cahu-kv">
                    <dt>Status</dt>
                    <dd><?= status_badge((string) $export['status']) ?></dd>

                    <dt>Buyer</dt>
                    <dd>
                        <?= e($export['buyer_name'] ?: '—') ?>
                        <?php if (!empty($export['buyer_type'])): ?>
                            <div class="text-muted small">
                                <?= e(ucwords(str_replace('_', ' ', (string) $export['buyer_type']))) ?>
                            </div>
                        <?php endif; ?>
                    </dd>

                    <dt>Destination</dt>
                    <dd><?= e($export['destination_country']) ?></dd>

                    <dt>Shipment date</dt>
                    <dd><?= e(format_date($export['shipment_date'])) ?></dd>

                    <dt>Estimated arrival</dt>
                    <dd><?= e(format_date($export['eta'])) ?></dd>

                    <dt>Certificate</dt>
                    <dd><?= e($export['certificate_no'] ?: '—') ?></dd>

                    <dt>Quality certificate</dt>
                    <dd><?= e($export['quality_certificate_no'] ?: '—') ?></dd>

                    <dt>Phytosanitary</dt>
                    <dd><?= e($export['phytosanitary_no'] ?: '—') ?></dd>

                    <dt>Recorded</dt>
                    <dd>
                        <?= e($export['created_by_name'] ?: '—') ?>
                        <div class="text-muted small"><?= e(format_datetime($export['created_at'])) ?></div>
                    </dd>
                </dl>

                <?php if (!empty($export['notes'])): ?>
                    <div class="alert alert-light mt-3 mb-0"><?= nl2br(e($export['notes'])) ?></div>
                <?php endif; ?>
            </div>

            <div class="cahu-card__foot cahu-form-actions">
                <a class="btn cahu-btn-outline w-100" href="<?= e(url('exports')) ?>">Back to register</a>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-7">
        <div class="cahu-card mb-3">
            <div class="cahu-card__head">Shipment chain</div>
            <div class="cahu-card__body">
                <ol class="cahu-timeline">
                    <li class="cahu-timeline__item is-done">
                        <div class="fw-semibold">Export <?= e($export['export_code']) ?></div>
                        <div class="text-muted small">
                            <?= e($export['destination_country']) ?>
                            · <?= status_badge((string) $export['status']) ?>
                        </div>
                    </li>

                    <li class="cahu-timeline__item <?= $export['sale_code'] ? 'is-done' : '' ?>">
                        <div class="fw-semibold">Sale</div>
                        <?php if (!empty($export['sale_code'])): ?>
                            <div class="text-muted small">
                                <a href="<?= e(url('sales/' . $export['sale_code'])) ?>"><?= e($export['sale_code']) ?></a>
                                · <?= e(format_date((string) $export['sale_date'])) ?>
                                · <?= e(format_money((float) $export['sale_value'], (string) $export['sale_currency'])) ?>
                            </div>
                        <?php else: ?>
                            <div class="text-muted small">Not linked to a sale.</div>
                        <?php endif; ?>
                    </li>

                    <li class="cahu-timeline__item <?= !empty($export['batch_code']) ? 'is-done' : '' ?>">
                        <div class="fw-semibold">Batch</div>
                        <?php if (!empty($export['batch_code'])): ?>
                            <div class="text-muted small">
                                <a href="<?= e(url('batches/' . $export['batch_code'])) ?>"><?= e($export['batch_code']) ?></a>
                                · <?= e($export['crop_name'] ?? '') ?>
                                · <?= e(format_number($batch['total_quantity'] ?? 0, 3)) ?>
                                <?= e((string) ($batch['unit'] ?? '')) ?>
                            </div>
                        <?php else: ?>
                            <div class="text-muted small">No batch resolved for this consignment.</div>
                        <?php endif; ?>
                    </li>

                    <li class="cahu-timeline__item <?= $contributors !== [] ? 'is-done' : '' ?>">
                        <div class="fw-semibold">Harvests, gardens and farmers</div>
                        <div class="text-muted small">
                            <?php if ($contributors === []): ?>
                                No harvest records are attached to the batch.
                            <?php else: ?>
                                <?= e(count($contributors)) ?> harvest(s) from
                                <?= e((string) (int) ($summary['farmer_count'] ?? 0)) ?> farmer(s),
                                harvested <?= e(format_date($summary['first_harvest'] ?? null)) ?>
                                to <?= e(format_date($summary['last_harvest'] ?? null)) ?>.
                            <?php endif; ?>
                        </div>
                    </li>
                </ol>

                <?php if ($contributors !== []): ?>
                    <div class="table-responsive mt-3">
                        <table class="table cahu-table align-middle mb-0">
                            <thead>
                            <tr>
                                <th>Farmer</th>
                                <th>Garden</th>
                                <th>Harvested</th>
                                <th class="text-end">Quantity</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($contributors as $contributor): ?>
                                <tr>
                                    <td>
                                        <a class="text-decoration-none"
                                           href="<?= e(url('farmers/' . $contributor['farmer_code'])) ?>">
                                            <?= e($contributor['full_name']) ?>
                                        </a>
                                        <div class="text-muted small"><?= e($contributor['farmer_code']) ?></div>
                                    </td>
                                    <td class="small">
                                        <?= e($contributor['garden_code'] ?? '—') ?>
                                        <div class="text-muted"><?= e($contributor['district_name'] ?? '') ?></div>
                                    </td>
                                    <td class="small"><?= e(format_date($contributor['harvest_date'])) ?></td>
                                    <td class="text-end">
                                        <?= e(format_number($contributor['quantity_harvested'], 3)) ?>
                                        <span class="text-muted small"><?= e($contributor['unit']) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="cahu-card__foot cahu-form-actions">
                <a class="btn cahu-btn-primary w-100" href="<?= e(url('exports/' . $export['export_code'] . '/trace')) ?>">
                    Open the full traceability chain
                </a>
            </div>
        </div>
    </div>
</div>