<h1>Reset your password</h1>
<p>Enter the email address or username linked to your account and we will send you a reset link.</p>

<?php if (!empty(Core\Flash::errors())): ?>
    <div class="alert alert-danger py-2 small"><?= e(reset(Core\Flash::errors())) ?></div>
<?php endif; ?>

<form action="<?= url('forgot-password') ?>" method="post" data-validate novalidate>
    <?= csrf_field() ?>

    <div class="mb-3">
        <label class="form-label" for="identity">Email or username <span class="req">*</span></label>
        <input class="<?= invalid_class('identity') ?>" type="text" id="identity" name="identity" required
               data-label="Email or username" autocomplete="username" autofocus
               value="<?= e(old('identity')) ?>">
        <?php if (has_error('identity')): ?>
            <div class="invalid-feedback d-block"><?= e(error_for('identity')) ?></div>
        <?php endif; ?>
    </div>

    <button class="btn cahu-btn-primary w-100 justify-content-center" type="submit">Send reset link</button>
</form>

<div class="cahu-auth__foot mb-0">
    <a href="<?= url('login') ?>">&larr; Back to sign in</a>
</div>
