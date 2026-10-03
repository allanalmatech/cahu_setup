<?php
/**
 * Batch create form.
 *
 * @var Core\View $view
 * @var array<string,mixed> $batch
 * @var array<int,array<string,string>> $crops
 * @var array<int,array<string,string>> $varieties
 * @var array<int,array<string,string>> $seasons
 * @var array<int,array<string,string>> $options
 * @var string $pageTitle
 */
$unitOptions = array_merge([['value' => '', 'label' => 'Use the crop default...']], $options);
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="cahu-pagehead__sub">
            One batch holds one crop, variety, season and unit. Add the harvests that make up the lot afterwards.
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('batches')) ?>">Back to register</a>
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

<form method="post" action="<?= e(url('batches')) ?>" novalidate data-validate>
    <?= csrf_field() ?>

    <div class="cahu-card mb-3">
        <div class="cahu-card__body">
            <div class="form-section">
                <div class="form-section__title">What the lot contains</div>
                <div class="row">
                    <?php
                    $fields = [
                        ['name' => 'crop_id', 'label' => 'Crop', 'type' => 'select', 'required' => true,
                         'colspan' => 6, 'options' => $crops],
                        ['name' => 'variety_id', 'label' => 'Variety', 'type' => 'select',
                         'colspan' => 6, 'options' => $varieties,
                         'help' => 'Only harvests of this exact variety may be added.'],
                        ['name' => 'season_id', 'label' => 'Season', 'type' => 'select', 'required' => true,
                         'colspan' => 6, 'options' => $seasons],
                        ['name' => 'batch_date', 'label' => 'Batch date', 'type' => 'date', 'required' => true,
                         'colspan' => 6, 'default' => date('Y-m-d')],
                        ['name' => 'unit', 'label' => 'Unit', 'type' => 'select', 'required' => true,
                         'colspan' => 6, 'options' => $unitOptions,
                         'help' => 'Harvests measured in another unit cannot be added to this lot.'],
                        ['name' => 'total_quantity', 'label' => 'Opening quantity', 'type' => 'number',
                         'colspan' => 6, 'min' => 0, 'step' => 0.001,
                         'help' => 'Ignored on save: the total is always recalculated from the harvests added.'],
                        ['name' => 'grade', 'label' => 'Grade', 'type' => 'text', 'colspan' => 6],
                        ['name' => 'quality', 'label' => 'Quality', 'type' => 'text', 'colspan' => 6],
                        ['name' => 'storage_location', 'label' => 'Storage location', 'type' => 'text', 'colspan' => 6],
                        ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'colspan' => 12],
                    ];

                    foreach ($fields as $field) {
                        $view->partial('partials/field', ['field' => $field, 'record' => $batch, 'gpsLng' => '']);
                    }
                    ?>
                </div>
            </div>
        </div>

        <div class="cahu-card__foot cahu-form-actions">
            <a class="btn cahu-btn-outline" href="<?= e(url('batches')) ?>">Cancel</a>
            <button class="btn cahu-btn-primary" type="submit">Create batch</button>
        </div>
    </div>
</form>