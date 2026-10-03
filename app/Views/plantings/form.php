<?php
/**
 * Planting create / edit form.
 *
 * @var Core\View $view
 * @var array<string,mixed>|null $record
 * @var array<int,array{title:string,fields:array}> $sections
 */
// A create request can arrive pre-filled: recording a planting from a garden
// page seeds the form with a record. Editing is therefore keyed off the
// planting ID, not off whether a record exists.
$isEdit = isset($record['planting_code']);
// /edit only serves the form; the update route is a POST to the record itself.
$action = $isEdit ? url('plantings/' . $record['planting_code']) : url('plantings/create');
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="cahu-pagehead__sub">
            Saving a planting generates the activity schedule for the crop and writes an initial yield forecast.
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('plantings')) ?>">Back to register</a>
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
            <a class="btn cahu-btn-outline" href="<?= e(url('plantings')) ?>">Cancel</a>
            <button class="btn cahu-btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Record planting' ?></button>
        </div>
    </div>
</form>

<?php if (!$isEdit): ?>
    <div class="cahu-card">
        <div class="cahu-card__head">What happens when you save</div>
        <div class="cahu-card__body">
            <ol class="mb-0 text-muted">
                <li>The planting ID is issued and the record is linked to the farmer who owns the garden.</li>
                <li>
                    Activity templates configured for the crop (and variety, if one is chosen) are turned into dated
                    tasks. Variety templates override crop templates, and global templates fill any gap.
                </li>
                <li>An initial yield forecast is written from the crop's expected yield per acre.</li>
                <li>The farmer and officer receive a notification that a new crop cycle has started.</li>
            </ol>
        </div>
    </div>
<?php endif; ?>