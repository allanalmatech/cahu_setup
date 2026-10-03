<?php
declare(strict_types=1);

namespace Http\Controllers\Admin;

use Core\Audit;
use Core\Controller;
use Core\Csrf;
use Core\Flash;
use Core\Form;
use Core\HttpException;
use Core\Response;

/**
 * Roles and the permission matrix behind them. A user may hold several roles and
 * what they can do is the union of those roles' permissions, so this screen is
 * where access to the platform is actually decided.
 *
 * The seeded roles are marked is_system and cannot be deleted: they are what the
 * permission middleware and the navigation fall back on, and removing one would
 * lock every account that depends on it out of the parts of the platform it
 * still needs.
 */
final class RoleController extends Controller
{
    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
    }

    public function index(): Response
    {
        return $this->render('admin/roles/index', [
            'pageTitle' => 'Roles',
            'rows'      => $this->roles(),
        ]);
    }

    public function store(): Response
    {
        $this->assertCsrf();

        $input = $this->request->all();

        // A role code is read by the code, not by a person, so it is reduced to
        // the shape a code can actually take rather than whatever was typed.
        $input['code'] = self::normaliseCode($input['code'] ?? '');

        $fields    = $this->fields();
        $validator = Form::validate($fields, $input);
        $validator->unique('code', 'roles', 'code', null, 'Role code');

        if ($validator->fails()) {
            return $this->backWithErrors(url('admin/roles'), $validator->errors(), $input);
        }

        $values        = Form::extract($fields, $input, ['description']);
        $permissionIds = $this->resolvePermissionIds($input);

        $roleId = $this->db->transaction(function () use ($values, $permissionIds): int {
            $id = $this->db->insert('roles', $values);

            $this->writePermissions($id, $permissionIds);

            return $id;
        });

        $role = $this->findOrFail($roleId);

        Audit::log(
            'create',
            'roles',
            'role',
            $roleId,
            (string) $role['code'],
            null,
            ['name' => $values['name'], 'code' => $values['code'], 'description' => $values['description'] ?? null, 'permissions' => $permissionIds]
        );

        Flash::success('Role ' . $role['name'] . ' has been created.');

        return $this->redirect(url('admin/roles'));
    }

    public function edit(string $id): Response
    {
        $role = $this->findOrFail($id);

        return $this->render('admin/roles/form', [
            'pageTitle'        => 'Edit ' . $role['name'],
            'record'           => $role,
            'permissionGroups' => $this->permissionGroups(),
            'selected'         => $this->permissionIdsForRole((int) $role['id']),
        ]);
    }

    public function update(string $id): Response
    {
        $this->assertCsrf();

        $role         = $this->findOrFail($id);
        $input        = $this->request->all();
        $input['code'] = self::normaliseCode($input['code'] ?? '');

        $fields    = $this->fields();
        $validator = Form::validate($fields, $input);
        $validator->unique('code', 'roles', 'code', (int) $role['id'], 'Role code');

        if ($validator->fails()) {
            return $this->backWithErrors(url('admin/roles/' . $role['id'] . '/edit'), $validator->errors(), $input);
        }

        $values        = Form::extract($fields, $input, ['description']);
        $permissionIds = $this->resolvePermissionIds($input);
        $before        = $this->permissionIdsForRole((int) $role['id']);

        $this->db->transaction(function () use ($values, $role, $permissionIds): void {
            $this->db->update('roles', $values, 'id = :id', ['id' => $role['id']]);
            $this->writePermissions((int) $role['id'], $permissionIds);
        });

        Audit::log(
            'update',
            'roles',
            'role',
            (int) $role['id'],
            (string) $role['code'],
            ['name' => $role['name'], 'code' => $role['code'], 'description' => $role['description'], 'permissions' => $before],
            ['name' => $values['name'], 'code' => $values['code'], 'description' => $values['description'] ?? null, 'permissions' => $permissionIds]
        );

        if ($before !== $permissionIds) {
            Audit::log(
                'set_permissions',
                'roles',
                'role',
                (int) $role['id'],
                (string) $role['code'],
                ['removed' => array_values(array_diff($before, $permissionIds))],
                ['granted' => array_values(array_diff($permissionIds, $before))]
            );
        }

        Flash::success('Role ' . $values['name'] . ' has been updated.');

        return $this->redirect(url('admin/roles'));
    }

    public function destroy(string $id): Response
    {
        $this->assertCsrf();

        $role = $this->findOrFail($id);

        if ((int) $role['is_system'] === 1) {
            Flash::error(
                $role['name'] . ' is a system role. It backs the seeded access rules and cannot be deleted. '
                . 'Change the permissions it grants instead.'
            );

            return $this->redirect(url('admin/roles'));
        }

        $users = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM user_roles ur
             INNER JOIN users u ON u.id = ur.user_id
             WHERE ur.role_id = :id AND u.deleted_at IS NULL',
            ['id' => $role['id']]
        );

        if ($users > 0) {
            Flash::warning(
                'Role ' . $role['name'] . ' is still held by ' . $users . ' active user(s). Take the role away from '
                . 'them on their user record before deleting it.'
            );

            return $this->redirect(url('admin/roles'));
        }

        $before = $this->permissionIdsForRole((int) $role['id']);

        $this->db->transaction(function () use ($role): void {
            $this->db->delete('role_permissions', 'role_id = :id', ['id' => $role['id']]);
            $this->db->delete('user_roles', 'role_id = :id', ['id' => $role['id']]);
            $this->db->delete('roles', 'id = :id', ['id' => $role['id']]);
        });

        Audit::log(
            'delete',
            'roles',
            'role',
            (int) $role['id'],
            (string) $role['code'],
            ['name' => $role['name'], 'code' => $role['code'], 'permissions' => $before]
        );

        Flash::success('Role ' . $role['name'] . ' has been deleted.');

        return $this->redirect(url('admin/roles'));
    }

    /* ------------------------------------------------------------------ */
    /* Supporting data                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<int,array<string,mixed>>
     */
    private function roles(): array
    {
        return $this->db->select(
            'SELECT r.id, r.name, r.code, r.description, r.is_system, r.created_at,
                    (SELECT COUNT(*) FROM user_roles ur
                     INNER JOIN users u ON u.id = ur.user_id
                     WHERE ur.role_id = r.id AND u.deleted_at IS NULL) AS user_count,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count
             FROM roles r
             ORDER BY r.name'
        );
    }

    /**
     * Permissions grouped by the module they belong to, which is how the matrix
     * on the form is laid out: an administrator grants access to a screen, not
     * to an arbitrary list of codes.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function permissionGroups(): array
    {
        $groups = [];

        foreach ($this->db->select('SELECT id, module, name, code, description FROM permissions ORDER BY module, name') as $permission) {
            $groups[(string) $permission['module']][] = $permission;
        }

        return $groups;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Role name', 'type' => Form::TEXT, 'required' => true, 'maxlength' => 80],
            ['name' => 'code', 'label' => 'Role code', 'type' => Form::TEXT, 'required' => true, 'maxlength' => 50,
             'help' => 'Lower case letters, digits and underscores, e.g. field_officer. It is stored on the role and is what the code checks for.'],
            ['name' => 'description', 'label' => 'Description', 'type' => Form::TEXTAREA, 'maxlength' => 255],
        ];
    }

    /**
     * The posted matrix is a list of permission ids. Anything that is not a real
     * permission is dropped rather than written, so a crafted POST cannot grant
     * access to a permission that does not exist.
     *
     * @param array<string,mixed> $input
     * @return array<int,int>
     */
    private function resolvePermissionIds(array $input): array
    {
        $posted = $input['permissions'] ?? [];

        if (!is_array($posted)) {
            return [];
        }

        $known = array_map(
            'intval',
            $this->db->column('SELECT id FROM permissions')
        );

        $ids = [];

        foreach ($posted as $value) {
            $id = (int) $value;

            if ($id > 0 && in_array($id, $known, true) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * @param array<int,int> $permissionIds
     */
    private function writePermissions(int $roleId, array $permissionIds): void
    {
        $this->db->delete('role_permissions', 'role_id = :id', ['id' => $roleId]);

        foreach ($permissionIds as $permissionId) {
            $this->db->insert('role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    /**
     * @return array<int,int>
     */
    private function permissionIdsForRole(int $roleId): array
    {
        $ids = array_map(
            'intval',
            $this->db->column('SELECT permission_id FROM role_permissions WHERE role_id = :id ORDER BY permission_id', ['id' => $roleId])
        );

        sort($ids);

        return $ids;
    }

    /**
     * A role code is lower case letters, digits and underscores. Anything else a
     * typist produced is folded into that shape rather than rejected, so a code
     * never reaches the permission checks in a form the code cannot read.
     */
    private static function normaliseCode(mixed $raw): string
    {
        if (!is_scalar($raw)) {
            return '';
        }

        $code = strtolower(trim((string) $raw));
        $code = (string) preg_replace('/[^a-z0-9]+/', '_', $code);

        return trim($code, '_');
    }

    /**
     * @return array<string,mixed>
     */
    private function findOrFail(string $id): array
    {
        $role = $this->db->selectOne('SELECT * FROM roles WHERE id = :id LIMIT 1', ['id' => $id]);

        if ($role === null) {
            throw new HttpException(404, 'That role could not be found.');
        }

        return $role;
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }
}