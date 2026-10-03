<?php
/**
 * Inspection create / edit form.
 *
 * @var Core\View $view
 * @var array<string,mixed>|null $record
 * @var array<int,array{title:string,fields:array}> $sections
 * @var array<int,array<string,mixed>> $findings
 * @var string $gpsLng
 */

// A create request can arrive pre-filled: opening "record inspection" from a
// farmer or garden page supplies a record to seed the form with. Editing is
// therefore keyed off the inspection ID, not off whether a record exists.
$isEdit   = isset($record['inspection_code']);
$action   = $isEdit ? url('inspections/' . $record['inspection_code']) : url('inspections');

$findingTypes     = \Core\Form::enumOptions("'pest','disease','weed','nutrient','water','other'", '');
$findingSeverities = \Core\Form::enumOptions("'none','low','moderate','high','severe'", '');

// Editing starts from what is already recorded; a blank row would otherwise
// post an empty finding and make the officer delete their own history.
$rows = $isEdit && $findings !== []
    ? $findings
    : [['name' => '', 'finding_type' => 'pest', 'severity' => 'low', 'affected_area' => '', 'notes' => '']];
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="cahu-pagehead__sub">
            <?= $isEdit
                ? 'Condition, recommendations and evidence can be corrected. The inspection ID is never reused.'
                : 'The inspection ID is issued automatically. A draft is not counted in reports until it is submitted.' ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('inspections')) ?>">Back to register</a>
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
                            <?php $view->partial('partials/field', ['field' => $field, 'record' => $record, 'gpsLng' => $gpsLng]); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="cahu-card__foot cahu-form-actions">
            <a class="btn cahu-btn-outline" href="<?= e(url('inspections')) ?>">Cancel</a>
            <button class="btn cahu-btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Record inspection' ?></button>
        </div>
    </div>

    <?php /* Findings live inside the form: posted as parallel arrays and stored alongside the inspection. */ ?>
    <div class="cahu-card mb-3">
        <div class="cahu-card__head">Specific findings</div>
        <div class="cahu-card__body">
            <p class="text-muted small mb-3">
                List each distinct problem seen in the field, such as a pest, a disease patch or a nutrient
                deficiency. Leave a row blank to skip it.
            </p>

            <div class="table-responsive">
                <table class="table cahu-table align-middle mb-0" id="findings-table">
                    <thead>
                    <tr>
                        <th>Finding</th>
                        <th style="width: 180px">Type</th>
                        <th style="width: 160px">Severity</th>
                        <th style="width: 140px">Area %</th>
                        <th>Notes</th>
                        <th class="text-end" style="width: 60px"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td>
                                <input class="form-control" type="text" name="finding_name[]"
                                       value="<?= e($row['name'] ?? '') ?>"
                                       placeholder="e.g. Fall armyworm on maize">
                            </td>
                            <td>
                                <select class="form-select" name="finding_type[]">
                                    <?php foreach ($findingTypes as $option): ?>
                                        <option value="<?= e($option['value']) ?>"
                                            <?= (string) $option['value'] === (string) ($row['finding_type'] ?? 'pest') ? ' selected' : '' ?>>
                                            <?= e($option['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select class="form-select" name="finding_severity[]">
                                    <?php foreach ($findingSeverities as $option): ?>
                                        <option value="<?= e($option['value']) ?>"
                                            <?= (string) $option['value'] === (string) ($row['severity'] ?? 'low') ? ' selected' : '' ?>>
                                            <?= e($option['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input class="form-control" type="number" name="finding_area[]"
                                       min="0" max="100" step="0.01"
                                       value="<?= e($row['affected_area'] ?? '') ?>">
                            </td>
                            <td>
                                <input class="form-control" type="text" name="finding_notes[]"
                                       value="<?= e($row['notes'] ?? '') ?>"
                                       placeholder="What was observed">
                            </td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-link text-danger text-decoration-none" type="button"
                                        data-remove-row aria-label="Remove this finding">&times;</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <button class="btn btn-sm cahu-btn-outline mt-3" type="button" data-add-row="#findings-table tbody">
                Add another finding
            </button>

            <noscript>
                <p class="text-muted small mt-2 mb-0">
                    Adding a second row needs JavaScript. Without it, record the most important finding and
                    put the rest in Remarks.
                </p>
            </noscript>
        </div>
    </div>
</form>