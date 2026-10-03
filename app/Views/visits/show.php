<?php
/**
 * Field visit detail: the visit, the plot it happened on, and what came of it.
 *
 * @var Core\View $view
 * @var array<string,mixed> $visit
 * @var array<int,array<string,mixed>> $inspections
 * @var array<int,array<string,mixed>> $sprayings
 * @var string $inspectUrl
 */
$hasGps = $visit['latitude'] !== null && $visit['longitude'] !== null;
?>
<div class="cahu-pagehead">
    <div>
        <h1>Visit <?= e($visit['visit_code']) ?></h1>
        <p class="cahu-pagehead__sub">
            <?= e(format_date($visit['visit_date'])) ?>
            &middot; <?= status_badge((string) $visit['status']) ?>
            &middot; <?= e($visit['officer_name'] ?: 'Unassigned') ?>
            <?php if (!empty($visit['farmer_code'])): ?>
                &middot; <?= e($visit['full_name']) ?>
                (<a href="<?= e(url('farmers/' . $visit['farmer_code'])) ?>"><?= e($visit['farmer_code']) ?></a>)
            <?php endif; ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('visits')) ?>">Back to register</a>
        <?php if (!empty($visit['garden_code'])): ?>
            <a class="btn cahu-btn-outline" href="<?= e(url('gardens/' . $visit['garden_code'])) ?>">Open garden</a>
        <?php endif; ?>
        <a class="btn cahu-btn-primary" href="<?= e($inspectUrl) ?>">Record inspection</a>
    </div>
</div>

<div class="cahu-stats mb-3">
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">People met</div>
        <div class="cahu-stat__value"><?= e($visit['people_met'] !== null ? (string) (int) $visit['people_met'] : '—') ?></div>
        <div class="cahu-stat__meta"><?= e($visit['group_name'] ?: 'No group') ?></div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Inspections raised</div>
        <div class="cahu-stat__value"><?= e((string) count($inspections)) ?></div>
        <div class="cahu-stat__meta">from this visit</div>
    </div>
    <div class="cahu-stat cahu-stat--amber">
        <div class="cahu-stat__label">Garden visited</div>
        <div class="cahu-stat__value" style="font-size:1.1rem"><?= e($visit['garden_name'] ?: 'Whole farm') ?></div>
        <div class="cahu-stat__meta">
            <?= e($visit['garden_acreage'] !== null ? format_number($visit['garden_acreage']) . ' acres' : 'No plot recorded') ?>
        </div>
    </div>
    <div class="cahu-stat cahu-stat--soil">
        <div class="cahu-stat__label">Location</div>
        <div class="cahu-stat__value" style="font-size:1.1rem"><?= $hasGps ? 'Captured' : 'Not captured' ?></div>
        <div class="cahu-stat__meta">
            <?= $hasGps
                ? e(round((float) $visit['latitude'], 5)) . ', ' . e(round((float) $visit['longitude'], 5))
                : 'No coordinates on this visit' ?>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-4">
        <div class="cahu-card h-100 mb-3">
            <div class="cahu-card__head">Visit details</div>
            <div class="cahu-card__body">
                <dl class="row mb-0">
                    <?php
                    $details = [
                        'Visit ID'      => $visit['visit_code'],
                        'Officer'       => $visit['officer_name'],
                        'Officer phone' => $visit['officer_phone'],
                        'Farmer'        => $visit['full_name'],
                        'Farmer phone'  => $visit['farmer_phone'],
                        'Village'       => $visit['village_name'],
                        'Garden'        => $visit['garden_name'],
                        'Group'         => $visit['group_name'],
                        'Purpose'       => $visit['visit_purpose'],
                        'Recorded by'   => $visit['recorded_by_name'],
                    ];
                    ?>
                    <?php foreach ($details as $label => $value): ?>
                        <?php if ($value === null || trim((string) $value) === '') { continue; } ?>
                        <dt class="col-5 col-sm-4 text-muted fw-normal"><?= e($label) ?></dt>
                        <dd class="col-7 col-sm-8"><?= e($value) ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>
        </div>

        <?php if (!empty($visit['advisories_given'])): ?>
            <div class="cahu-card">
                <div class="cahu-card__head">Advisories given</div>
                <div class="cahu-card__body">
                    <p class="mb-0"><?= nl2br(e($visit['advisories_given'])) ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-12 col-xl-8">
        <div class="cahu-card mb-3">
            <div class="cahu-card__head">Inspections raised from this visit</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Condition</th>
                            <th>Pests</th>
                            <th>Disease</th>
                            <th>Officer</th>
                            <th class="text-end"></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($inspections === []): ?>
                            <?= $view->partial('partials/empty', [
                                'columns'     => 6,
                                'title'       => 'No inspection recorded yet',
                                'message'     => 'An inspection turns what the officer saw into the structured record that feeds reports and the yield forecast.',
                                'actionLabel' => 'Record inspection',
                                'actionUrl'   => $inspectUrl,
                            ]) ?>
                        <?php else: ?>
                            <?php foreach ($inspections as $inspection): ?>
                                <tr>
                                    <td><?= e(format_date($inspection['inspection_date'])) ?></td>
                                    <td><?= status_badge((string) ($inspection['crop_condition'] ?? 'not assessed')) ?></td>
                                    <td><?= status_badge((string) ($inspection['pest_status'] ?? 'none')) ?></td>
                                    <td><?= status_badge((string) ($inspection['disease_status'] ?? 'none')) ?></td>
                                    <td><?= e($inspection['officer_name'] ?: '—') ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-link text-decoration-none"
                                           href="<?= e(url('inspections/' . $inspection['inspection_code'])) ?>">Open</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="cahu-card">
            <div class="cahu-card__head">Spraying on this plot around this visit</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Product</th>
                            <th>Active ingredient</th>
                            <th class="text-end">Quantity</th>
                            <th class="text-end"></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($sprayings === []): ?>
                            <tr>
                                <td colspan="5" class="text-muted p-3">
                                    No spraying was recorded on this plot in the week either side of this visit.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sprayings as $spraying): ?>
                                <tr>
                                    <td><?= e(format_date($spraying['application_date'])) ?></td>
                                    <td class="fw-semibold"><?= e($spraying['product_name']) ?></td>
                                    <td class="small"><?= e($spraying['active_ingredient'] ?: '—') ?></td>
                                    <td class="text-end">
                                        <?= e(format_quantity($spraying['quantity_used'], (string) ($spraying['unit'] ?? ''))) ?>
                                    </td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-link text-decoration-none"
                                           href="<?= e(url('spraying/' . $spraying['spray_code'])) ?>">Open</a>
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
            A visit that inspections hang off cannot be deleted, because those inspections are the structured
            evidence of it. Cancel the visit instead.
        </span>
        <form method="post" action="<?= e(url('visits/' . $visit['visit_code'] . '/delete')) ?>"
              data-confirm="Remove visit <?= e($visit['visit_code']) ?>? This cannot be undone.">
            <?= csrf_field() ?>
            <button class="btn cahu-btn-outline" type="submit">Remove visit</button>
        </form>
    </div>
</div>