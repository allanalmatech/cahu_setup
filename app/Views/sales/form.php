<?php
/**
 * Sale create / edit form, with the repeatable sale-item sub-form.
 *
 * Lines are posted as `items[][batch_id]` and friends. A blank row is dropped
 * by the controller, so the extra row the page keeps ready is harmless.
 *
 * @var Core\View $view
 * @var array<string,mixed>|null $record
 * @var array<int,array{title:string,fields:array}> $sections
 * @var array<int,array{value:string,label:string}> $crops
 * @var array<int,array{id:int,crop_id:int,label:string}> $batches
 * @var array<int,array<string,mixed>> $items
 * @var string $saleCode
 * @var bool $locked
 */
$isEdit  = $saleCode !== '';
$action  = $isEdit ? url('sales/' . $saleCode) : url('sales');

$rows = $isEdit && $items !== []
    ? $items
    : [['id' => 0, 'batch_id' => 0, 'crop_id' => 0, 'quantity' => '', 'unit_price' => '']];
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="cahu-pagehead__sub">
            <?= $locked
                ? 'This sale is fulfilled, so its lines are fixed. The header can still be corrected.'
                : 'Each line takes tonnage from one batch. A lot can never be sold twice.' ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <?php if ($isEdit): ?>
            <a class="btn cahu-btn-outline" href="<?= e(url('sales/' . $saleCode)) ?>">Back to sale</a>
        <?php else: ?>
            <a class="btn cahu-btn-outline" href="<?= e(url('sales')) ?>">Back to register</a>
        <?php endif; ?>
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
    </div>

    <div class="cahu-card mb-3">
        <div class="cahu-card__head">What is being sold</div>
        <div class="cahu-card__body">
            <?php if ($locked): ?>
                <div class="alert alert-info">
                    This sale has been fulfilled. The lines below are shown for reference and cannot be changed.
                </div>
            <?php endif; ?>

            <p class="text-muted small mb-3">
                Every line draws from one harvest batch. The batch supplies the crop and the unit; the quantity may
                not exceed what is still uncommitted on that lot. Leave a row blank to skip it.
            </p>

            <div class="table-responsive">
                <table class="table cahu-table align-middle mb-0" id="sale-items-table">
                    <thead>
                    <tr>
                        <th>Crop</th>
                        <th>Batch</th>
                        <th style="width:150px">Quantity</th>
                        <th style="width:150px">Unit price</th>
                        <th style="width:130px" class="text-end">Line value</th>
                        <?php if (!$locked): ?><th class="text-end" style="width:56px"></th><?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $index => $row): ?>
                        <tr>
                            <td>
                                <input type="hidden" name="items[<?= e((string) $index) ?>][id]"
                                       value="<?= e((string) (int) ($row['id'] ?? 0)) ?>">
                                <select class="form-select" name="items[<?= e((string) $index) ?>][crop_id]"
                                        <?= $locked ? 'disabled' : '' ?>
                                        data-item-crop aria-label="Crop">
                                    <?php foreach ($crops as $option): ?>
                                        <option value="<?= e($option['value']) ?>"
                                            <?= (string) ($row['crop_id'] ?? '') === (string) $option['value'] ? 'selected' : '' ?>>
                                            <?= e($option['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select class="form-select" name="items[<?= e((string) $index) ?>][batch_id]"
                                        <?= $locked ? 'disabled' : '' ?>
                                        data-item-batch aria-label="Batch">
                                    <option value="">Select batch...</option>
                                    <?php foreach ($batches as $batch): ?>
                                        <option value="<?= e((string) $batch['id']) ?>"
                                                data-crop="<?= e((string) $batch['crop_id']) ?>"
                                            <?= (string) ($row['batch_id'] ?? '') === (string) $batch['id'] ? 'selected' : '' ?>>
                                            <?= e($batch['label']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input class="form-control" type="number" min="0" step="0.001"
                                       name="items[<?= e((string) $index) ?>][quantity]"
                                       value="<?= e((string) ($row['quantity'] ?? '')) ?>"
                                       data-item-quantity <?= $locked ? 'disabled' : '' ?> aria-label="Quantity">
                            </td>
                            <td>
                                <input class="form-control" type="number" min="0" step="0.01"
                                       name="items[<?= e((string) $index) ?>][unit_price]"
                                       value="<?= e((string) ($row['unit_price'] ?? '')) ?>"
                                       data-item-price <?= $locked ? 'disabled' : '' ?> aria-label="Unit price">
                            </td>
                            <td class="text-end" data-item-total>
                                <?php e(format_money(
                                    isset($row['quantity'], $row['unit_price'])
                                        ? (float) $row['quantity'] * (float) $row['unit_price']
                                        : 0
                                )) ?>
                            </td>
                            <?php if (!$locked): ?>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-link text-danger text-decoration-none" type="button"
                                            data-remove-row aria-label="Remove this line">&times;</button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!$locked): ?>
                <button class="btn btn-sm cahu-btn-outline mt-3" type="button" data-add-row="#sale-items-table tbody">
                    Add another line
                </button>

                <noscript>
                    <p class="text-muted small mt-2 mb-0">
                        Adding a second line needs JavaScript. Without it, record the batches one at a time.
                    </p>
                </noscript>
            <?php endif; ?>
        </div>

        <div class="cahu-card__foot cahu-form-actions">
            <a class="btn cahu-btn-outline" href="<?= e($isEdit ? url('sales/' . $saleCode) : url('sales')) ?>">Cancel</a>
            <button class="btn cahu-btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Record sale' ?></button>
        </div>
    </div>
</form>

<?php
$pageScripts = [
    <<<'JS'
    (function () {
        var table = document.getElementById('sale-items-table');
        if (!table) return;

        var body = table.querySelector('tbody');

        // The sub-form posts items[<index>][...], so every row needs its own
        // index. A row cloned from the last one arrives carrying that row's
        // index, which would silently overwrite it, so rows are renumbered
        // whenever the set of rows changes.
        function reindex() {
            Array.prototype.forEach.call(body.querySelectorAll('tr'), function (row, index) {
                row.querySelectorAll('[name^="items["]').forEach(function (field) {
                    field.name = field.name.replace(/^items\[[^\]]*\]/, 'items[' + index + ']');
                });
            });
        }

        // The batch decides the crop and the unit, so choosing a batch fills the
        // crop in. The server re-checks the pairing either way: this only saves
        // the officer from being told they are wrong after saving.
        function syncCrop(select) {
            var option = select.options[select.selectedIndex];
            if (!option) return;

            var row = select.closest('tr');
            var crop = row.querySelector('[data-item-crop]');
            if (!crop) return;

            var cropId = option.getAttribute('data-crop');
            if (cropId) crop.value = cropId;
        }

        function lineTotal(row) {
            var quantity = parseFloat(row.querySelector('[data-item-quantity]').value || '0');
            var price = parseFloat(row.querySelector('[data-item-price]').value || '0');
            var target = row.querySelector('[data-item-total]');

            if (target) {
                target.textContent = (isNaN(quantity) || isNaN(price) ? 0 : quantity * price)
                    .toLocaleString(undefined, { maximumFractionDigits: 0 });
            }
        }

        table.addEventListener('click', function (event) {
            // Removal is handled by app.js; reindexing happens after it, since
            // the click handler there runs first.
            if (event.target.closest('[data-remove-row]')) {
                setTimeout(reindex, 0);
            }
        });

        table.addEventListener('change', function (event) {
            if (event.target.matches('[data-item-batch]')) syncCrop(event.target);
        });

        table.addEventListener('input', function (event) {
            var row = event.target.closest('tr');
            if (!row) return;

            if (event.target.matches('[data-item-quantity]') || event.target.matches('[data-item-price]')) {
                lineTotal(row);
            }
        });

        // Cloned rows carry the previous row's line value until they are typed
        // into, which reads as a real figure. Clear it as soon as a row appears,
        // and renumber the rows so each posts under its own key.
        var observer = new MutationObserver(function (records) {
            var changed = false;

            records.forEach(function (record) {
                Array.prototype.forEach.call(record.addedNodes, function (node) {
                    if (node.tagName !== 'TR') return;
                    var target = node.querySelector('[data-item-total]');
                    if (target) target.textContent = '0';
                    changed = true;
                });
            });

            if (changed) reindex();
        });

        observer.observe(body, { childList: true });
    })();
    JS,
];