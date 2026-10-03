<?php
/**
 * User create / edit form.
 *
 * The username, the UUID and the password are deliberately absent from the edit
 * half: an identifier is issued once and never rewritten, and a password is
 * changed through the reset action so there is exactly one code path that can
 * set one.
 *
 * @var Core\View $view
 * @var array<string,mixed>|null $record
 * @var array<int,array{title:string,fields:array}> $sections
 */
$isEdit = $record !== null;
$action = $isEdit ? url('admin/users/' . (int) $record['id']) : url('admin/users');
?>
<div class="cahu-pagehead">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="cahu-pagehead__sub">
            <?= $isEdit
                ? 'Update the account. The username and the account identifier cannot be changed.'
                : 'Create an account. The account identifier is issued automatically when you save.' ?>
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('admin/users')) ?>">Back to users</a>
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
                            <?php $view->partial('partials/field', ['field' => $field, 'record' => $record]); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="cahu-card__foot cahu-form-actions">
            <a class="btn cahu-btn-outline" href="<?= e(url('admin/users')) ?>">Cancel</a>
            <button class="btn cahu-btn-primary" type="submit">
                <?= $isEdit ? 'Save changes' : 'Create user' ?>
            </button>
        </div>
    </div>
</form>

<?php if ($isEdit): ?>
    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div class="cahu-card h-100">
                <div class="cahu-card__head">Reset the password</div>
                <div class="cahu-card__body">
                    <p class="text-muted small">
                        Issues a temporary password, shown once and never stored in readable form. The account is signed
                        out everywhere, its lockout is cleared and any outstanding reset links are thrown away. The
                        holder must choose their own password at the next sign-in.
                    </p>

                    <dl class="cahu-kv mb-3">
                        <dt>Created</dt>
                        <dd><?= e(format_datetime($record['created_at'])) ?></dd>
                        <dt>Password changed</dt>
                        <dd><?= e(format_datetime($record['password_changed_at'] ?: null)) ?></dd>
                        <dt>Last sign-in</dt>
                        <dd><?= e(format_datetime($record['last_login_at'] ?: null)) ?></dd>
                        <dt>Failed attempts</dt>
                        <dd><?= e((int) $record['failed_attempts']) ?></dd>
                        <dt>Locked until</dt>
                        <dd>
                            <?php
                            $locked = !empty($record['locked_until']) && strtotime((string) $record['locked_until']) > time();
                            ?>
                            <?= e($locked ? format_datetime($record['locked_until']) : 'Not locked') ?>
                        </dd>
                    </dl>

                    <form method="post" action="<?= e(url('admin/users/' . (int) $record['id'] . '/reset-password')) ?>"
                          onsubmit="return confirm('Issue a temporary password for <?= e($record['username']) ?>? Every existing session is closed.');">
                        <?= csrf_field() ?>
                        <button class="btn cahu-btn-outline text-danger" type="submit">Issue temporary password</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="cahu-card h-100">
                <div class="cahu-card__head">Deactivate this account</div>
                <div class="cahu-card__body">
                    <p class="text-muted small">
                        The account is set to <span class="cahu-table__code">deactivated</span> and its sessions are
                        closed. Nothing is deleted: the record stays attached to everything this person recorded, which is
                        what makes the audit trail and the traceability chain trustworthy.
                    </p>

                    <form method="post" action="<?= e(url('admin/users/' . (int) $record['id'] . '/delete')) ?>"
                          onsubmit="return confirm('Deactivate <?= e($record['username']) ?>? Their sessions will be closed and their API tokens revoked.');">
                        <?= csrf_field() ?>
                        <button class="btn cahu-btn-outline text-danger" type="submit">Deactivate account</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>