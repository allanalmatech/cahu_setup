<?php
/**
 * User register: every account that can sign in, the roles it holds and how its
 * last sign-in went.
 *
 * @var Core\View $view
 * @var array<int,array<string,mixed>> $rows
 * @var Core\Paginator $paginator
 * @var string $sort
 * @var string $direction
 * @var array<int,array<string,mixed>> $filters
 */
ob_start();
?>
<div class="table-responsive">
    <table class="table cahu-table align-middle mb-0">
        <thead>
        <tr>
            <th style="width:64px">User</th>
            <th><?= $view->partial('partials/sort', ['column' => 'username', 'label' => 'Username', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th>Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Roles</th>
            <th>Status</th>
            <th><?= $view->partial('partials/sort', ['column' => 'last_login_at', 'label' => 'Last sign-in', 'currentSort' => $sort, 'currentDirection' => $direction]) ?></th>
            <th class="text-end">Failed</th>
            <th class="text-end">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <?= $view->partial('partials/empty', [
                'columns'     => 10,
                'title'       => 'No user accounts found',
                'message'     => 'Adjust the filters, or add the first account for this deployment.',
                'actionLabel' => 'Add user',
                'actionUrl'   => url('admin/users/create'),
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <?php $fullName = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']); ?>
                <tr>
                    <td>
                        <?php if (!empty($row['profile_photo'])): ?>
                            <img class="cahu-avatar" src="<?= e($row['profile_photo']) ?>" alt="" width="34" height="34">
                        <?php else: ?>
                            <span class="cahu-avatar"><?= e(strtoupper(substr((string) $row['first_name'], 0, 1))) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="cahu-table__code"><?= e($row['username']) ?></td>
                    <td>
                        <div class="fw-semibold"><?= e($fullName !== '' ? $fullName : '—') ?></div>
                        <?php if ((int) $row['must_change_password'] === 1): ?>
                            <div class="text-muted small">Must change password</div>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= e($row['email']) ?></td>
                    <td class="small"><?= e($row['phone'] ?: '—') ?></td>
                    <td>
                        <?php if ((string) $row['role_names'] === ''): ?>
                            <span class="text-muted small">No role</span>
                        <?php else: ?>
                            <?php foreach (explode(', ', (string) $row['role_names']) as $roleName): ?>
                                <span class="cahu-chip"><?= e($roleName) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td><?= status_badge((string) $row['status']) ?></td>
                    <td class="small">
                        <div><?= e(format_datetime($row['last_login_at'])) ?></div>
                        <div class="text-muted"><?= e($row['last_login_ip'] ?: '—') ?></div>
                    </td>
                    <td class="text-end">
                        <?php if ((int) $row['failed_attempts'] > 0): ?>
                            <span class="badge text-bg-warning"><?= e((int) $row['failed_attempts']) ?></span>
                            <?php if (!empty($row['locked_until']) && strtotime((string) $row['locked_until']) > time()): ?>
                                <div class="text-muted small">Locked</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">&mdash;</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end cahu-table__actions">
                        <a class="btn btn-sm cahu-btn-outline" href="<?= url('admin/users/' . (int) $row['id'] . '/edit') ?>">Edit</a>
                        <form method="post" action="<?= url('admin/users/' . (int) $row['id'] . '/delete') ?>"
                              onsubmit="return confirm('Deactivate <?= e($row['username']) ?>? Their sessions will be closed and their API tokens revoked. The record is kept.');">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm btn-link text-danger text-decoration-none" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php
$content = ob_get_clean();

$view->partial('partials/register', [
    'title'             => 'Users',
    'subtitle'          => 'Every account that can sign in, what it may do, and how its last sign-in went.',
    'actions'           => [
        ['label' => 'Roles and permissions', 'url' => url('admin/roles'), 'icon' => '', 'style' => 'cahu-btn-outline'],
        ['label' => 'Add user', 'url' => url('admin/users/create'), 'icon' => '+', 'style' => 'cahu-btn-primary'],
    ],
    'filters'           => $filters,
    'searchPlaceholder' => 'Search by username, name, email or phone...',
    'clearUrl'          => url('admin/users'),
    'paginator'         => $paginator,
    'content'           => $content,
]);