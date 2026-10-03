<?php
/**
 * Market demand create / edit form.
 *
 * @var Core\View $view
 * @var array<string,mixed>|null $record
 * @var array<int,array{title:string,fields:array}> $sections
 * @var bool $isEdit
 */
// $record may hold a prefill on a create screen (buyer_id / crop_id from the
// query string), so the mode is stated explicitly rather than inferred.
$isEdit = $isEdit ?? ($record !== null && isset($record['requirement_code']));
$action = $isEdit ? url('demand/' . $record['requirement_code'] . '/edit') : url('demand/create');
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="cahu-pagehead__sub">
            <?= $isEdit
                ? 'Update the demand record. The requirement ID cannot be changed.'
                : 'Record what a buyer has asked for. The requirement ID is issued automatically when you save.' ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('demand')) ?>">Back to demand register</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('demand/balance')) ?>">Supply vs demand</a>
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
                            <?= $view->partial('partials/field', ['field' => $field, 'record' => $record]) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="cahu-card__foot cahu-form-actions">
            <a class="btn cahu-btn-outline" href="<?= e(url('demand')) ?>">Cancel</a>
            <button class="btn cahu-btn-primary" type="submit">
                <?= $isEdit ? 'Save changes' : 'Record demand' ?>
            </button>
        </div>
    </div>
</form>

<?php if ($isEdit): ?>
    <div class="cahu-card">
        <div class="cahu-card__body d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="text-muted small">
                A requirement that has already been committed against cannot be removed. Withdrawing it
                cancels the requirement so the commitment history survives.
            </div>
            <form method="post" action="<?= e(url('demand/' . $record['requirement_code'] . '/delete')) ?>"
                  onsubmit="return confirm('Withdraw this demand? Anything already committed against it is cancelled rather than deleted.');">
                <?= csrf_field() ?>
                <button class="btn btn-link text-danger text-decoration-none" type="submit">Withdraw demand</button>
            </form>
        </div>
    </div>
<?php endif; ?>