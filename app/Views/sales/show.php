<?php
/**
 * Sale detail: the commercial record and the full origin chain behind it.
 *
 * The traceability panel is the point of the page. Everything below the items
 * table answers "which farmers grew this, on which plots, using what, and who
 * verified it", which is what a buyer or auditor is entitled to see.
 *
 * @var Core\View $view
 * @var array<string,mixed> $sale
 * @var array<int,array<string,mixed>> $items
 * @var array<int,array<string,mixed>> $deliveries
 * @var array<int,array<string,mixed>> $exports
 * @var int $farmerCount
 * @var float $outstanding
 */
$quantity = array_sum(array_map('floatval', array_column($items, 'quantity')));
?>
<div class="cahu-pagehead">
    <div>
        <h1>Sale <?= e($sale['sale_code']) ?></h1>
        <p class="cahu-pagehead__sub">
            <?= e(format_date($sale['sale_date'])) ?>
            · <?= e($sale['buyer_name']) ?>
            (<?= e($sale['buyer_type'] ? ucwords(str_replace('_', ' ', (string) $sale['buyer_type'])) : 'buyer') ?>)
            <?= $sale['market_name'] ? ' · ' . e($sale['market_name']) : '' ?>
            · <?= e(format_money($sale['total_value'], (string) $sale['currency'])) ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('sales')) ?>">Back to register</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('sales/' . $sale['sale_code'] . '/edit')) ?>">Edit</a>
    </div>
</div>

<?php if ((string) $sale['status'] === 'cancelled'): ?>
    <div class="alert alert-warning">
        This sale is <strong>cancelled</strong>. It is kept as an audit record and its tonnage has been
        returned to the batches it came from.
    </div>
<?php endif; ?>

