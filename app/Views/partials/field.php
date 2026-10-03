<?php
/**
 * Renders a single form control from a field definition.
 *
 * @var array<string,mixed> $field
 * @var array<string,mixed>|null $record
 */
$name     = (string) $field['name'];
$label    = (string) ($field['label'] ?? ucfirst(str_replace('_', ' ', $name)));
$type     = (string) ($field['type'] ?? Core\Form::TEXT);
$required = (bool) ($field['required'] ?? false);
$help     = (string) ($field['help'] ?? '');
$value    = Core\Form::value($field, $record ?? null);
$id       = (string) ($field['id'] ?? 'f_' . $name);
$col      = (int) ($field['colspan'] ?? 12);
$invalid  = has_error($name);
?>
<div class="col-12 col-md-<?= $col ?>">
    <?php if ($type === Core\Form::HIDDEN): ?>
        <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">

    <?php elseif ($type === Core\Form::STATIC): ?>
        <div class="mb-3">
            <label class="form-label" for="<?= e($id) ?>"><?= e($label) ?></label>
            <div class="cahu-chip"><?= e($value === '' || $value === null ? '-' : $value) ?></div>
        </div>

    <?php elseif ($type === Core\Form::CHECKBOX): ?>
        <div class="mb-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="<?= e($id) ?>"
                       name="<?= e($name) ?>" value="1" <?= $value ? 'checked' : '' ?>>
                <label class="form-check-label" for="<?= e($id) ?>"><?= e($label) ?></label>
            </div>
            <?php if ($help !== ''): ?>
                <div class="form-text"><?= e($help) ?></div>
            <?php endif; ?>
        </div>

    <?php elseif ($type === Core\Form::GPS): ?>
        <div class="mb-3">
            <label class="form-label"><?= e($label) ?></label>
            <div class="row g-2">
                <div class="col-5">
                    <input class="<?= invalid_class($name . '_lat') ?> form-control" type="number" step="0.0000001"
                           id="<?= e($id) ?>_lat" name="<?= e($name) ?>_lat" placeholder="Latitude"
                           value="<?= e($value === '' || $value === null ? '' : round((float) $value, 7)) ?>">
                </div>
                <div class="col-5">
                    <input class="<?= invalid_class($name . '_lng') ?> form-control" type="number" step="0.0000001"
                           id="<?= e($id) ?>_lng" name="<?= e($name) ?>_lng" placeholder="Longitude"
                           value="<?= e($gpsLng ?? '') ?>">
                </div>
                <div class="col-2 d-grid">
                    <button class="btn cahu-btn-outline" type="button" title="Capture GPS from this device"
                            onclick="Cahu.locate('<?= e($id) ?>_lat','<?= e($id) ?>_lng','<?= e($id) ?>_status')">
                        GPS
                    </button>
                </div>
            </div>
            <div class="gps-status mt-1" id="<?= e($id) ?>_status">
                <?= $help !== '' ? e($help) : 'Enter coordinates manually or capture them on this device.' ?>
            </div>
        </div>

    <?php elseif ($type === Core\Form::SELECT || $type === Core\Form::MULTI): ?>
        <div class="mb-3">
            <label class="form-label" for="<?= e($id) ?>">
                <?= e($label) ?><?= $required ? ' <span class="req">*</span>' : '' ?>
            </label>
            <select class="<?= invalid_class($name) ?> form-select" id="<?= e($id) ?>" name="<?= e($name) ?><?= $type === Core\Form::MULTI ? '[]' : '' ?>"
                    <?= $required ? 'required' : '' ?>
                    <?= !empty($field['cascade']) ? 'data-cascade="' . e($field['cascade']) . '"' : '' ?>>
                <?php if ($type === Core\Form::MULTI): ?>
                    <?php foreach (($field['options'] ?? []) as $option): ?>
                        <option value="<?= e($option['value']) ?>"
                            <?= in_array((string) $option['value'], array_map('strval', (array) $value), true) ? 'selected' : '' ?>>
                            <?= e($option['label']) ?>
                        </option>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach (($field['options'] ?? []) as $option): ?>
                        <option value="<?= e($option['value']) ?>" <?= (string) $value === (string) $option['value'] ? 'selected' : '' ?>>
                            <?= e($option['label']) ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <?php if ($invalid): ?>
                <div class="invalid-feedback d-block"><?= e(error_for($name)) ?></div>
            <?php elseif ($help !== ''): ?>
                <div class="form-text"><?= e($help) ?></div>
            <?php endif; ?>
        </div>

    <?php elseif ($type === Core\Form::TEXTAREA): ?>
        <div class="mb-3">
            <label class="form-label" for="<?= e($id) ?>">
                <?= e($label) ?><?= $required ? ' <span class="req">*</span>' : '' ?>
            </label>
            <textarea class="<?= invalid_class($name) ?> form-control" id="<?= e($id) ?>" name="<?= e($name) ?>"
                      rows="<?= (int) ($field['rows'] ?? 3) ?>" <?= $required ? 'required' : '' ?>
                      placeholder="<?= e($field['placeholder'] ?? '') ?>"><?= e($value) ?></textarea>
            <?php if ($invalid): ?>
                <div class="invalid-feedback d-block"><?= e(error_for($name)) ?></div>
            <?php elseif ($help !== ''): ?>
                <div class="form-text"><?= e($help) ?></div>
            <?php endif; ?>
        </div>

    <?php elseif ($type === Core\Form::FILE): ?>
        <?php
        // A file control's current file is whatever the record already holds, so
        // the same definition works for create and edit without extra plumbing.
        $existing = (string) ($field['existing'] ?? (is_scalar($value) ? (string) $value : ''));
        ?>
        <div class="mb-3">
            <label class="form-label" for="<?= e($id) ?>"><?= e($label) ?></label>
            <input class="form-control" type="file" id="<?= e($id) ?>" name="<?= e($name) ?>"
                   accept="<?= e($field['accept'] ?? 'image/*') ?>">
            <?php if ($existing !== ''): ?>
                <div class="form-text">
                    Current file:
                    <a href="<?= e($existing) ?>" target="_blank" rel="noopener">
                        <?= ($field['filetype'] ?? '') === 'image' ? 'view' : 'download' ?>
                    </a>
                    <?php if (!empty($field['remove'])): ?>
                        <label class="form-check-inline ms-2">
                            <input class="form-check-input" type="checkbox" name="<?= e($field['remove']) ?>" value="1">
                            remove
                        </label>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($help !== ''): ?>
                <div class="form-text"><?= e($help) ?></div>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <?php
        $inputType = match ($type) {
            Core\Form::NUMBER, Core\Form::DATE, Core\Form::DATETIME, Core\Form::MONTH,
            Core\Form::EMAIL, Core\Form::TEL, Core\Form::PASSWORD => $type,
            default => 'text',
        };
        $attributes = '';
        foreach (['min', 'max', 'step', 'maxlength'] as $attribute) {
            if (isset($field[$attribute])) {
                $attributes .= sprintf(' %s="%s"', $attribute, e((string) $field[$attribute]));
            }
        }
        if (!empty($field['readonly'])) {
            $attributes .= ' readonly';
        }
        ?>
        <div class="mb-3">
            <label class="form-label" for="<?= e($id) ?>">
                <?= e($label) ?><?= $required ? ' <span class="req">*</span>' : '' ?>
            </label>
            <input class="<?= invalid_class($name) ?> form-control" type="<?= e($inputType) ?>" id="<?= e($id) ?>"
                   name="<?= e($name) ?>" value="<?= e($value) ?>"
                   <?= $required ? 'required' : '' ?>
                   data-label="<?= e($label) ?>"
                   placeholder="<?= e($field['placeholder'] ?? '') ?>"<?= $attributes ?>>
            <?php if ($invalid): ?>
                <div class="invalid-feedback d-block"><?= e(error_for($name)) ?></div>
            <?php elseif ($help !== ''): ?>
                <div class="form-text"><?= e($help) ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
