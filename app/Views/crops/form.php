<?php
/**
 * Crop create / edit form.
 *
 * @var Core\View $view
 * @var array<string,mixed>|null $record
 * @var array<int,array{title:string,fields:array}> $sections
 */
$isEdit = $record !== null;
$action = $isEdit ? url('crops/' . $record['id']) : url('crops/create');
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="cahu-pagehead__sub">
            <?= $isEdit
                ? 'Update the crop record. The crop code cannot be changed.'
                : 'Add a crop to the catalogue. The crop code is issued automatically when you save.' ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('crops')) ?>">Back to register</a>
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
                            <?php $view->partial('partials/field', ['field' => $field, 'record' => $record]); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="cahu-card__foot cahu-form-actions">
            <a class="btn cahu-btn-outline" href="<?= e(url('crops')) ?>">Cancel</a>
            <?php if ($isEdit): ?>
                <a class="btn cahu-btn-outline" href="<?= e(url('crops/' . $record['id'] . '/lifecycle')) ?>">Lifecycle</a>
            <?php endif; ?>
            <button class="btn cahu-btn-primary" type="submit">
                <?= $isEdit ? 'Save changes' : 'Add crop' ?>
            </button>
        </div>
    </div>
</form>
