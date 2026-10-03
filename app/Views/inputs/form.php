<?php
/**
 * Input item create / edit form.
 *
 * @var Core\View $view
 * @var array<string,mixed>|null $record
 * @var array<int,array{title:string,fields:array}> $sections
 * @var bool $locked Whether the item already has stock movements
 */
$isEdit = $record !== null;
$action = $isEdit ? url('inputs/' . $record['input_code'] . '/edit') : url('inputs/create');
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="cahu-pagehead__sub">
            <?= $isEdit
                ? 'Update the item record. The input ID cannot be changed.'
                : 'Register an item on the shelf. The input ID is issued automatically when you save.' ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('inputs')) ?>">Back to inventory</a>
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

<?php if ($isEdit && $locked): ?>
    <div class="alert alert-warning">
        This item already has stock movements on the ledger, so its category and unit of measure are fixed.
        Changing either would make the historical movement rows meaningless.
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
            <a class="btn cahu-btn-outline" href="<?= e(url('inputs')) ?>">Cancel</a>
            <button class="btn cahu-btn-primary" type="submit">
                <?= $isEdit ? 'Save changes' : 'Register item' ?>
            </button>
        </div>
    </div>
</form>

<?php if ($isEdit): ?>
    <form method="post" action="<?= e(url('inputs/' . $record['input_code'] . '/delete')) ?>"
          onsubmit="return confirm('Deactivate this input item?')">
        <?= csrf_field() ?>
        <button class="btn btn-link text-danger text-decoration-none" type="submit">Deactivate this item</button>
    </form>
<?php endif; ?>
