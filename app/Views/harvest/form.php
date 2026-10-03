<?php
/**
 * Harvest create / edit form.
 *
 * @var Core\View $view
 * @var array<string,mixed>|null $record
 * @var array<int,array{title:string,fields:array}> $sections
 * @var string $pageTitle
 */
$isEdit = isset($record['id']) && $record['id'] !== null;

// The update route is a POST to the record itself; /edit only serves the form.
$action = $isEdit ? url('harvest/' . $record['harvest_code']) : url('harvest');
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="cahu-pagehead__sub">
            The crop, plot and farmer are taken from the planting, so the origin of this produce cannot be broken.
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('harvest')) ?>">Back to register</a>
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

<form method="post" action="<?= e($action) ?>" novalidate data-validate>
    <?= csrf_field() ?>

    <div class="cahu-card mb-3">
        <div class="cahu-card__body">
            <?php foreach ($sections as $section): ?>
                <div class="form-section">
                    <div class="form-section__title"><?= e($section['title']) ?></div>
                    <div class="row">
                        <?php foreach ($section['fields'] as $field): ?>
                            <?php $view->partial('partials/field', ['field' => $field, 'record' => $record, 'gpsLng' => '']); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="cahu-card__foot cahu-form-actions">
            <a class="btn cahu-btn-outline" href="<?= e(url('harvest')) ?>">Cancel</a>
            <button class="btn cahu-btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Record harvest' ?></button>
        </div>
    </div>
</form>

<?php if (!$isEdit): ?>
    <div class="cahu-card">
        <div class="cahu-card__head">What happens when you save</div>
        <div class="cahu-card__body">
            <ol class="mb-0 text-muted">
                <li>The harvest ID is issued and the record is linked to the planting it came from.</li>
                <li>The quantity is added to the planting's forecast, so the yield variance stays current.</li>
                <li>The planting is marked as harvested once it has produce against it.</li>
                <li>
                    The harvest starts unbatched. Grouping harvests from several farmers into one batch is what
                    creates the lot that buyers and exporters buy against.
                </li>
            </ol>
        </div>
    </div>
<?php endif; ?>