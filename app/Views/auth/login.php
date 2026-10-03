<?php
/**
 * Sign-in screen.
 *
 * @var string $appShort
 * @var array<string,string> $errors  First error is shown as the alert; the rest
 *                                    attach themselves to the field they belong to.
 */
$errors = $_SESSION['_errors'] ?? [];
unset($_SESSION['_errors']);
?>
<div class="text-center mb-4 d-lg-none">
    <div class="cahu-brandmark mx-auto mb-2" style="width:46px;height:46px">CA</div>
    <strong><?= e($appShort) ?> Agribusiness Platform</strong>
</div>

<h1>Sign in</h1>
<p>Enter your credentials to access the platform.</p>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger py-2 small">
        <?= e(reset($errors)) ?>
    </div>
<?php endif; ?>

<form action="<?= url('login') ?>" method="post" data-validate novalidate>
    <?= csrf_field() ?>

    <div class="mb-3">
        <label class="form-label" for="login">Username or email <span class="req">*</span></label>
        <input class="<?= invalid_class('login') ?>" type="text" id="login" name="login" required
               data-label="Username" autocomplete="username" autofocus
               value="<?= e(old('login')) ?>">
        <?php if (has_error('login')): ?>
            <div class="invalid-feedback d-block"><?= e(error_for('login')) ?></div>
        <?php endif; ?>
    </div>

    <div class="mb-3">
        <label class="form-label" for="password">Password <span class="req">*</span></label>
        <div class="password-field">
            <input class="<?= invalid_class('password') ?>" type="password" id="password" name="password" required
                   data-label="Password" autocomplete="current-password">
            <button class="toggle" type="button">Show</button>
        </div>
        <?php if (has_error('password')): ?>
            <div class="invalid-feedback d-block"><?= e(error_for('password')) ?></div>
        <?php endif; ?>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
            <label class="form-check-label small" for="remember">Keep me signed in</label>
        </div>
        <a class="small" href="<?= url('forgot-password') ?>">Forgot password?</a>
    </div>

    <button class="btn cahu-btn-primary w-100 justify-content-center" type="submit">Sign in</button>
</form>

<div class="cahu-auth__foot">
    New to the cooperative? <a href="<?= url('register') ?>">Create an account</a>.
</div>
