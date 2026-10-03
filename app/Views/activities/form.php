<?php
/**
 * Activity create / edit form.
 *
 * @var Core\View $view
 * @var array<string,mixed>|null $record
 * @var array<int,array{title:string,fields:array}> $sections
 */
// A create request can arrive pre-filled: adding an activity from a planting
// page seeds the form with a record. Editing is therefore keyed off the
// activity ID, not off whether a record exists.
$isEdit = isset($record['activity_code']);
// /edit only serves the form; the update route is a POST to the record itself.
$action = $isEdit ? url('activities/' . $record['activity_code'] . '/edit') : url('activities/create');
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="cahu-pagehead__sub">
            Most activities are generated from the crop lifecycle. Use this form for an extra task that does not
            come from a template.
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('activities')) ?>">Back to register</a>
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

    <div class="cahu-card">
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
            <a class="btn cahu-btn-outline" href="<?= e(url('activities')) ?>">Cancel</a>
            <button class="btn cahu-btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Add activity' ?></button>
        </div>
    </div>
</form>