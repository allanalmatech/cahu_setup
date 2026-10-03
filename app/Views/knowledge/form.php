<?php
/**
 * Advisory create / edit form.
 *
 * @var Core\View $view
 * @var array<string,mixed>|null $record
 * @var array<int,array{title:string,fields:array<int,array<string,mixed>>}> $sections
 */
$isEdit = $record !== null;
$action = $isEdit ? url('knowledge/' . $record['slug']) : url('knowledge');
$backTo = url('knowledge');
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="cahu-pagehead__sub">
            <?= $isEdit
                ? 'Update the advisory. Its address only changes if you change the title.'
                : 'Add an advisory to the library. The address is generated from the title when you save.' ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e($backTo) ?>">Back to library</a>
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

<form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" novalidate data-validate>
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
            <a class="btn cahu-btn-outline" href="<?= e($backTo) ?>">Cancel</a>
            <button class="btn cahu-btn-primary" type="submit">
                <?= $isEdit ? 'Save changes' : 'Save advisory' ?>
            </button>
        </div>
    </div>
</form>

<?php if ($isEdit): ?>
    <div class="cahu-card">
        <div class="cahu-card__head">Withdraw this advisory</div>
        <div class="cahu-card__body">
            <p class="small text-muted">
                Withdrawing removes the advisory from the library. Farmers who were directed to this page will
                no longer find it, so only do this when the guidance is wrong or out of date.
            </p>
            <form method="post" action="<?= e(url('knowledge/' . $record['slug'] . '/delete')) ?>"
                  onsubmit="return confirm('Withdraw this advisory from the library?');">
                <?= csrf_field() ?>
                <button class="btn btn-outline-danger" type="submit">Withdraw advisory</button>
            </form>
        </div>
    </div>
<?php endif; ?>
