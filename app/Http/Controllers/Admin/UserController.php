<?php
declare(strict_types=1);

namespace Http\Controllers\Admin;

use Core\Audit;
use Core\Auth;
use Core\Controller;
use Core\Csrf;
use Core\Flash;
use Core\Form;
use Core\HttpException;
use Core\Paginator;
use Core\Response;

/**
 * Administration of the accounts that can sign in to the platform. A user is
 * never hard deleted: deactivating an account keeps the identity attached to
 * everything it did, so the audit trail, the production history and the
 * traceability chain stay readable long after the person has left.
 *
 * Passwords are only ever written here, never read back: the create form takes
 * one, the edit form deliberately does not, and the reset action mints a
 * temporary password that is shown exactly once and never written to the audit
 * trail.
 */
final class UserController extends Controller
{
    private const TEMPORARY_PASSWORD_LENGTH = 12;

    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
    }

    public function index(): Response
    {
        $params = $this->listParams();

        $where  = ['u.deleted_at IS NULL'];
        $values = [];

        if ($params['search'] !== '') {
            // One placeholder per column, all bound to the same term. The
            // connection uses native prepared statements, where PDO maps each
            // placeholder to exactly one bound value, so a single :s used five
            // times asks for a parameter that was never supplied and the query
            // fails with HY093.
            $term = '%' . $params['search'] . '%';

            $where[]      = '(u.username LIKE :s1 OR u.first_name LIKE :s2 OR u.last_name LIKE :s3
                            OR u.email LIKE :s4 OR u.phone LIKE :s5)';
            $values['s1'] = $term;
            $values['s2'] = $term;
            $values['s3'] = $term;
            $values['s4'] = $term;
            $values['s5'] = $term;
        }

        if (($params['filters']['status'] ?? '') !== '') {
            $where[]       = 'u.status = :status';
            $values['status'] = $params['filters']['status'];
        }

        // The role filter is an EXISTS rather than a join: a user may hold
        // several roles, and joining would multiply the rows being counted.
        if (($params['filters']['role_id'] ?? '') !== '') {
            $where[]           = 'EXISTS (SELECT 1 FROM user_roles ur
                                   INNER JOIN roles r ON r.id = ur.role_id
                                   WHERE ur.user_id = u.id AND r.id = :role_id)';
            $values['role_id'] = $params['filters']['role_id'];
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        $total     = (int) $this->db->scalar('SELECT COUNT(*) FROM users u' . $whereSql, $values);
        $paginator = new Paginator($total, $params['page'], $params['per_page']);

        $rows = $this->db->select(
            'SELECT u.id, u.uuid, u.username, u.first_name, u.last_name, u.email, u.phone,
                    u.profile_photo, u.status, u.must_change_password, u.last_login_at, u.last_login_ip,
                    u.failed_attempts, u.locked_until, u.created_at,
                    GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ", ") AS role_names,
                    COUNT(DISTINCT r.id) AS role_count
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id'
            . $whereSql
            . ' GROUP BY u.id'
            . $this->sortClause($params['sort'], $params['direction'])
            . ' LIMIT ' . $paginator->perPage . ' OFFSET ' . $paginator->offset(),
            $values
        );

        return $this->render('admin/users/index', [
            'pageTitle' => 'Users',
            'rows'      => $rows,
            'paginator' => $paginator,
            'sort'      => $params['sort'],
            'direction' => $params['direction'],
            'filters'   => [
                ['key' => 'status', 'label' => 'Status', 'options' => Form::enumOptions("'pending','active','suspended','deactivated'", 'All statuses')],
                ['key' => 'role_id', 'label' => 'Role', 'options' => Form::options($this->allRoles(), 'id', 'name', 'All roles')],
            ],
        ]);
    }

    public function create(): Response
    {
        return $this->render('admin/users/form', [
            'pageTitle' => 'Add user',
            'record'    => null,
            'sections'  => $this->sections(),
        ]);
    }

    public function store(): Response
    {
        $this->assertCsrf();

        $input    = $this->request->all();
        $sections = $this->sections();
        $fields   = array_merge(...array_column($sections, 'fields'));

        $validator = Form::validate($fields, $input);
        $validator->unique('username', 'users', 'username', null, 'Username');
        $validator->unique('email', 'users', 'email', null, 'Email address');

        $password = (string) ($input['password'] ?? '');
        $strength = Auth::passwordStrength($password);

        if ($strength['valid'] !== true) {
            $validator->addError('password', $strength['message']);
        }

        if ($validator->fails()) {
            return $this->backWithErrors(url('admin/users/create'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, ['last_name', 'phone']);
        unset($values['roles'], $values['password']);

        $roleIds = $this->resolveRoleIds($input);

        $userId = $this->db->transaction(function () use ($values, $password, $roleIds): int {
            $values['uuid']                = $this->uuid4();
            $values['password_hash']       = password_hash($password, PASSWORD_DEFAULT);
            $values['must_change_password'] = 1;
            $values['password_changed_at'] = date('Y-m-d H:i:s');

            $id = $this->db->insert('users', $values);

            foreach ($roleIds as $roleId) {
                $this->db->insert('user_roles', [
                    'user_id'     => $id,
                    'role_id'     => $roleId,
                    'assigned_at' => date('Y-m-d H:i:s'),
                    'assigned_by' => $this->auth->id(),
                ]);
            }

            return $id;
        });

        $user = $this->findOrFail($userId);

        Audit::log(
            'create',
            'users',
            'user',
            $userId,
            (string) $user['username'],
            null,
            $values + ['roles' => $roleIds]
        );

        Flash::success('User ' . $user['username'] . ' has been created. They must choose their own password at first sign-in.');

        return $this->redirect(url('admin/users/' . $userId . '/edit'));
    }

    public function edit(string $id): Response
    {
        $user = $this->findOrFail($id);

        return $this->render('admin/users/form', [
            'pageTitle' => 'Edit ' . $user['username'],
            'record'    => $user,
            'sections'  => $this->sections(),
        ]);
    }

    public function update(string $id): Response
    {
        $this->assertCsrf();

        $user   = $this->findOrFail($id);
        $input  = $this->request->all();
        $fields = array_merge(...array_column($this->sections(true), 'fields'));

        // The edit form deliberately declares no password and no uuid field, so
        // neither can be rewritten by a crafted POST.
        $validator = Form::validate($fields, $input);
        $validator->unique('email', 'users', 'email', (int) $user['id'], 'Email address');

        if ($validator->fails()) {
            return $this->backWithErrors(url('admin/users/' . $user['id'] . '/edit'), $validator->errors(), $input);
        }

        $values  = Form::extract($fields, $input, ['last_name', 'phone']);
        $roleIds = $this->resolveRoleIds($input);
        unset($values['roles']);

        $previousRoles = $this->roleIdsForUser((int) $user['id']);

        $this->db->transaction(function () use ($values, $user, $roleIds): void {
            $this->db->update('users', $values, 'id = :id', ['id' => $user['id']]);
            $this->db->delete('user_roles', 'user_id = :id', ['id' => $user['id']]);

            foreach ($roleIds as $roleId) {
                $this->db->insert('user_roles', [
                    'user_id'     => (int) $user['id'],
                    'role_id'     => $roleId,
                    'assigned_at' => date('Y-m-d H:i:s'),
                    'assigned_by' => $this->auth->id(),
                ]);
            }
        });

        Audit::log(
            'update',
            'users',
            'user',
            (int) $user['id'],
            (string) $user['username'],
            $this->auditable($user),
            $values + ['roles' => $roleIds]
        );

        if ($previousRoles !== $roleIds) {
            Audit::log(
                'assign_roles',
                'users',
                'user',
                (int) $user['id'],
                (string) $user['username'],
                ['roles' => $previousRoles],
                ['roles' => $roleIds]
            );
        }

        Flash::success('User record updated.');

        return $this->redirect(url('admin/users'));
    }

    /**
     * Deactivates an account rather than deleting it, and closes every way the
     * account could still be used: its browser sessions are dropped and its API
     * tokens are revoked.
     */
    public function destroy(string $id): Response
    {
        $this->assertCsrf();

        $user = $this->findOrFail($id);

        if ($this->isSuperAdmin((int) $user['id']) && $this->otherSuperAdmins((int) $user['id']) === 0) {
            Flash::error(
                $user['username'] . ' is the last active super administrator. Promote another account to '
                . 'super administrator before deactivating this one, or nobody will be able to administer the platform.'
            );

            return $this->redirect(url('admin/users'));
        }

        $now = date('Y-m-d H:i:s');

        $this->db->transaction(function () use ($user, $now): void {
            $this->db->update(
                'users',
                ['status' => 'deactivated', 'deleted_at' => $now],
                'id = :id',
                ['id' => $user['id']]
            );

            $this->db->delete('user_sessions', 'user_id = :id', ['id' => $user['id']]);

            $this->db->update(
                'api_tokens',
                ['revoked_at' => $now],
                'user_id = :id AND revoked_at IS NULL',
                ['id' => $user['id']]
            );
        });

        Audit::log(
            'deactivate',
            'users',
            'user',
            (int) $user['id'],
            (string) $user['username'],
            $this->auditable($user),
            ['status' => 'deactivated', 'sessions_closed' => true, 'api_tokens_revoked' => true]
        );

        Flash::success(
            'User ' . $user['username'] . ' has been deactivated. Their sessions were closed and their API tokens revoked. '
            . 'The record is retained for the audit trail.'
        );

        return $this->redirect(url('admin/users'));
    }

    /**
     * Issues a temporary password an administrator can read out over the phone.
     * Every credential the account held is invalidated at the same time, so a
     * reset really does close the account rather than adding a second key to it.
     */
    public function resetPassword(string $id): Response
    {
        $this->assertCsrf();

        $user     = $this->findOrFail($id);
        $password = $this->temporaryPassword();
        $now      = date('Y-m-d H:i:s');

        $this->db->transaction(function () use ($user, $password, $now): void {
            $this->db->update('users', [
                'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
                'must_change_password' => 1,
                'password_changed_at'  => $now,
                'failed_attempts'      => 0,
                'locked_until'         => null,
            ], 'id = :id', ['id' => $user['id']]);

            $this->db->delete('password_resets', 'user_id = :id', ['id' => $user['id']]);
            $this->db->delete('user_sessions', 'user_id = :id', ['id' => $user['id']]);
        });

        // The password itself is deliberately absent from the audit record; only
        // the fact that a reset happened is kept.
        Audit::log(
            'password_reset',
            'users',
            'user',
            (int) $user['id'],
            (string) $user['username'],
            ['must_change_password' => (int) $user['must_change_password']],
            ['must_change_password' => 1, 'failed_attempts' => 0, 'locked_until' => null, 'sessions_closed' => true]
        );

        Flash::success(
            'Temporary password for ' . $user['username'] . ': ' . $password
            . ' — shown once. The user must change it at first sign-in, and all their sessions were closed.'
        );

        return $this->redirect(url('admin/users'));
    }

    /* ------------------------------------------------------------------ */
    /* Supporting data                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(bool $isEdit = false): array
    {
        $roleOptions = Form::options($this->allRoles(), 'id', 'name', '');

        $account = [
            [
                'name'  => 'first_name',
                'label' => 'First name',
                'type'  => Form::TEXT,
                'required' => true,
                'colspan' => 6,
            ],
            [
                'name'  => 'last_name',
                'label' => 'Last name',
                'type'  => Form::TEXT,
                'colspan' => 6,
            ],
        ];

        if (!$isEdit) {
            array_unshift($account, [
                'name'     => 'username',
                'label'    => 'Username',
                'type'     => Form::TEXT,
                'required' => true,
                'colspan'  => 12,
                'minlength' => 3,
                'maxlength' => 60,
                'help'     => 'Letters, digits, dots, dashes or underscores. It is what the user signs in with.',
            ]);
        }

        $sections = [
            ['title' => 'Account', 'fields' => $account],
            [
                'title'  => 'Contact',
                'fields' => [
                    ['name' => 'email', 'label' => 'Email address', 'type' => Form::EMAIL, 'required' => true, 'colspan' => 6],
                    ['name' => 'phone', 'label' => 'Phone number', 'type' => Form::TEL, 'colspan' => 6,
                     'help' => 'Used for sign-in advisories and one-time codes.'],
                ],
            ],
        ];

        if (!$isEdit) {
            $sections[] = [
                'title'  => 'First password',
                'fields' => [
                    ['name' => 'password', 'label' => 'Initial password', 'type' => Form::PASSWORD, 'required' => true, 'colspan' => 6,
                     'help' => 'At least 8 characters with both letters and numbers. The user must change it at first sign-in.'],
                    ['name' => 'status', 'label' => 'Status', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions("'active','pending','suspended'", '')],
                ],
            ];
        } else {
            $sections[] = [
                'title'  => 'Status',
                'fields' => [
                    ['name' => 'status', 'label' => 'Status', 'type' => Form::SELECT, 'required' => true, 'colspan' => 6,
                     'options' => Form::enumOptions("'active','pending','suspended','deactivated'", '')],
                ],
            ];
        }

        $sections[] = [
            'title'  => 'Roles',
            'fields' => [
                ['name' => 'roles', 'label' => 'Roles held', 'type' => Form::MULTI, 'colspan' => 12, 'options' => $roleOptions,
                 'help' => 'A user may hold several roles. What they can do is the union of the permissions those roles grant. '
                         . 'Super administrators implicitly hold every permission.'],
            ],
        ];

        return $sections;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function allRoles(): array
    {
        return $this->db->select('SELECT id, name, code FROM roles ORDER BY name');
    }

    /**
     * Reduces the posted role list to role ids that actually exist. The form is
     * a multi-select, but the posted values are still treated as untrusted.
     *
     * @param array<string,mixed> $input
     * @return array<int,int>
     */
    private function resolveRoleIds(array $input): array
    {
        $posted = $input['roles'] ?? [];

        if (!is_array($posted)) {
            return [];
        }

        $known = array_map('intval', array_column($this->allRoles(), 'id'));
        $ids   = [];

        foreach ($posted as $value) {
            $id = (int) $value;

            if ($id > 0 && in_array($id, $known, true) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return array<int,int>
     */
    private function roleIdsForUser(int $userId): array
    {
        return array_map(
            'intval',
            $this->db->column('SELECT role_id FROM user_roles WHERE user_id = :id ORDER BY role_id', ['id' => $userId])
        );
    }

    private function isSuperAdmin(int $userId): bool
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM user_roles ur
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = :id AND r.code = :code",
            ['id' => $userId, 'code' => Auth::SUPER_ADMIN_ROLE]
        ) > 0;
    }

    /**
     * Counts the other super administrators still able to sign in, so the last
     * one cannot be removed by accident.
     */
    private function otherSuperAdmins(int $userId): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(DISTINCT ur.user_id) FROM user_roles ur
             INNER JOIN roles r ON r.id = ur.role_id
             INNER JOIN users u ON u.id = ur.user_id
             WHERE r.code = :code AND ur.user_id <> :id
               AND u.deleted_at IS NULL AND u.status = 'active'",
            ['code' => Auth::SUPER_ADMIN_ROLE, 'id' => $userId]
        );
    }

    /**
     * A version 4 UUID built from 16 cryptographically random bytes, formatted
     * by hand. The two version/variant nibbles are forced, so the value always
     * matches the CHAR(36) column the mobile application expects.
     */
    private function uuid4(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * A temporary password with the character classes Auth::passwordStrength()
     * insists on, built from an alphabet that excludes the characters most often
     * confused when read aloud or written on paper.
     */
    private function temporaryPassword(): string
    {
        $upper  = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower  = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $all    = $upper . $lower . $digits;

        $password = $upper[random_int(0, strlen($upper) - 1)]
            . $lower[random_int(0, strlen($lower) - 1)]
            . $digits[random_int(0, strlen($digits) - 1)];

        for ($i = strlen($password); $i < self::TEMPORARY_PASSWORD_LENGTH; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        $characters = str_split($password);

        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    /**
     * The user row with the credential columns removed, so nothing derived from
     * a password hash reaches the audit table.
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    private function auditable(array $user): array
    {
        unset($user['password_hash'], $user['uuid'], $user['last_login_ip']);

        return $user;
    }

    /**
     * @return array<string,mixed>
     */
    private function findOrFail(string $id): array
    {
        $user = $this->db->selectOne('SELECT * FROM users WHERE id = :id LIMIT 1', ['id' => $id]);

        if ($user === null) {
            throw new HttpException(404, 'That user account could not be found.');
        }

        return $user;
    }

    private function sortClause(string $sort, string $direction): string
    {
        $allowed = ['username', 'created_at', 'last_login_at'];
        $column  = in_array($sort, $allowed, true) ? $sort : 'created_at';
        $order   = $direction === 'asc' ? 'ASC' : 'DESC';

        return ' ORDER BY u.' . $column . ' ' . $order;
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }
}