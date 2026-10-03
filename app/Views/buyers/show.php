<?php
/**
 * The buyer's record: contact block, open demand, sales history and deliveries.
 *
 * @var Core\View $view
 * @var array<string,mixed> $buyer
 * @var array<int,array<string,mixed>> $requirements
 * @var array<int,array<string,mixed>> $sales
 * @var array<int,array<string,mixed>> $deliveries
 * @var array<string,float|int> $totals
 */
$outstanding = (float) $totals['sales_value'] - (float) $totals['amount_paid'];
?>
<div class="cahu-pagehead">
    <div class="d-flex align-items-center gap-3">
        <span class="cahu-avatar" style="width:56px;height:56px;font-size:1.5rem">
            <?= e(strtoupper(substr((string) $buyer['name'], 0, 1))) ?>
        </span>
        <div>
            <h1 class="mb-0"><?= e($buyer['name']) ?></h1>
            <p class="cahu-pagehead__sub mb-0">
                <span class="cahu-table__code"><?= e($buyer['buyer_code']) ?></span>
                &middot; <?= status_badge((string) $buyer['status']) ?>
                &middot; <?= e(ucwords(str_replace('_', ' ', (string) $buyer['buyer_type']))) ?>
                <?php if (!empty($buyer['country'])): ?>
                    &middot; <?= e($buyer['country']) ?>
                <?php endif; ?>
            </p>
        </div>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('buyers')) ?>">Back to register</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('buyers/' . $buyer['buyer_code'] . '/edit')) ?>">Edit</a>
        <a class="btn cahu-btn-primary" href="<?= e(url('demand/create?buyer_id=' . $buyer['id'])) ?>">Record demand</a>
    </div>
</div>

