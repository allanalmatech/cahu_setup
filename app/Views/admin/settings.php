<?php
/**
 * System settings, grouped the way they are maintained.
 *
 * A row flagged as a secret is never sent to this page: the query behind it
 * replaces the value with NULL, and the control renders as an empty password
 * box. Leaving it blank therefore keeps the stored value, which is the only
 * sensible meaning for a box an administrator cannot read.
 *
 * A row the application does not read from this table renders read-only, with a
 * note saying where the live value comes from. The controller decides which
 * those are, so this template never has to guess.
 *
 * @var Core\View $view
 * @var array<string,array<int,array<string,mixed>>> $groups
 */
?>
<div class="cahu-pagehead">
    <div>
        <h1>Settings</h1>
        <p class="cahu-pagehead__sub">
            Runtime configuration for this installation. Only the names listed here can be changed, and each change is
            recorded in the audit log. Entries marked read-only are configured elsewhere and are shown for reference.
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('admin/reference')) ?>">Reference data</a>
        <a class="btn cahu-btn-outline" href="<?= e(url('admin/audit')) ?>">Audit log</a>
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

<?php if ($groups === []): ?>
    <div class="cahu-card">
        <div class="cahu-card__body">
            <?= $view->partial('partials/empty', [
                'columns'     => 1,
                'title'       => 'No settings defined',
                'message'     => 'This installation has no rows in system_settings yet, so there is nothing to configure here.',
                'actionLabel' => '',
                'actionUrl'   => '',
            ]) ?>
        </div>
    </div>
<?php else: ?>
    <form method="post" action="<?= e(url('admin/settings')) ?>" novalidate data-validate>
        <?= csrf_field() ?>

        <?php foreach ($groups as $group => $rows): ?>
            <div class="cahu-card mb-3">
                <div class="cahu-card__head"><?= e(ucwords(str_replace('_', ' ', (string) $group))) ?></div>
                <div class="cahu-card__body">
                    <div class="row">
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $key      = (string) $row['setting_key'];
                            $inputId  = 'setting_' . preg_replace('/[^a-z0-9]+/i', '_', $key);
                            $secret   = (int) $row['is_secret'] === 1;
                            $editable = (bool) ($row['editable'] ?? false);
                            $value    = (string) ($row['setting_value'] ?? '');
                            ?>
                            <div class="col-12 col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" for="<?= e($inputId) ?>">
                                        <?= e($key) ?>
                                        <?php if ($secret && $editable): ?>
                                            <span class="badge text-bg-warning">Secret</span>
                                        <?php elseif (!$editable): ?>
                                            <span class="badge text-bg-secondary">Read-only</span>
                                        <?php endif; ?>
                                    </label>

                                    <?php if (!$editable): ?>
                                        <?php /* No name attribute: the row cannot be submitted, even by a crafted POST. */ ?>
                                        <?php if ($secret): ?>
                                            <input class="form-control" type="text" id="<?= e($inputId) ?>"
                                                   value="Not shown" disabled readonly>
                                        <?php else: ?>
                                            <input class="form-control" type="text" id="<?= e($inputId) ?>"
                                                   value="<?= e($value) ?>" disabled readonly>
                                        <?php endif; ?>
                                    <?php elseif ($secret): ?>
                                        <?php /* The stored value is deliberately never rendered, not even masked. */ ?>
                                        <input class="<?= invalid_class('settings') ?> form-control" type="password"
                                               id="<?= e($inputId) ?>" name="settings[<?= e($key) ?>]"
                                               value="" autocomplete="new-password"
                                               placeholder="Not shown. Type only to replace it.">
                                    <?php elseif ($row['control'] === 'toggle'): ?>
                                        <select class="form-select" id="<?= e($inputId) ?>"
                                                name="settings[<?= e($key) ?>]">
                                            <?php foreach (['0' => 'No', '1' => 'Yes'] as $optionValue => $optionLabel): ?>
                                                <option value="<?= e($optionValue) ?>"
                                                    <?= $value === $optionValue ? 'selected' : '' ?>>
                                                    <?= e($optionLabel) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($row['control'] === 'textarea'): ?>
                                        <textarea class="form-control" id="<?= e($inputId) ?>"
                                                  name="settings[<?= e($key) ?>]" rows="3"><?= e($value) ?></textarea>
                                    <?php else: ?>
                                        <input class="form-control" type="text" id="<?= e($inputId) ?>"
                                               name="settings[<?= e($key) ?>]" value="<?= e($value) ?>">
                                    <?php endif; ?>

                                    <?php if (!empty($row['description'])): ?>
                                        <div class="form-text"><?= e($row['description']) ?></div>
                                    <?php endif; ?>

                                    <?php if (!empty($row['note'])): ?>
                                        <div class="form-text fst-italic"><?= e($row['note']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="cahu-card">
            <div class="cahu-card__foot cahu-form-actions">
                <span class="text-muted small">
                    Saving writes every group at once. A secret left blank is kept as it is.
                </span>
                <button class="btn cahu-btn-primary" type="submit">Save settings</button>
            </div>
        </div>
    </form>
<?php endif; ?>