<?php
/**
 * Role register.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $rows
 */
ob_start();
?>
<div class="table-responsive">
    <table class="table cahu-table align-middle mb-0">
        <thead>
        <tr>
            <th style="width:44px"></th>
            <th>Role</th>
            <th>Code</th>
            <th>Description</th>
            <th class="text-end">Users</th>
            <th class="text-end">Permissions</th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 7,
                'title'       => 'No roles yet',
                'message'     => 'Roles decide what an account can do. Create the first one on the settings screen.',
                'actionLabel' => '',
                'actionUrl'   => '',
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <?php if ((int) $row['is_system'] === 1): ?>
                            <span class="badge text-bg-primary" title="Seeded role, cannot be deleted">System</span>
                        <?php endif; ?>
                    </td>
                    <td class="fw-semibold"><?= e($row['name']) ?></td>
                    <td class="cahu-table__code"><?= e($row['code']) ?></td>
                    <td class="small text-muted"><?= e($row['description'] ?: '—') ?></td>
                    <td class="text-end">
                        <?php if ((int) $row['user_count'] > 0): ?>
                            <a class="text-decoration-none"
                               href="<?= url('admin/users?role_id=' . (int) $row['id']) ?>"><?= e((int) $row['user_count']) ?></a>
                        <?php else: ?>
                            <span class="text-muted">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end"><?= e((int) $row['permission_count']) ?></td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-outline" href="<?= url('admin/roles/' . (int) $row['id'] . '/edit') ?>">Edit</a>
                        <?php if ((int) $row['is_system'] === 0 && (int) $row['user_count'] === 0): ?>
                            <form method="post" action="<?= url('admin/roles/' . (int) $row['id'] . '/delete') ?>"
                                  onsubmit="return confirm('Delete the <?= e($row['name']) ?> role? The permissions it granted are removed with it.');">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-link text-danger text-decoration-none" type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="cahu-card mt-3">
    <div class="cahu-card__head">Add a role</div>
    <div class="cahu-card__body">
        <form method="post" action="<?= url('admin/roles') ?>" novalidate data-validate>
            <?= csrf_field() ?>

            <div class="row">
                <div class="col-12 col-md-4 mb-3">
                    <label class="form-label" for="role_name">Role name <span class="req">*</span></label>
                    <input class="<?= invalid_class('name') ?> form-control" type="text" id="role_name" name="name"
                           value="<?= e(old('name')) ?>" required maxlength="80" data-label="Role name">
                    <?php if (has_error('name')): ?>
                        <div class="invalid-feedback d-block"><?= e(error_for('name')) ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-12 col-md-4 mb-3">
                    <label class="form-label" for="role_code">Role code <span class="req">*</span></label>
                    <input class="<?= invalid_class('code') ?> form-control" type="text" id="role_code" name="code"
                           value="<?= e(old('code')) ?>" required maxlength="50" data-label="Role code">
                    <?php if (has_error('code')): ?>
                        <div class="invalid-feedback d-block"><?= e(error_for('code')) ?></div>
                    <?php else: ?>
                        <div class="form-text">Lower case letters, digits and underscores, e.g. field_officer.</div>
                    <?php endif; ?>
                </div>

                <div class="col-12 col-md-4 mb-3">
                    <label class="form-label" for="role_description">Description</label>
                    <input class="<?= invalid_class('description') ?> form-control" type="text" id="role_description"
                           name="description" value="<?= e(old('description')) ?>" maxlength="255">
                </div>
            </div>

            <button class="btn cahu-btn-primary" type="submit">Create role</button>
            <span class="form-text ms-2">Grant its permissions straight after it is created.</span>
        </form>
    </div>
</div>
<?php
$content = ob_get_clean();

$view->partial('partials/register', [
    'title'     => 'Roles',
    'subtitle'  => 'What each role may do. A user may hold several roles and gets the union of their permissions.',
    'filters'   => [],
    'clearUrl'  => url('admin/roles'),
    'content'   => $content,
]);