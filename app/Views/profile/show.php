<?php
/**
 * The signed-in user's own profile: their details, the roles and records
 * attached to the account, and the API tokens the mobile app signs in with.
 *
 * @var Core\View $view
 * @var array<string,mixed> $user
 * @var string $fullName
 * @var array<int,array<string,mixed>> $roles
 * @var array<int,string> $permissions
 * @var array<string,mixed>|null $farmer
 * @var array<string,mixed>|null $officer
 * @var array<int,array<string,mixed>> $tokens
 * @var array<int,array{title:string,fields:array<int,array<string,mixed>>}> $sections
 */
?>
<div class="cahu-pagehead">
    <div class="d-flex align-items-center gap-3">
        <?php if (!empty($user['profile_photo'])): ?>
            <img class="cahu-avatar" style="width:56px;height:56px" src="<?= e($user['profile_photo']) ?>" alt="">
        <?php else: ?>
            <span class="cahu-avatar" style="width:56px;height:56px;font-size:1.5rem">
                <?= e(strtoupper(substr((string) $user['first_name'], 0, 1))) ?>
            </span>
        <?php endif; ?>
        <div>
            <h1 class="mb-0"><?= e($fullName) ?></h1>
            <p class="cahu-pagehead__sub mb-0">
                <span class="cahu-table__code"><?= e($user['username']) ?></span>
                &middot; <?= status_badge((string) $user['status']) ?>
            </p>
        </div>
    </div>
    <div class="cahu-pagehead__actions">
        <a class="btn cahu-btn-outline" href="<?= e(url('profile/password')) ?>">Change password</a>
    </div>
</div>

<?php if ((int) $user['must_change_password'] === 1): ?>
    <div class="alert alert-warning">
        Your password was set by an administrator. Please
        <a href="<?= e(url('profile/password')) ?>">choose your own</a> now.
    </div>
