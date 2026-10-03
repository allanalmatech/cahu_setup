<h1>Choose a new password</h1>
<p>Your reset link is valid for a limited time. Pick a password you have not used before.</p>

<?php if (!empty(Core\Flash::errors())): ?>
    <div class="alert alert-danger py-2 small"><?= e(reset(Core\Flash::errors())) ?></div>
<?php endif; ?>

<form action="<?= url('reset-password/' . e($token)) ?>" method="post" data-validate novalidate>
    <?= csrf_field() ?>

    <div class="mb-3">
        <label class="form-label" for="password">New password <span class="req">*</span></label>
        <div class="password-field">
            <input class="<?= invalid_class('password') ?>" type="password" id="password" name="password" required
                   data-label="Password" minlength="8" autocomplete="new-password">
            <button class="toggle" type="button">Show</button>
        </div>
        <div class="form-text">At least 8 characters, including letters and numbers.</div>
        <?php if (has_error('password')): ?>
            <div class="invalid-feedback d-block"><?= e(error_for('password')) ?></div>
        <?php endif; ?>
    </div>

    <div class="mb-3">
        <label class="form-label" for="password_confirmation">Confirm new password <span class="req">*</span></label>
        <div class="password-field">
            <input class="<?= invalid_class('password_confirmation') ?>" type="password" id="password_confirmation"
                   name="password_confirmation" required data-label="Password confirmation" autocomplete="new-password">
            <button class="toggle" type="button">Show</button>
        </div>
        <?php if (has_error('password_confirmation')): ?>
            <div class="invalid-feedback d-block"><?= e(error_for('password_confirmation')) ?></div>
        <?php endif; ?>
    </div>

    <button class="btn cahu-btn-primary w-100 justify-content-center" type="submit">Update password</button>
</form>
