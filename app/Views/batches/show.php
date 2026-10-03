<?php
/**
 * Batch detail: the lot, its contents, and where it went.
 *
 * @var Core\View $view
 * @var array<string,mixed> $batch
 * @var array<int,array<string,mixed>> $items
 * @var array<int,array<string,mixed>> $candidates
 * @var array<int,array<string,mixed>> $sold
 * @var array<int,array<string,mixed>> $exports
 * @var bool $canManage
 * @var bool $frozen
 * @var array<string,mixed> $summary
 */
?>
<div class="cahu-pagehead">
    <div>
        <h1>Batch <?= e($batch['batch_code']) ?></h1>
        <p class="cahu-pagehead__sub">
            <?= e($batch['crop_name'] ?? '—') ?>
            <?= $batch['variety_name'] ? ' · ' . e($batch['variety_name']) : '' ?>
            · <?= e($batch['season_name'] ?? '') ?>
            · batched <?= e(format_date($batch['batch_date'])) ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('batches')) ?>">Back to register</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('batches/' . $batch['batch_code'] . '/trace')) ?>">
            Traceability sheet
        </a>
        <a class="btn cahu-btn-outline" href="<?= e(url('batches/' . $batch['batch_code'] . '/trace?format=csv')) ?>">
            Origin CSV
        </a>
    </div>
</div>

