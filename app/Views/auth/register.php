<?php
/**
 * Public self-registration.
 *
 * The copy on this page is deliberate: a new account has no roles, so the form
 * cannot promise a dashboard. It says an officer assigns the role, which is
 * what actually happens -- see AuthApiController / RegisterController.
 *
 * @var string $appShort
 * @var array<string,string> $errors
 */
$errors = $_SESSION['_errors'] ?? [];
unset($_SESSION['_errors']);
?>
<div class="text-center mb-4 d-lg-none">
    <div class="cahu-brandmark mx-auto mb-2" style="width:46px;height:46px">CA</div>
    <strong><?= e($appShort) ?> Agribusiness Platform</strong>
</div>

<h1>Create an account</h1>
<p>Registering gives you a record on the cooperative platform. An officer assigns your role after signup.</p>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger py-2 small">
        <?= e(reset($errors)) ?>
    </div>
<?php endif; ?>

<form action="<?= url('register') ?>" method="post" data-validate novalidate>
    <?= csrf_field() ?>

    <div class="row">
        <div class="col-12 col-sm-6">
            <label class="form-label" for="first_name">First name <span class="req">*</span></label>
            <input class="<?= invalid_class('first_name') ?>" type="text" id="first_name" name="first_name" required
                   data-label="First name" autocomplete="given-name" autofocus
                   value="<?= e(old('first_name')) ?>">
            <?php if (has_error('first_name')): ?>
                <div class="invalid-feedback d-block"><?= e(error_for('first_name')) ?></div>
            <?php endif; ?>
        </div>

        <div class="col-12 col-sm-6">
            <label class="form-label" for="last_name">Last name <span class="req">*</span></label>
            <input class="<?= invalid_class('last_name') ?>" type="text" id="last_name" name="last_name" required
                   data-label="Last name" autocomplete="family-name"
                   value="<?= e(old('last_name')) ?>">
            <?php if (has_error('last_name')): ?>
                <div class="invalid-feedback d-block"><?= e(error_for('last_name')) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="mb-3 mt-3">
        <label class="form-label" for="email">Email address <span class="req">*</span></label>
        <input class="<?= invalid_class('email') ?>" type="email" id="email" name="email" required
               data-label="Email address" autocomplete="email"
               value="<?= e(old('email')) ?>">
        <?php if (has_error('email')): ?>
            <div class="invalid-feedback d-block"><?= e(error_for('email')) ?></div>
        <?php endif; ?>
    </div>

    <div class="mb-3">
        <label class="form-label" for="password">Password <span class="req">*</span></label>
        <div class="password-field">
            <input class="<?= invalid_class('password') ?>" type="password" id="password" name="password" required
                   data-label="Password" autocomplete="new-password"
                   minlength="8">
            <button class="toggle" type="button">Show</button>
        </div>
        <div class="form-text">At least 8 characters, with both letters and numbers.</div>
        <?php if (has_error('password')): ?>
            <div class="invalid-feedback d-block"><?= e(error_for('password')) ?></div>
        <?php endif; ?>
    </div>

    <div class="mb-3">
        <label class="form-label" for="password_confirmation">Confirm password <span class="req">*</span></label>
        <div class="password-field">
            <input class="<?= invalid_class('password_confirmation') ?>" type="password"
                   id="password_confirmation" name="password_confirmation" required
                   data-label="Password confirmation" autocomplete="new-password"
                   minlength="8">
            <button class="toggle" type="button">Show</button>
        </div>
        <?php if (has_error('password_confirmation')): ?>
            <div class="invalid-feedback d-block"><?= e(error_for('password_confirmation')) ?></div>
        <?php endif; ?>
    </div>

    <button class="btn cahu-btn-primary w-100 justify-content-center" type="submit">Create account</button>
</form>

<div class="cahu-auth__foot">
    Already registered? <a href="<?= url('login') ?>">Sign in instead</a>.
</div>