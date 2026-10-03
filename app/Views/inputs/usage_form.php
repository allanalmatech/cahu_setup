<?php
/**
 * Record what a farmer actually applied against an issue.
 *
 * @var Core\View $view
 * @var array<string,mixed>|null $record
 * @var array<int,array{title:string,fields:array}> $sections
 * @var float|null $remaining Amount still available on the issue being edited
 */
$isEdit = $record !== null;
$action = $isEdit ? url('input-usage/' . $record['usage_code']) : url('input-usage/create');
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="cahu-pagehead__sub">
            <?= $isEdit
                ? 'Correct a usage record. The parent issue is brought back into line with its usage rows when you save.'
                : 'Record what was applied in the field. Choosing an issue fixes the item and farmer and caps the amount
                     at what is still outstanding.' ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('input-usage')) ?>">Back to usage</a>
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

<?php if ($isEdit && $remaining !== null): ?>
    <div class="alert alert-info">
        <?= e(format_number($remaining)) ?> <?= e((string) $record['unit']) ?> is available on
        <a href="<?= e(url('input-issues/' . $record['issue_code'])) ?>"><?= e((string) $record['issue_code']) ?></a>
        once this record is taken into account.
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
            <a class="btn cahu-btn-outline" href="<?= e(url('input-usage')) ?>">Cancel</a>
            <button class="btn cahu-btn-primary" type="submit">
                <?= $isEdit ? 'Save usage record' : 'Record usage' ?>
            </button>
        </div>
    </div>
</form>
