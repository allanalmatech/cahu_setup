<?php
/**
 * Spraying record detail: the application, the plot it went on, and the rest of
 * that plot's spray history.
 *
 * @var Core\View $view
 * @var array<string,mixed> $spraying
 * @var array<int,array<string,mixed>> $history
 * @var array<int,array<string,mixed>> $movements
 */
$next     = $spraying['next_application_date'];
$overdue  = $next !== null && $next !== '' && $next < date('Y-m-d');
$quantity = (float) $spraying['quantity_used'];
?>
<div class="cahu-pagehead">
    <div>
        <h1>Spraying <?= e($spraying['spray_code']) ?></h1>
        <p class="cahu-pagehead__sub">
            <?= e(format_date($spraying['application_date'])) ?>
            &middot; <?= e($spraying['full_name']) ?>
            (<a href="<?= e(url('farmers/' . $spraying['farmer_code'])) ?>"><?= e($spraying['farmer_code']) ?></a>)
            &middot; <?= e($spraying['garden_code']) ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('spraying')) ?>">Back to register</a>
        <?php if (!empty($spraying['garden_code'])): ?>
            <a class="btn cahu-btn-outline" href="<?= e(url('gardens/' . $spraying['garden_code'])) ?>">Open garden</a>
        <?php endif; ?>
        <a class="btn cahu-btn-primary" href="<?= e(url('spraying/' . $spraying['spray_code'] . '/edit')) ?>">Edit</a>
    </div>
</div>

<?php if ($overdue): ?>
    <div class="alert alert-warning">
        The follow-up application was due on <?= e(format_date($next)) ?> and has not been recorded.
        A missed interval lets the pest population rebuild, so this needs chasing rather than waiting.
    </div>
<?php endif; ?>

