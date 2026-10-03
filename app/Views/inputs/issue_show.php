<?php
/**
 * One input issue: what was handed over, what has been applied since, and the
 * form to record the next application.
 *
 * @var Core\View $view
 * @var array<string,mixed> $issue
 * @var array<int,array<string,mixed>> $usages
 * @var float $remaining
 * @var array<string,mixed> $record
 * @var array<int,array{title:string,fields:array}> $sections
 */
$progress = (float) $issue['quantity'] > 0
    ? (float) $issue['quantity_used'] / (float) $issue['quantity'] * 100
    : 0.0;
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($issue['issue_code']) ?></h1>
        <p class="cahu-pagehead__sub">
            <?= e($issue['input_name']) ?>
            &middot; <?= status_badge((string) $issue['status']) ?>
            &middot; issued <?= e(format_date($issue['issue_date'])) ?>
            <?php if (!empty($issue['farmer_code'])): ?>
                &middot; <a href="<?= e(url('farmers/' . $issue['farmer_code'])) ?>"><?= e($issue['farmer_name']) ?></a>
            <?php endif; ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('input-issues')) ?>">Back to issues</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('inputs/' . $issue['input_code'] . '/stock')) ?>">Item stock</a>
    </div>
</div>

<?php if ($errors = \Core\Flash::errors()): ?>
    <div class="alert alert-danger">
        <strong>Please correct the following:</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($errors as $message): ?>
                <li><?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="cahu-stats mb-3">
    <div class="cahu-stat cahu-stat--green">
        <div class="cahu-stat__label">Issued</div>
        <div class="cahu-stat__value"><?= e(format_number($issue['quantity'])) ?></div>
        <div class="cahu-stat__meta"><?= e($issue['unit']) ?></div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Applied</div>
        <div class="cahu-stat__value"><?= e(format_number($issue['quantity_used'])) ?></div>
        <div class="cahu-stat__meta"><?= e(format_number($progress, 1)) ?>% of the issue</div>
    </div>
    <div class="cahu-stat <?= $remaining > 0 ? 'cahu-stat--amber' : 'cahu-stat--soil' ?>">
        <div class="cahu-stat__label">Still with the farmer</div>
        <div class="cahu-stat__value"><?= e(format_number($remaining)) ?></div>
        <div class="cahu-stat__meta"><?= e($issue['unit']) ?></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-7">
        <div class="cahu-card">
            <div class="cahu-card__head">Usage recorded against this issue</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Applied</th>
                            <th>Usage ID</th>
                            <th>Plot</th>
                            <th>Activity</th>
                            <th class="text-end">Quantity</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($usages === []): ?>
                            <?= $view->partial('partials/empty', [
                                'columns' => 6,
                                'title'   => 'Nothing applied yet',
                                'message' => 'Record what the farmer actually used in the field so the cooperative knows the rest of the issue is still with them.',
                            ]) ?>
                        <?php else: ?>
                            <?php foreach ($usages as $usage): ?>
                                <tr>
                                    <td class="text-nowrap"><?= e(format_date($usage['application_date'])) ?></td>
                                    <td class="cahu-table__code"><?= e($usage['usage_code']) ?></td>
                                    <td>
                                        <?= e($usage['garden_name'] ?: '—') ?>
                                        <?php if (!empty($usage['purpose'])): ?>
                                            <div class="text-muted small"><?= e($usage['purpose']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted">
                                        <?= e($usage['activity_name'] ?? '—') ?>
                                    </td>
                                    <td class="text-end text-nowrap"><?= e(format_quantity($usage['quantity_used'], $usage['unit'])) ?></td>
                                    <td class="text-end cahu-table__actions">
                                        <a class="btn btn-sm cahu-btn-outline"
                                           href="<?= e(url('input-usage/' . $usage['usage_code'] . '/edit')) ?>">Edit</a>
                                        <form method="post" class="d-inline"
                                              action="<?= e(url('input-usage/' . $usage['usage_code'] . '/delete')) ?>"
                                              onsubmit="return confirm('Remove this usage record? The issue will be recalculated.')">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-link text-danger text-decoration-none"
                                                    type="submit">Remove</button>
                                        </form>
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

    <div class="col-12 col-xl-5">
        <div class="cahu-card mb-3">
            <div class="cahu-card__head">Issue details</div>
            <div class="cahu-card__body">
                <dl class="cahu-kv mb-0">
                    <?php
                    $details = [
                        'Input item'    => $issue['input_name'],
                        'Input ID'      => $issue['input_code'],
                        'Farmer'        => $issue['farmer_name'],
                        'Garden'        => $issue['garden_name'],
                        'Crop'          => $issue['crop_name'],
                        'Season'        => $issue['season_code'] === null ? null : $issue['season_code'] . ' - ' . $issue['season_name'],
                        'Officer'       => $issue['officer_name'],
                        'Issued by'     => $issue['issued_by_name'],
                        'Reference'     => $issue['reference_no'],
                        'Purpose'       => $issue['purpose'],
                        'Unit'          => $issue['unit'],
                    ];
                    ?>
                    <?php foreach ($details as $label => $value): ?>
                        <?php if ($value === null || trim((string) $value) === '' || $value === '—') { continue; } ?>
                        <dt><?= e($label) ?></dt>
                        <dd><?= e($value) ?></dd>
                    <?php endforeach; ?>
                </dl>
            </div>
        </div>

        <?php if ($remaining > 0): ?>
            <div class="cahu-card">
                <div class="cahu-card__head">Record further usage</div>
                <div class="cahu-card__body">
                    <p class="text-muted small">
                        Up to <?= e(format_quantity($remaining, (string) $issue['unit'])) ?> can still be recorded
                        against this issue.
                    </p>
                    <form method="post" action="<?= e(url('input-usage')) ?>" novalidate data-validate>
                        <?= csrf_field() ?>

                        <?php foreach ($sections as $section): ?>
                            <div class="form-section">
                                <div class="form-section__title"><?= e($section['title']) ?></div>
                                <div class="row">
                                    <?php foreach ($section['fields'] as $field): ?>
                                        <?php $view->partial('partials/field', ['field' => $field, 'record' => $record]); ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <button class="btn cahu-btn-primary w-100" type="submit">Record usage</button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="cahu-card">
                <div class="cahu-card__head">Nothing left to record</div>
                <div class="cahu-card__body">
                    <p class="mb-2 text-muted small">
                        The full <?= e(format_quantity((float) $issue['quantity'], (string) $issue['unit'])) ?> has been
                        accounted for against this issue. If the farmer hands any of it back, record a stock return on the
                        <a href="<?= e(url('inputs/' . $issue['input_code'] . '/stock')) ?>">item stock screen</a>.
                    </p>
                    <a class="btn cahu-btn-outline" href="<?= e(url('input-usage/create')) ?>">Record standalone usage</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