<?php endif; ?>

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
    <div class="col-12 col-xl-4">
        <div class="cahu-card mb-3">
            <div class="cahu-card__head">Account</div>
            <div class="cahu-card__body">
                <dl class="cahu-kv mb-0">
                    <dt>Full name</dt>
                    <dd><?= e($fullName) ?></dd>
                    <dt>Username</dt>
                    <dd><?= e($user['username']) ?></dd>
                    <dt>Email</dt>
                    <dd><?= e($user['email']) ?></dd>
                    <dt>Phone</dt>
                    <dd><?= e($user['phone'] ?: 'Not given') ?></dd>
                    <dt>Status</dt>
                    <dd><?= status_badge((string) $user['status']) ?></dd>
                    <dt>Last signed in</dt>
                    <dd><?= e(format_datetime($user['last_login_at'])) ?></dd>
                    <dt>From address</dt>
                    <dd><?= e($user['last_login_ip'] ?: 'Not recorded') ?></dd>
                    <dt>Password changed</dt>
                    <dd><?= e(format_datetime($user['password_changed_at'])) ?></dd>
                    <dt>Account created</dt>
                    <dd><?= e(format_date($user['created_at'])) ?></dd>
                </dl>
            </div>
        </div>

        <div class="cahu-card mb-3">
            <div class="cahu-card__head">What you can do</div>
            <div class="cahu-card__body">
                <?php if ($roles === []): ?>
                    <p class="text-muted small mb-0">
                        No role has been assigned to this account yet, so it cannot open any working screen.
                    </p>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <?php foreach ($roles as $role): ?>
                            <span class="cahu-chip"><?= e($role['name']) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-muted small mb-1">
                        <?= e(count($permissions)) ?> permission(s) granted
                    </div>
                    <div class="d-flex flex-wrap gap-1">
                        <?php if (in_array('*', $permissions, true)): ?>
                            <span class="badge text-bg-primary">Everything</span>
                        <?php else: ?>
                            <?php foreach ($permissions as $permission): ?>
                                <span class="badge text-bg-light text-dark"><?= e($permission) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($farmer !== null): ?>
            <div class="cahu-card mb-3">
                <div class="cahu-card__head">Your farmer record</div>
                <div class="cahu-card__body">
                    <dl class="cahu-kv mb-3">
                        <dt>Farmer ID</dt>
                        <dd><?= e($farmer['farmer_code']) ?></dd>
                        <dt>Name</dt>
                        <dd><?= e($farmer['full_name']) ?></dd>
                        <dt>Phone</dt>
                        <dd><?= e($farmer['phone']) ?></dd>
                        <dt>Status</dt>
                        <dd><?= status_badge((string) $farmer['status']) ?></dd>
                        <dt>Gardens</dt>
                        <dd><?= e((int) $farmer['garden_count']) ?></dd>
                        <dt>Plantings</dt>
                        <dd><?= e((int) $farmer['planting_count']) ?></dd>
                    </dl>
                    <a class="btn cahu-btn-outline w-100" href="<?= e(url('farmers/' . $farmer['farmer_code'])) ?>">
                        Open my farmer record
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($officer !== null): ?>
            <div class="cahu-card">
                <div class="cahu-card__head">Your field officer record</div>
                <div class="cahu-card__body">
                    <dl class="cahu-kv mb-3">
                        <dt>Officer ID</dt>
                        <dd><?= e($officer['officer_code']) ?></dd>
                        <dt>Name</dt>
                        <dd><?= e($officer['full_name']) ?></dd>
                        <dt>Employee number</dt>
                        <dd><?= e($officer['employee_no'] ?: 'Not given') ?></dd>
                        <dt>Area</dt>
                        <dd><?= e(trim(((string) ($officer['district_name'] ?? '')) . ' ' . ((string) ($officer['region_name'] ?? '')))) ?: 'Not set' ?></dd>
                        <dt>Status</dt>
                        <dd><?= status_badge((string) $officer['status']) ?></dd>
                        <dt>Assignments</dt>
                        <dd><?= e((int) $officer['assignment_count']) ?></dd>
                    </dl>
                    <a class="btn cahu-btn-outline w-100" href="<?= e(url('officers/' . $officer['id'])) ?>">
                        Open my officer record
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-12 col-xl-8">
        <div class="cahu-card mb-3">
            <div class="cahu-card__head">Your details</div>
            <div class="cahu-card__body">
                <p class="text-muted small">
                    Correct anything that is wrong here. Your username, your role and what you can see are
                    issued by an administrator, so they are not editable from this screen.
                </p>

                <form method="post" action="<?= e(url('profile')) ?>" novalidate data-validate>
                    <?= csrf_field() ?>

                    <div class="row">
                        <?php foreach ($sections as $section): ?>
                            <?php foreach ($section['fields'] as $field): ?>
                                <?php $view->partial('partials/field', ['field' => $field, 'record' => $user]); ?>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>

                    <button class="btn cahu-btn-primary" type="submit">Save my details</button>
                </form>
            </div>
        </div>

        <div class="cahu-card mb-3">
            <div class="cahu-card__head">API tokens</div>
            <div class="cahu-card__body cahu-card__body--flush">
                <?php if ($tokens === []): ?>
                    <div class="cahu-empty">
                        <div class="cahu-empty__icon">
                            <svg viewBox="0 0 24 24" width="42" height="42" fill="none" stroke="currentColor" stroke-width="1.4"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 018 0v3"/>
                            </svg>
                        </div>
                        <h3>No tokens issued</h3>
                        <p>
                            Nothing has been set up for this account to use the mobile application.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table cahu-table align-middle mb-0">
                            <thead>
                            <tr>
                                <th>Token</th>
                                <th>Device</th>
                                <th>Issued</th>
                                <th>Last used</th>
                                <th>Expires</th>
                                <th>State</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($tokens as $token): ?>
                                <?php
                                $expired = $token['expires_at'] !== null && strtotime((string) $token['expires_at']) < time();
                                $state   = $token['revoked_at'] !== null ? 'revoked' : ($expired ? 'expired' : 'active');
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= e($token['name']) ?></div>
                                        <?php if (!empty($token['scopes'])): ?>
                                            <div class="text-muted small"><?= e($token['scopes']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?= e($token['device_info'] ?: 'Not recorded') ?></td>
                                    <td><?= e(format_date($token['created_at'])) ?></td>
                                    <td><?= e($token['last_used_at'] !== null ? format_datetime($token['last_used_at']) : 'Never used') ?></td>
                                    <td><?= e(format_date($token['expires_at'])) ?></td>
                                    <td><?= status_badge($state) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div class="cahu-card__foot">
                <p class="small text-muted mb-0">
                    API tokens are issued and revoked by an administrator, so they are listed here for
                    information only. If you no longer use a device, ask an administrator to revoke its token.
                    The mobile application signs in with <span class="cahu-table__code">POST /api/v1/auth/login</span>
                    and holds the token it is given.
                </p>
            </div>
        </div>

        <div class="cahu-card">
            <div class="cahu-card__head">Change your password</div>
            <div class="cahu-card__body">
                <form method="post" action="<?= e(url('profile/password')) ?>" novalidate data-validate>
                    <?= csrf_field() ?>

                    <div class="row">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="current_password">Current password <span class="req">*</span></label>
                            <input class="<?= invalid_class('current_password') ?> form-control" type="password"
                                   id="current_password" name="current_password" required autocomplete="current-password"
                                   data-label="Current password">
                            <?php if (has_error('current_password')): ?>
                                <div class="invalid-feedback d-block"><?= e(error_for('current_password')) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label" for="new_password">New password <span class="req">*</span></label>
                            <input class="<?= invalid_class('password') ?> form-control" type="password"
                                   id="new_password" name="password" required autocomplete="new-password"
                                   minlength="8" data-label="New password">
                            <?php if (has_error('password')): ?>
                                <div class="invalid-feedback d-block"><?= e(error_for('password')) ?></div>
                            <?php else: ?>
                                <div class="form-text">At least 8 characters, with both letters and numbers.</div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label" for="password_confirmation">Repeat new password <span class="req">*</span></label>
                            <input class="<?= invalid_class('password_confirmation') ?> form-control" type="password"
                                   id="password_confirmation" name="password_confirmation" required
                                   autocomplete="new-password" data-label="Repeat new password">
                            <?php if (has_error('password_confirmation')): ?>
                                <div class="invalid-feedback d-block"><?= e(error_for('password_confirmation')) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <button class="btn cahu-btn-primary" type="submit">Change password</button>
                </form>
            </div>
        </div>
    </div>
</div>