<div class="cahu-stats mb-3">
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">Product applied</div>
        <div class="cahu-stat__value" style="font-size:1.2rem"><?= e($spraying['product_name']) ?></div>
        <div class="cahu-stat__meta"><?= e($spraying['active_ingredient'] ?: 'Active ingredient not recorded') ?></div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Quantity used</div>
        <div class="cahu-stat__value"><?= e(format_number($quantity)) ?></div>
        <div class="cahu-stat__meta"><?= e($spraying['unit']) ?></div>
    </div>
    <div class="cahu-stat cahu-stat--amber">
        <div class="cahu-stat__label">Target</div>
        <div class="cahu-stat__value" style="font-size:1.1rem"><?= e($spraying['pest_disease_targeted'] ?: 'Not recorded') ?></div>
        <div class="cahu-stat__meta"><?= e($spraying['application_method'] ? ucwords(str_replace('_', ' ', (string) $spraying['application_method'])) : 'Method not recorded') ?></div>
    </div>
    <div class="cahu-stat <?= $overdue ? 'cahu-stat--red' : 'cahu-stat--soil' ?>">
        <div class="cahu-stat__label">Next application</div>
        <div class="cahu-stat__value" style="font-size:1.1rem"><?= e(format_date($next)) ?></div>
        <div class="cahu-stat__meta"><?= $overdue ? 'Overdue' : 'Follow-up reminder set' ?></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-4">
        <div class="cahu-card h-100 mb-3">
            <div class="cahu-card__head">Application details</div>
            <div class="cahu-card__body">
                <dl class="row mb-0">
                    <?php
                    $details = [
                        'Spray ID'        => $spraying['spray_code'],
                        'Farmer'          => $spraying['full_name'],
                        'Farmer phone'    => $spraying['farmer_phone'],
                        'Village'         => $spraying['village_name'],
                        'Garden'          => $spraying['garden_name'],
                        'Garden acreage'  => $spraying['garden_acreage'] !== null
                            ? format_number($spraying['garden_acreage']) . ' acres'
                            : null,
                        'Crop'            => $spraying['crop_name'],
                        'Variety'         => $spraying['variety_name'],
                        'Planting'        => $spraying['planting_code'],
                        'Season'          => $spraying['season_code'],
                        'Dosage'          => $spraying['dosage'],
                        'Applicator'      => $spraying['applicator_name'],
                        'Officer'         => $spraying['officer_name'],
                        'Weather'         => $spraying['weather_conditions'],
                        'Stock item'      => $spraying['input_code'],
                        'Recorded by'     => $spraying['recorded_by_name'],
                    ];
                    ?>
                    <?php foreach ($details as $label => $value): ?>
                        <?php if ($value === null || trim((string) $value) === '') { continue; } ?>
                        <dt class="col-5 col-sm-4 text-muted fw-normal"><?= e($label) ?></dt>
                        <dd class="col-7 col-sm-8"><?= e($value) ?></dd>
                    <?php endforeach; ?>
                </dl>

                <?php if ($spraying['input_code'] !== null): ?>
                    <hr>
                    <div class="text-muted small">
                        Taken from inventory item <span class="cahu-table__code"><?= e($spraying['input_code']) ?></span>,
                        which now stands at
                        <?= e(format_number($spraying['input_stock'], 2)) ?> <?= e($spraying['unit']) ?>.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($spraying['remarks'])): ?>
            <div class="cahu-card">
                <div class="cahu-card__head">Remarks</div>
                <div class="cahu-card__body">
                    <p class="mb-0"><?= nl2br(e($spraying['remarks'])) ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-12 col-xl-8">
        <?php if ($movements !== []): ?>
            <div class="cahu-card mb-3">
                <div class="cahu-card__head">Stock movements for this record</div>
                <div class="cahu-card__body cahu-card__body--flush">
                    <div class="table-responsive">
                        <table class="table cahu-table align-middle mb-0">
                            <thead>
                            <tr>
                                <th>Date</th>
                                <th>Movement</th>
                                <th>Type</th>
                                <th class="text-end">Quantity</th>
                                <th class="text-end">Balance after</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($movements as $movement): ?>
                                <tr>
                                    <td><?= e(format_date($movement['transaction_date'])) ?></td>
                                    <td class="cahu-table__code"><?= e($movement['transaction_code']) ?></td>
                                    <td><?= e(ucwords(str_replace('_', ' ', (string) $movement['transaction_type']))) ?></td>
                                    <td class="text-end"><?= e(format_number($movement['quantity'])) ?> <?= e($movement['unit']) ?></td>
                                    <td class="text-end"><?= e(format_number($movement['balance_after'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="cahu-card">
            <div class="cahu-card__head">Other sprayings on this garden</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Product</th>
                            <th>Active ingredient</th>
                            <th>Target</th>
                            <th class="text-end">Quantity</th>
                            <th>Officer</th>
                            <th class="text-end"></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($history === []): ?>
                            <?= $view->partial('partials/empty', [
                                'columns'     => 7,
                                'title'       => 'First spray on this garden',
                                'message'     => 'There is no earlier treatment on this plot to compare against. A rotation is usually only obvious once there is history.',
                            ]) ?>
                        <?php else: ?>
                            <?php foreach ($history as $past): ?>
                                <tr>
                                    <td><?= e(format_date($past['application_date'])) ?></td>
                                    <td class="fw-semibold"><?= e($past['product_name']) ?></td>
                                    <td class="small"><?= e($past['active_ingredient'] ?: '—') ?></td>
                                    <td class="small"><?= e($past['pest_disease_targeted'] ?: '—') ?></td>
                                    <td class="text-end">
                                        <?= e(format_quantity($past['quantity_used'], (string) ($past['unit'] ?? ''))) ?>
                                    </td>
                                    <td><?= e($past['officer_name'] ?: '—') ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-link text-decoration-none"
                                           href="<?= e(url('spraying/' . $past['spray_code'])) ?>">Open</a>
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
</div>

<div class="cahu-card mt-3">
    <div class="cahu-card__body d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="text-muted small">
            Removing this record returns anything it took out of the store to the ledger first, so the balance
            is not left short for a product still on the shelf.
        </span>
        <form method="post" action="<?= e(url('spraying/' . $spraying['spray_code'] . '/delete')) ?>"
              data-confirm="Remove spraying record <?= e($spraying['spray_code']) ?>? Any stock it consumed will be returned to the store.">
            <?= csrf_field() ?>
            <button class="btn cahu-btn-outline" type="submit">Remove record</button>
        </form>
    </div>
</div>