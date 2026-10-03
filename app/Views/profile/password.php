<?php
/**
 * Change password.
 *
 * The session in use and the account's recent sign-ins are shown alongside the
 * form, so a user who believes someone else has access to their account can
 * see it before they change anything.
 *
 * @var Core\View $view
 * @var array<string,mixed> $user
 * @var array<string,mixed>|null $session
 * @var array<int,array<string,mixed>> $sessions
 */
$currentSessionId = session_id();
?>
<div class="cahu-pagehead">
    <div>
        <h1>Change password</h1>
        <p class="cahu-pagehead__sub">
            Signed in as <span class="cahu-table__code"><?= e($user['username']) ?></span>.
            Changing your password does not sign you out of this device.
        </p>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('profile')) ?>">Back to my profile</a>
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

<div class="row g-3">
    <div class="col-12 col-xl-7">
        <div class="cahu-card">
            <div class="cahu-card__head">Choose a new password</div>
            <div class="cahu-card__body">
                <form method="post" action="<?= e(url('profile/password')) ?>" novalidate data-validate>
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label" for="current_password">Current password <span class="req">*</span></label>
                        <input class="<?= invalid_class('current_password') ?> form-control" type="password"
                               id="current_password" name="current_password" required autofocus
                               autocomplete="current-password" data-label="Current password">
                        <?php if (has_error('current_password')): ?>
                            <div class="invalid-feedback d-block"><?= e(error_for('current_password')) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="new_password">New password <span class="req">*</span></label>
                        <input class="<?= invalid_class('password') ?> form-control" type="password"
                               id="new_password" name="password" required minlength="8"
                               autocomplete="new-password" data-label="New password">
                        <?php if (has_error('password')): ?>
                            <div class="invalid-feedback d-block"><?= e(error_for('password')) ?></div>
                        <?php else: ?>
                            <div class="form-text">
                                At least 8 characters long, and it must contain both letters and numbers.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="password_confirmation">Repeat the new password <span class="req">*</span></label>
                        <input class="<?= invalid_class('password_confirmation') ?> form-control" type="password"
                               id="password_confirmation" name="password_confirmation" required
                               autocomplete="new-password" data-label="Repeat the new password">
                        <?php if (has_error('password_confirmation')): ?>
                            <div class="invalid-feedback d-block"><?= e(error_for('password_confirmation')) ?></div>
                        <?php endif; ?>
                    </div>

                    <button class="btn cahu-btn-primary" type="submit">Change my password</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-5">
        <div class="cahu-card mb-3">
            <div class="cahu-card__head">This session</div>
            <div class="cahu-card__body">
                <?php if ($session === null): ?>
                    <p class="text-muted small mb-0">
                        This browser session is not recorded in the sign-in history, so its address cannot be shown.
                    </p>
                <?php else: ?>
                    <dl class="cahu-kv mb-0">
                        <dt>Signed in from</dt>
                        <dd><?= e($session['ip_address'] ?: 'Not recorded') ?></dd>
                        <dt>Browser</dt>
                        <dd class="small"><?= e($session['user_agent'] ?: 'Not recorded') ?></dd>
                        <dt>Session started</dt>
                        <dd><?= e(format_datetime($session['created_at'])) ?></dd>
                        <dt>Last activity</dt>
                        <dd>
                            <?= e($session['last_activity'] > 0 ? format_datetime(date('Y-m-d H:i:s', (int) $session['last_activity'])) : 'Not recorded') ?>
                        </dd>
                    </dl>
                <?php endif; ?>
            </div>
        </div>

        <div class="cahu-card">
            <div class="cahu-card__head">Recent sign-ins</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <?php if ($sessions === []): ?>
                    <div class="cahu-empty">
                        <h3>No sign-ins recorded</h3>
                        <p>Sign-in history is not available for this account.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table cahu-table align-middle mb-0">
                            <thead>
                            <tr><th>From</th><th>Last activity</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($sessions as $entry): ?>
                                <tr>
                                    <td>
                                        <div><?= e($entry['ip_address'] ?: 'Not recorded') ?></div>
                                        <div class="text-muted small">
                                            <?= e(mb_strimwidth((string) ($entry['user_agent'] ?? 'Unknown browser'), 0, 48, '…')) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($entry['session_id'] === $currentSessionId): ?>
                                            <?= status_badge('active', 'This session') ?>
                                            <div class="text-muted small">
                                                <?= e(format_datetime(date('Y-m-d H:i:s', (int) $entry['last_activity']))) ?>
                                            </div>
                                        <?php else: ?>
                                            <?= e($entry['last_activity'] > 0 ? format_datetime(date('Y-m-d H:i:s', (int) $entry['last_activity'])) : '-') ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div class="cahu-card__foot">
                <p class="small text-muted mb-0">
                    If you do not recognise a sign-in, change your password and tell an administrator so the
                    account can be reviewed.
                </p>
            </div>
        </div>
    </div>
</div>
