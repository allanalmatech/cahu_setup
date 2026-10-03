<?php
/**
 * Crop lifecycle configuration.
 *
 * The table below is the schedule a planting of this crop will actually receive:
 * the rows are resolved with the same specificity rules the CropLifecycle
 * service applies at planting time, so a variety step overrides the crop step
 * for the same day offset, and both override the global default.
 *
 * @var Core\View $view
 * @var array<string,mixed> $crop
 * @var array<int,array<string,mixed>> $rows
 * @var array<int,array<string,mixed>> $varieties
 * @var array<int,array<string,mixed>> $fields
 * @var int $globalCount
 */
$mandatory = count(array_filter($rows, static fn (array $row): bool => (int) $row['is_mandatory'] === 1));
$overrides = count(array_filter($rows, static fn (array $row): bool => $row['_scope'] === 'variety'));
?>
<div class="cahu-pagehead">
    <div>
        <h1>Lifecycle — <?= e($crop['name']) ?></h1>
        <p class="cahu-pagehead__sub">
            <span class="cahu-table__code"><?= e($crop['crop_code']) ?></span>
            &middot; <?= e(ucwords(str_replace('_', ' ', (string) $crop['category']))) ?>
            &middot; <?= status_badge((string) $crop['status']) ?>
            <?php if ($crop['lifecycle_days'] !== null): ?>
                &middot; <?= (int) $crop['lifecycle_days'] ?> day lifecycle
            <?php endif; ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('crops')) ?>">Back to register</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('crops/' . $crop['id'] . '/edit')) ?>">Edit crop</a>
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
        <div class="cahu-stat__label">Steps in schedule</div>
        <div class="cahu-stat__value"><?= e(count($rows)) ?></div>
        <div class="cahu-stat__meta"><?= e($mandatory) ?> mandatory</div>
    </div>
    <div class="cahu-stat cahu-stat--blue">
        <div class="cahu-stat__label">Variety overrides</div>
        <div class="cahu-stat__value"><?= e($overrides) ?></div>
        <div class="cahu-stat__meta">across <?= e(count($varieties)) ?> variety/varieties</div>
    </div>
    <div class="cahu-stat cahu-stat--amber">
        <div class="cahu-stat__label">Global steps in play</div>
        <div class="cahu-stat__value"><?= e($globalCount) ?></div>
        <div class="cahu-stat__meta">apply to every crop</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="cahu-card h-100">
            <div class="cahu-card__head">Resolved lifecycle</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <div class="table-responsive">
                    <table class="table cahu-table align-middle mb-0">
                        <thead>
                        <tr>
                            <th>When</th>
                            <th>Activity</th>
                            <th class="text-end">Duration</th>
                            <th>Assigned to</th>
                            <th>Scope</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($rows === []): ?>
                            <?= $view->partial('partials/empty', [
                                'columns' => 6,
                                'title'   => 'No lifecycle steps resolved',
                                'message' => 'Without a lifecycle, plantings of this crop generate no scheduled activities. Add the first step using the form alongside.',
                            ]) ?>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $offset = (int) $row['day_offset'];
                                $scopeLabel = match ($row['_scope']) {
                                    'global'  => 'All crops',
                                    'variety' => (string) $row['_variety'],
                                    default   => 'This crop',
                                };
                                ?>
                                <tr>
                                    <td>
                                        <span class="cahu-table__code">
                                            <?= $offset < 0 ? '&minus;' . e(-$offset) : e($offset) ?>
                                        </span>
                                        <div class="text-muted small">
                                            <?= $offset < 0 ? 'days before planting' : ($offset === 0 ? 'on planting day' : 'days after planting') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= e(ucwords(str_replace('_', ' ', (string) $row['activity_type']))) ?></div>
                                        <div class="text-muted small"><?= e($row['activity_type']) ?></div>
                                        <?php if (!empty($row['description'])): ?>
                                            <div class="small mt-1"><?= e($row['description']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?= (int) $row['duration_days'] ?> day(s)
                                    </td>
                                    <td>
                                        <?= e(ucfirst((string) $row['assign_role'])) ?>
                                        <?php if ((int) $row['is_mandatory'] === 1): ?>
                                            <div class="text-muted small">Mandatory</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-light text-dark"><?= e($scopeLabel) ?></span>
                                    </td>
                                    <td class="text-end cahu-table__actions">
                                        <form method="post" action="<?= e(url('lifecycle/' . $row['id'] . '/delete')) ?>"
                                              onsubmit="return confirm('Remove this lifecycle step? Plantings that already have a schedule keep theirs.');">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-link text-danger text-decoration-none" type="submit">Remove</button>
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

    <div class="col-12 col-xl-4">
        <div class="cahu-card">
            <div class="cahu-card__head">Add a lifecycle step</div>
            <div class="cahu-card__body">
                <p class="text-muted small">
                    A step is scheduled on every new planting of this crop. A variety-specific step replaces the crop
                    step on the same day offset.
                </p>
                <form method="post" action="<?= e(url('crops/' . $crop['id'] . '/lifecycle')) ?>" novalidate data-validate>
                    <?= csrf_field() ?>
                    <div class="row">
                        <?php foreach ($fields as $field): ?>
                            <?php $view->partial('partials/field', ['field' => $field, 'record' => null]); ?>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn cahu-btn-primary w-100" type="submit">Add step</button>
                </form>
            </div>
        </div>
    </div>
</div>