<div class="cahu-stats mb-3">
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">Open demand</div>
        <div class="cahu-stat__value"><?= (int) $totals['open_demand'] ?></div>
        <div class="cahu-stat__meta"><?= e(format_number($totals['open_volume'])) ?> required in total</div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Sales recorded</div>
        <div class="cahu-stat__value"><?= (int) $totals['sale_count'] ?></div>
        <div class="cahu-stat__meta"><?= e(format_money($totals['sales_value'])) ?> in value</div>
    </div>
    <div class="cahu-stat cahu-stat--soil">
        <div class="cahu-stat__label">Amount paid</div>
        <div class="cahu-stat__value"><?= e(format_money($totals['amount_paid'])) ?></div>
        <div class="cahu-stat__meta">of <?= e(format_money($totals['sales_value'])) ?></div>
    </div>
    <div class="cahu-stat cahu-stat--amber">
        <div class="cahu-stat__label">Outstanding</div>
        <div class="cahu-stat__value"><?= e(format_money($outstanding)) ?></div>
        <div class="cahu-stat__meta">
            <?= $outstanding > 0.0 ? 'Awaiting payment' : 'Fully settled' ?>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-4">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Contact</div>
            <div class="cahu-card__body">
                <dl class="cahu-kv">
                    <dt>Contact person</dt>
                    <dd><?= e($buyer['contact_person'] ?: '—') ?></dd>

                    <dt>Phone</dt>
                    <dd>
                        <?php if (!empty($buyer['phone'])): ?>
                            <a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $buyer['phone']) ?? '') ?>">
                                <?= e($buyer['phone']) ?>
                            </a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </dd>

                    <dt>Email</dt>
                    <dd>
                        <?php if (!empty($buyer['email'])): ?>
                            <a href="mailto:<?= e($buyer['email']) ?>"><?= e($buyer['email']) ?></a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </dd>

                    <dt>Country</dt>
                    <dd><?= e($buyer['country'] ?: '—') ?></dd>

                    <dt>Region</dt>
                    <dd><?= e($buyer['region_name'] ?: '—') ?></dd>

                    <dt>District</dt>
                    <dd><?= e($buyer['district_name'] ?: '—') ?></dd>

                    <dt>Address</dt>
                    <dd><?= e($buyer['address'] ?: '—') ?></dd>

                    <dt>Contract</dt>
                    <dd><?= e($buyer['contract_ref'] ?: '—') ?></dd>

                    <dt>Quality</dt>
                    <dd class="small"><?= e($buyer['quality_requirements'] ?: 'Not specified') ?></dd>

                    <dt>Default price</dt>
                    <dd>
                        <?php if ($buyer['default_price'] !== null && $buyer['default_price'] !== ''): ?>
                            <?= e(format_money($buyer['default_price'])) ?>
                            <?php if (!empty($buyer['price_unit'])): ?>
                                <?= e($buyer['price_unit']) ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">Not agreed</span>
                        <?php endif; ?>
                    </dd>
                </dl>

                <?php if (!empty($buyer['notes'])): ?>
                    <hr>
                    <div class="text-muted small">Notes</div>
                    <p class="mb-0"><?= nl2br(e($buyer['notes'])) ?></p>
                <?php endif; ?>
            </div>

            <div class="cahu-card__foot">
                <form method="post" action="<?= e(url('buyers/' . $buyer['buyer_code'] . '/delete')) ?>"
                      onsubmit="return confirm('Remove this buyer? If it has any sales it will be refused, otherwise it is deactivated and the ID is retired.');">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm btn-link text-danger text-decoration-none p-0" type="submit">
                        Remove buyer
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-8">
        <div class="cahu-card mb-3">
            <div class="cahu-card__head">Open market demand</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Requirement</th>
                            <th>Crop</th>
                            <th class="text-end">Quantity</th>
                            <th>Quality</th>
                            <th>Delivery location</th>
                            <th>Required by</th>
                            <th class="text-end">Offered</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($requirements === []): ?>
                            <?= $view->partial('partials/empty', [
                                'columns'     => 8,
                                'title'       => 'No open demand',
                                'message'     => 'This buyer has nothing outstanding against the cooperative right now.',
                                'actionLabel' => 'Record demand',
                                'actionUrl'   => url('demand/create?buyer_id=' . $buyer['id']),
                            ]) ?>
                        <?php else: ?>
                            <?php foreach ($requirements as $requirement): ?>
                                <tr>
                                    <td class="cahu-table__code"><?= e($requirement['requirement_code']) ?></td>
                                    <td>
                                        <?= e($requirement['crop_name']) ?>
                                        <?php if (!empty($requirement['variety_name'])): ?>
                                            <div class="text-muted small"><?= e($requirement['variety_name']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= e(format_quantity($requirement['quantity_required'], $requirement['unit'])) ?></td>
                                    <td class="small"><?= e($requirement['quality_required'] ?: '—') ?></td>
                                    <td class="small"><?= e($requirement['delivery_location'] ?: '—') ?></td>
                                    <td><?= e(format_date($requirement['required_date'])) ?></td>
                                    <td class="text-end">
                                        <?php if ($requirement['offered_price'] !== null && $requirement['offered_price'] !== ''): ?>
                                            <?= e(format_money($requirement['offered_price'])) ?>
                                            <div class="text-muted small"><?= e($requirement['price_unit'] ?: '') ?></div>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= status_badge((string) $requirement['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="cahu-card mb-3">
            <div class="cahu-card__head">Sales history</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Sale</th>
                            <th>Date</th>
                            <th>Market</th>
                            <th class="text-end">Value</th>
                            <th class="text-end">Paid</th>
                            <th>Payment</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($sales === []): ?>
                            <tr>
                                <td colspan="7" class="text-muted p-3">
                                    Nothing has been sold to this buyer yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sales as $sale): ?>
                                <tr>
                                    <td class="cahu-table__code"><?= e($sale['sale_code']) ?></td>
                                    <td><?= e(format_date($sale['sale_date'])) ?></td>
                                    <td><?= e($sale['market_name'] ?: '—') ?></td>
                                    <td class="text-end">
                                        <?= e(format_money($sale['total_value'], (string) $sale['currency'])) ?>
                                    </td>
                                    <td class="text-end"><?= e(format_money($sale['amount_paid'], (string) $sale['currency'])) ?></td>
                                    <td><?= status_badge((string) $sale['payment_status']) ?></td>
                                    <td><?= status_badge((string) $sale['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="cahu-card">
            <div class="cahu-card__head">Delivery history</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Delivery</th>
                            <th>Sale</th>
                            <th>Dispatched</th>
                            <th>Expected</th>
                            <th>Delivered</th>
                            <th>Location</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($deliveries === []): ?>
                            <tr>
                                <td colspan="7" class="text-muted p-3">
                                    No delivery has been raised against this buyer\'s sales.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($deliveries as $delivery): ?>
                                <tr>
                                    <td class="cahu-table__code"><?= e($delivery['delivery_code']) ?></td>
                                    <td class="cahu-table__code"><?= e($delivery['sale_code']) ?></td>
                                    <td><?= e(format_date($delivery['dispatch_date'])) ?></td>
                                    <td><?= e(format_date($delivery['expected_date'])) ?></td>
                                    <td><?= e(format_date($delivery['delivered_date'])) ?></td>
                                    <td class="small"><?= e($delivery['delivery_location'] ?: '—') ?></td>
                                    <td><?= status_badge((string) $delivery['status']) ?></td>
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