<?php if ($frozen): ?>
    <div class="alert alert-info">
        This batch is <strong><?= e($batch['status']) ?></strong>, so its contents are fixed.
        <?php if ($batch['status'] === 'closed'): ?>
            Nothing may be added or removed now that the lot is final.
        <?php else: ?>
            The lot has already moved past aggregation.
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="cahu-stats mb-3">
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">Lot quantity</div>
        <div class="cahu-stat__value"><?= e(format_number($batch['total_quantity'])) ?> <?= e($batch['unit']) ?></div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Contributing farmers</div>
        <div class="cahu-stat__value"><?= e((string) (int) ($summary['farmers'] ?? 0)) ?></div>
    </div>
    <div class="cahu-stat cahu-stat--soil">
        <div class="cahu-stat__label">Harvest records</div>
        <div class="cahu-stat__value"><?= e((string) (int) ($summary['harvests'] ?? 0)) ?></div>
    </div>
    <div class="cahu-stat cahu-stat--amber">
        <div class="cahu-stat__label">Area harvested</div>
        <div class="cahu-stat__value"><?= e(format_number($summary['acreage'] ?? 0, 2)) ?> ac</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="cahu-card">
            <div class="cahu-card__head">Harvests in this lot</div>

            <?php if ($items === []): ?>
                <div class="cahu-card__body">
                    <div class="cahu-empty">
                        <h3>No harvests in this batch</h3>
                        <p>Add the harvests that make up the lot. The total updates as each one is added.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Harvest ID</th>
                            <th>Farmer</th>
                            <th>Garden</th>
                            <th>Date</th>
                            <th>Quality</th>
                            <th class="text-end">Quantity</th>
                            <?php if ($canManage && !$frozen): ?><th class="text-end">Action</th><?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td class="cahu-table__code">
                                    <a href="<?= e(url('harvest/' . $item['harvest_code'])) ?>">
                                        <?= e($item['harvest_code']) ?>
                                    </a>
                                </td>
                                <td>
                                    <a class="text-decoration-none" href="<?= e(url('farmers/' . $item['farmer_code'])) ?>">
                                        <?= e($item['full_name']) ?>
                                    </a>
                                </td>
                                <td><?= e($item['garden_code'] ?? '—') ?></td>
                                <td><?= e(format_date($item['harvest_date'])) ?></td>
                                <td><?= status_badge((string) ($item['quality'] ?: $item['grade'] ?: 'ungraded')) ?></td>
                                <td class="text-end cahu-table__number">
                                    <?= e(format_number($item['item_quantity'])) ?>
                                    <span class="text-muted small"><?= e($item['item_unit']) ?></span>
                                </td>
                                <?php if ($canManage && !$frozen): ?>
                                    <td class="text-end">
                                        <form method="post"
                                              action="<?= e(url('batches/' . (int) $batch['id'] . '/items/remove')) ?>"
                                              onsubmit="return confirm('Remove this harvest from the batch?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="harvest_id" value="<?= e((string) (int) $item['harvest_id']) ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($canManage && !$frozen): ?>
            <div class="cahu-card mt-3">
                <div class="cahu-card__head">Add a harvest to this lot</div>
                <div class="cahu-card__body">
                    <?php if ($candidates === []): ?>
                        <p class="text-muted mb-0">
                            No unbatched harvest matches this batch. Only harvests of
                            <strong><?= e($batch['crop_name'] ?? 'this crop') ?></strong> in
                            <strong><?= e($batch['season_name'] ?? 'this season') ?></strong> measured in
                            <strong><?= e($batch['unit']) ?></strong> can be added.
                        </p>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('batches/' . (int) $batch['id'] . '/items')) ?>">
                            <?= csrf_field() ?>
                            <div class="row g-2 align-items-end">
                                <div class="col-md-8">
                                    <label class="form-label" for="harvest_id">Harvest</label>
                                    <select class="form-select" name="harvest_id" id="harvest_id" required>
                                        <?php foreach ($candidates as $candidate): ?>
                                            <option value="<?= e((string) (int) $candidate['id']) ?>">
                                                <?= e($candidate['harvest_code']) ?>
                                                — <?= e($candidate['full_name']) ?>
                                                — <?= e(format_number($candidate['quantity_harvested'])) ?>
                                                <?= e($candidate['unit']) ?>
                                                (<?= e(format_date($candidate['harvest_date'])) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <button class="btn cahu-btn-primary w-100" type="submit">Add to batch</button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-12 col-xl-4">
        <div class="cahu-card">
            <div class="cahu-card__head">Lot details</div>
            <div class="cahu-card__body">
                <dl class="cahu-deflist">
                    <dt>Status</dt>
                    <dd><?= status_badge((string) $batch['status']) ?></dd>

                    <dt>Quantity</dt>
                    <dd><?= e(format_quantity($batch['total_quantity'], $batch['unit'])) ?></dd>

                    <dt>Grade</dt>
                    <dd><?= e($batch['grade'] ?: '—') ?></dd>

                    <dt>Quality</dt>
                    <dd><?= e($batch['quality'] ?: '—') ?></dd>

                    <dt>Storage</dt>
                    <dd><?= e($batch['storage_location'] ?: '—') ?></dd>

                    <?php if (!empty($summary['first_date'])): ?>
                        <dt>Harvest window</dt>
                        <dd>
                            <?= e(format_date($summary['first_date'])) ?>
                            to <?= e(format_date($summary['last_date'])) ?>
                        </dd>
                    <?php endif; ?>

                    <?php if (!empty($summary['weighted_moisture'])): ?>
                        <dt>Weighted moisture</dt>
                        <dd><?= e(format_number($summary['weighted_moisture'], 1)) ?>%</dd>
                    <?php endif; ?>

                    <?php if (!empty($batch['is_perishable'])): ?>
                        <dt>Shelf life</dt>
                        <dd class="text-warning-emphasis">Perishable</dd>
                    <?php endif; ?>
                </dl>

                <?php if (!empty($batch['notes'])): ?>
                    <div class="cahu-note mt-3"><?= nl2br(e($batch['notes'])) ?></div>
                <?php endif; ?>
            </div>

            <?php if ($canManage && !$frozen): ?>
                <div class="cahu-card__foot">
                    <form method="post" action="<?= e(url('batches/' . $batch['batch_code'] . '/close')) ?>"
                          onsubmit="return confirm('Close this batch? Its contents can no longer be changed.');">
                        <?= csrf_field() ?>
                        <button class="btn cahu-btn-outline w-100" type="submit"
                                <?= $items === [] ? 'disabled title="Add at least one harvest first"' : '' ?>>
                            Close batch
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div class="cahu-card mt-3">
            <div class="cahu-card__head">Sales from this lot</div>
            <div class="cahu-card__body">
                <?php if ($sold === []): ?>
                    <p class="text-muted small mb-0">Nothing has been sold from this batch yet.</p>
                <?php else: ?>
                    <ul class="cahu-chainlist mb-0">
                        <?php foreach ($sold as $line): ?>
                            <li>
                                <a href="<?= e(url('sales/' . $line['sale_code'])) ?>"><?= e($line['sale_code']) ?></a>
                                <span class="text-muted small">
                                    <?= e($line['buyer_name']) ?> ·
                                    <?= e(format_quantity($line['quantity'], $batch['unit'])) ?> ·
                                    <?= e(format_date($line['sale_date'])) ?>
                                </span>
                                <?= status_badge((string) $line['sale_status']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="cahu-card mt-3">
            <div class="cahu-card__head">Exports from this lot</div>
            <div class="cahu-card__body">
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
                                    <?= e($export['destination_country']) ?>
                                    <?= $export['shipment_date'] ? ' · ' . e(format_date($export['shipment_date'])) : '' ?>
                                </span>
                                <?= status_badge((string) $export['status']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>