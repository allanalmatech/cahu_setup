<?php
/**
 * Role edit form with the permission matrix.
 *
 * Permissions are grouped by module because that is how access is actually
 * granted: an administrator gives someone the farmer screens, not a hand-picked
 * list of codes.
 *
 * @var Core\View $view
 * @var array<string,mixed> $record
 * @var array<string,array<int,array<string,mixed>>> $permissionGroups
 * @var array<int,int> $selected
 */
$action = url('admin/roles/' . (int) $record['id']);
$fields = [
    ['name' => 'name', 'label' => 'Role name', 'type' => \Core\Form::TEXT, 'required' => true, 'maxlength' => 80],
    ['name' => 'code', 'label' => 'Role code', 'type' => \Core\Form::TEXT, 'required' => true, 'maxlength' => 50,
     'help' => 'Lower case letters, digits and underscores, e.g. field_officer. It is what the code checks for.'],
    ['name' => 'description', 'label' => 'Description', 'type' => \Core\Form::TEXTAREA, 'maxlength' => 255],
];
$granted = count($selected);
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="cahu-pagehead__sub">
            What accounts holding this role can reach. Changes take effect the next time a permission is checked.
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('admin/roles')) ?>">Back to roles</a>
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
        <div class="cahu-card__head">Role</div>
        <div class="cahu-card__body">
            <?php if ((int) $record['is_system'] === 1): ?>
                <div class="alert alert-info">
                    This is a system role. It backs the seeded access rules, so it can be edited but never deleted.
                </div>
            <?php endif; ?>

            <div class="row">
                <?php foreach ($fields as $field): ?>
                    <?php $view->partial('partials/field', ['field' => $field, 'record' => $record]); ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="cahu-card mb-3">
        <div class="cahu-card__head">Permissions</div>
        <div class="cahu-card__body">
            <?php if ($permissionGroups === []): ?>
                <p class="text-muted mb-0">No permissions have been defined for this installation yet.</p>
            <?php else: ?>
                <p class="text-muted small">
                    Tick everything this role should be allowed to do. Ticking nothing removes every permission from
                    the role, so an account holding it can sign in and see nothing.
                </p>

                <?php foreach ($permissionGroups as $module => $permissions): ?>
                    <div class="form-section">
                        <div class="form-section__title"><?= e(ucwords(str_replace('_', ' ', (string) $module))) ?></div>

                        <div class="row g-2">
                            <?php foreach ($permissions as $permission): ?>
                                <?php $isOn = in_array((int) $permission['id'], $selected, true); ?>
                                <div class="col-12 col-md-6 col-xl-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               id="perm_<?= (int) $permission['id'] ?>" name="permissions[]"
                                               value="<?= (int) $permission['id'] ?>"
                                               <?= $isOn ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="perm_<?= (int) $permission['id'] ?>">
                                            <?= e($permission['name']) ?>
                                            <span class="cahu-table__code"><?= e($permission['code']) ?></span>
                                        </label>
                                        <?php if (!empty($permission['description'])): ?>
                                            <div class="form-text"><?= e($permission['description']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="cahu-card__foot cahu-form-actions">
            <a class="btn cahu-btn-outline" href="<?= e(url('admin/roles')) ?>">Cancel</a>
            <button class="btn cahu-btn-primary" type="submit">Save role</button>
        </div>
    </div>
</form>

<div class="cahu-card">
    <div class="cahu-card__head">Delete this role</div>
    <div class="cahu-card__body">
        <?php if ((int) $record['is_system'] === 1): ?>
            <p class="text-muted mb-0">
                System roles cannot be deleted. They are what the permission middleware and the navigation fall back on,
                and removing one would lock every account that depends on it out of the platform.
            </p>
        <?php else: ?>
            <p class="text-muted">
                Deleting the role removes it from every account and throws away the
                <strong><?= e($granted) ?></strong> permission(s) it grants. Accounts that held it lose access
                immediately.
            </p>

            <form method="post" action="<?= e(url('admin/roles/' . (int) $record['id'] . '/delete')) ?>"
                  onsubmit="return confirm('Delete the <?= e($record['name']) ?> role?');">
                <?= csrf_field() ?>
                <button class="btn cahu-btn-outline text-danger" type="submit">Delete role</button>
            </form>
        <?php endif; ?>
    </div>
</div>