<div class="cahu-stats mb-3">
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">Sale value</div>
        <div class="cahu-stat__value"><?= e(format_money($sale['total_value'], (string) $sale['currency'])) ?></div>
        <div class="cahu-stat__meta"><?= e(format_number($quantity, 3)) ?> across <?= e(count($items)) ?> line(s)</div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Received</div>
        <div class="cahu-stat__value"><?= e(format_money($sale['amount_paid'], (string) $sale['currency'])) ?></div>
        <div class="cahu-stat__meta"><?= status_badge((string) $sale['payment_status']) ?></div>
    </div>
    <div class="cahu-stat cahu-stat--amber">
        <div class="cahu-stat__label">Outstanding</div>
        <div class="cahu-stat__value"><?= e(format_money($outstanding, (string) $sale['currency'])) ?></div>
        <div class="cahu-stat__meta">
            <?= $sale['payment_due_date']
                ? 'due ' . e(format_date((string) $sale['payment_due_date']))
                : 'no due date set' ?>
        </div>
    </div>
    <div class="cahu-stat cahu-stat--soil">
        <div class="cahu-stat__label">Contributing farmers</div>
        <div class="cahu-stat__value"><?= e((string) $farmerCount) ?></div>
        <div class="cahu-stat__meta">across every batch in this sale</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="cahu-card">
            <div class="cahu-card__head">Items sold</div>

            <?php if ($items === []): ?>
                <div class="cahu-card__body">
                    <div class="cahu-empty">
                        <h3>No lines on this sale</h3>
                        <p>A sale is only meaningful with at least one line drawn from a batch.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Batch</th>
                            <th>Crop</th>
                            <th class="text-end">Quantity</th>
                            <th class="text-end">Unit price</th>
                            <th class="text-end">Value</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td class="cahu-table__code">
                                    <?php if (!empty($item['batch_code'])): ?>
                                        <a href="<?= e(url('batches/' . $item['batch_code'])) ?>"><?= e($item['batch_code']) ?></a>
                                        <?= status_badge((string) $item['batch_status']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">no batch</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= e($item['crop_name']) ?></div>
                                    <?php if (!empty($item['variety_name'])): ?>
                                        <div class="text-muted small"><?= e($item['variety_name']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= e(format_number($item['quantity'], 3)) ?>
                                    <span class="text-muted small"><?= e($item['unit']) ?></span>
                                </td>
                                <td class="text-end"><?= e(format_money($item['unit_price'], (string) $sale['currency'])) ?></td>
                                <td class="text-end"><?= e(format_money($item['total_value'], (string) $sale['currency'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="cahu-card mt-3">
            <div class="cahu-card__head">Origin chain</div>
            <div class="cahu-card__body">
                <p class="text-muted small">
                    Everything below is walked back from the batches in this sale to the individual farmers, plots,
                    plantings, inputs and inspections behind the produce.
                </p>

                <?php foreach ($items as $item): ?>
                    <?php
                    $origin      = $item['origin'] ?? [];
                    $batch       = $origin['batch'] ?? [];
                    $summary     = $origin['summary'] ?? [];
                    $contributors = $origin['contributors'] ?? [];
                    ?>
                    <div class="cahu-chip mt-3 mb-2">
                        <?= e($item['batch_code'] ?: 'No batch') ?>
                        — <?= e($item['crop_name']) ?>
                    </div>

                    <?php if ($batch === [] || $contributors === []): ?>
                        <p class="text-muted small mb-3">
                            No origin could be traced for this line: the batch holds no harvest records yet.
                        </p>
                        <?php continue; ?>
                    <?php endif; ?>

                    <dl class="cahu-kv mb-2">
                        <dt>Lot</dt>
                        <dd>
                            <?= e(format_quantity($batch['total_quantity'], $batch['unit'])) ?>
                            <?= status_badge((string) $batch['status']) ?>
                            <?= !empty($batch['grade']) ? ' · ' . e($batch['grade']) : '' ?>
                        </dd>

                        <dt>Season</dt>
                        <dd><?= e($batch['season_code'] ?: '—') ?></dd>

                        <dt>Farmers / plots</dt>
                        <dd>
                            <?= e((string) (int) ($summary['farmer_count'] ?? 0)) ?> farmer(s) across
                            <?= e((string) (int) ($summary['garden_count'] ?? 0)) ?> garden(s)
                        </dd>

                        <dt>Harvest window</dt>
                        <dd>
                            <?= e(format_date($summary['first_harvest'] ?? null)) ?>
                            to <?= e(format_date($summary['last_harvest'] ?? null)) ?>
                        </dd>
                    </dl>

                    <div class="table-responsive">
                        <table class="table cahu-table align-middle mb-3">
                            <thead>
                            <tr>
                                <th>Farmer</th>
                                <th>Garden / plot</th>
                                <th>Crop / planting</th>
                                <th>Harvested</th>
                                <th class="text-end">Quantity</th>
                                <th>Inputs &amp; inspections</th>
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
                                        <div class="text-muted small">
                                            <?= e($contributor['phone'] ?: 'no phone') ?>
                                            <?= !empty($contributor['officer_name'])
                                                ? ' · ' . e($contributor['officer_name'])
                                                : '' ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?= e($contributor['garden_code'] ?? '—') ?>
                                        <div class="text-muted small"><?= e($contributor['garden_name'] ?? '') ?></div>
                                        <div class="text-muted small">
                                            <?php if ($contributor['latitude'] !== null && $contributor['longitude'] !== null): ?>
                                                <?= e(format_number($contributor['latitude'], 5)) ?>,
                                                <?= e(format_number($contributor['longitude'], 5)) ?>
                                                <div><?= e($contributor['district_name'] ?? '') ?></div>
                                            <?php else: ?>
                                                <span class="text-muted">no coordinates recorded</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="small">
                                        <?= e($contributor['expected_yield_per_acre'] !== null
                                            ? 'yield ' . format_number((float) $contributor['expected_yield_per_acre'], 3) . '/ac'
                                            : 'crop not set') ?>
                                        <div class="text-muted">
                                            planted <?= e(format_date($contributor['planting_date'])) ?>
                                        </div>
                                        <?php if (!empty($contributor['growth_stage'])): ?>
                                            <div class="text-muted"><?= e(\Services\Lookups::label((string) $contributor['growth_stage'])) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small">
                                        <?= e(format_date($contributor['harvest_date'])) ?>
                                        <div class="text-muted"><?= e($contributor['harvest_code']) ?></div>
                                        <?php if (!empty($contributor['quality'])): ?>
                                            <div><?= status_badge((string) $contributor['quality']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?= e(format_number($contributor['quantity_harvested'], 3)) ?>
                                        <span class="text-muted small"><?= e($contributor['unit']) ?></span>
                                    </td>
                                    <td class="small">
                                        <?php if (($contributor['inputs'] ?? []) === [] && ($contributor['inspections'] ?? []) === []): ?>
                                            <span class="text-muted">nothing recorded</span>
                                        <?php else: ?>
                                            <?php foreach (($contributor['inputs'] ?? []) as $usage): ?>
                                                <div>
                                                    <?= e($usage['input_name']) ?>
                                                    <span class="text-muted">
                                                        <?= e(format_number($usage['quantity_used'], 2)) ?>
                                                        <?= e((string) $usage['unit']) ?>
                                                        · <?= e(format_date($usage['application_date'])) ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php foreach (($contributor['inspections'] ?? []) as $inspection): ?>
                                                <div class="text-muted">
                                                    inspected <?= e(format_date($inspection['inspection_date'])) ?>
                                                    — <?= e($inspection['crop_condition'] ?: 'condition not stated') ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="cahu-card">
            <div class="cahu-card__head">Sale details</div>
            <div class="cahu-card__body">
                <dl class="cahu-kv">
                    <dt>Buyer</dt>
                    <dd>
                        <?= e($sale['buyer_name']) ?>
                        <div class="text-muted small">
                            <?= e($sale['contact_person'] ?? '') ?>
                            <?= !empty($sale['buyer_phone']) ? ' · ' . e($sale['buyer_phone']) : '' ?>
                        </div>
                        <?php if (!empty($sale['contract_ref'])): ?>
                            <div class="text-muted small">Contract <?= e($sale['contract_ref']) ?></div>
                        <?php endif; ?>
                    </dd>

                    <dt>Market</dt>
                    <dd><?= e($sale['market_name'] ?: '—') ?></dd>

                    <dt>Currency</dt>
                    <dd><?= e($sale['currency']) ?></dd>

                    <dt>Payment due</dt>
                    <dd><?= e(format_date($sale['payment_due_date'])) ?></dd>

                    <dt>Sale status</dt>
                    <dd><?= status_badge((string) $sale['status']) ?></dd>

                    <dt>Recorded</dt>
                    <dd><?= e($sale['created_by_name'] ?: '—') ?></dd>
                    <dd class="text-muted small"><?= e(format_datetime($sale['created_at'])) ?></dd>
                </dl>

                <?php if (!empty($sale['notes'])): ?>
                    <div class="alert alert-light mt-3 mb-0"><?= nl2br(e($sale['notes'])) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ((string) $sale['status'] !== 'cancelled'): ?>
            <div class="cahu-card mt-3">
                <div class="cahu-card__head">Record a payment</div>
                <div class="cahu-card__body">
                    <form method="post" action="<?= e(url('sales/' . $sale['sale_code'] . '/payment')) ?>">
                        <?= csrf_field() ?>
                        <div class="mb-2">
                            <label class="form-label" for="payment_amount">Amount received</label>
                            <input class="form-control" type="number" step="0.01" min="0.01"
                                   id="payment_amount" name="amount" required
                                   placeholder="<?= e((string) $sale['currency']) ?>">
                            <div class="form-text">
                                <?= e(format_money($outstanding, (string) $sale['currency'])) ?> is still outstanding.
                            </div>
                        </div>
                        <button class="btn cahu-btn-primary w-100" type="submit">Record payment</button>
                    </form>
                </div>
            </div>

            <div class="cahu-card mt-3">
                <div class="cahu-card__head">Dispatch this sale</div>
                <div class="cahu-card__body">
                    <form method="post" action="<?= e(url('sales/' . $sale['sale_code'] . '/delivery')) ?>">
                        <?= csrf_field() ?>
                        <div class="row">
                            <?php
                            $dispatchFields = [
                                ['name' => 'dispatch_date', 'label' => 'Dispatch date', 'type' => \Core\Form::DATE, 'colspan' => 6,
                                 'default' => date('Y-m-d')],
                                ['name' => 'expected_date', 'label' => 'Expected date', 'type' => \Core\Form::DATE, 'colspan' => 6],
                                ['name' => 'delivery_location', 'label' => 'Delivery location', 'type' => \Core\Form::TEXT, 'colspan' => 12],
                                ['name' => 'transporter_name', 'label' => 'Transporter', 'type' => \Core\Form::TEXT, 'colspan' => 6],
                                ['name' => 'vehicle_number', 'label' => 'Vehicle number', 'type' => \Core\Form::TEXT, 'colspan' => 6],
                                ['name' => 'tracking_number', 'label' => 'Tracking number', 'type' => \Core\Form::TEXT, 'colspan' => 12],
                                ['name' => 'notes', 'label' => 'Notes', 'type' => \Core\Form::TEXTAREA, 'colspan' => 12],
                            ];
                            foreach ($dispatchFields as $field):
                                ?>
                                <?php $view->partial('partials/field', ['field' => $field, 'record' => null, 'gpsLng' => '']); ?>
                            <?php endforeach; ?>
                        </div>
                        <button class="btn cahu-btn-primary w-100" type="submit"
                                data-confirm="Dispatch this sale? The sale will be marked fulfilled.">
                            Raise delivery
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="cahu-card mt-3">
            <div class="cahu-card__head">Deliveries</div>
            <div class="cahu-card__body">
                <?php if ($deliveries === []): ?>
                    <p class="text-muted small mb-0">Nothing has been dispatched against this sale yet.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($deliveries as $delivery): ?>
                            <li>
                                <a href="<?= e(url('deliveries/' . $delivery['delivery_code'] . '/edit')) ?>">
                                    <?= e($delivery['delivery_code']) ?>
                                </a>
                                <span class="text-muted small">
                                    <?= $delivery['dispatch_date']
                                        ? 'dispatched ' . e(format_date((string) $delivery['dispatch_date']))
                                        : 'not dispatched' ?>
                                    <?= $delivery['delivered_date']
                                        ? ' · delivered ' . e(format_date((string) $delivery['delivered_date']))
                                        : '' ?>
                                    <?= !empty($delivery['delivery_location'])
                                        ? ' · ' . e($delivery['delivery_location'])
                                        : '' ?>
                                </span>
                                <?= status_badge((string) $delivery['status']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="cahu-card mt-3">
            <div class="cahu-card__head">Exports from this sale</div>
            <div class="cahu-card__body">
                <?php if ($exports === []): ?>
                    <p class="text-muted small mb-0">This sale has not been exported.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($exports as $export): ?>
                            <li>
                                <a href="<?= e(url('exports/' . $export['export_code'])) ?>"><?= e($export['export_code']) ?></a>
                                <span class="text-muted small">
                                    <?= e($export['destination_country']) ?>
                                    <?= $export['shipment_date']
                                        ? ' · ' . e(format_date((string) $export['shipment_date']))
                                        : '' ?>
                                </span>
                                <?= status_badge((string) $export['status']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <?php if ((string) $sale['status'] !== 'cancelled'): ?>
            <div class="cahu-card mt-3">
                <div class="cahu-card__body">
                    <form method="post" action="<?= e(url('sales/' . $sale['sale_code'] . '/delete')) ?>"
                          data-confirm="Cancel this sale? The record is kept for audit and the tonnage returns to the batches.">
                        <?= csrf_field() ?>
                        <button class="btn btn-outline-danger w-100" type="submit">Cancel this sale</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>