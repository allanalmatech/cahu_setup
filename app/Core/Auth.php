<?php
declare(strict_types=1);

namespace Core;

/**
 * Session authentication and role based access control.
 *
 * Permissions are resolved once per request and cached. A user may hold
 * multiple roles; the union of their permissions applies. Super administrators
 * implicitly hold every permission.
 */
final class Auth
{
    public const SUPER_ADMIN_ROLE = 'super_admin';

    private Database $db;
    private array $config;

    /** @var array<string,mixed>|null */
    private ?array $user = null;
    /** @var array<int,string>|null */
    private ?array $permissions = null;

    /**
     * The api_tokens row that authenticated this request.
     *
     * Remembered so that signing out over the API can revoke exactly the token
     * that was used, rather than guessing or revoking all of a user's tokens.
     */
    private ?int $apiTokenId = null;

    public function __construct(Database $db, array $config)
    {
        $this->db     = $db;
        $this->config = $config;
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $params = [
            'lifetime' => (int) ($this->config['session_lifetime'] ?? 1800),
            'path'     => '/',
            'domain'   => '',
            'secure'   => (bool) ($this->config['session_secure'] ?? false),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        session_name((string) ($this->config['session_name'] ?? 'CAHUSESSID'));
        session_set_cookie_params($params);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_start();

        $this->enforceSessionTimeout();
    }

    private function enforceSessionTimeout(): void
    {
        $lifetime = (int) ($this->config['session_lifetime'] ?? 1800);

        if ($lifetime <= 0 || !isset($_SESSION['_last_activity'])) {
            $_SESSION['_last_activity'] = time();

            return;
        }

        if (time() - (int) $_SESSION['_last_activity'] > $lifetime) {
            $this->logout();

            return;
        }

        $_SESSION['_last_activity'] = time();
    }

    /**
     * Verifies credentials and starts an authenticated session.
     *
     * @return array{success:bool,message:string,user?:array<string,mixed>}
     */
    public function attempt(string $login, string $password, Request $request): array
    {
        $login = trim($login);
        $now   = date('Y-m-d H:i:s');

        // Two placeholders rather than one named :login used twice. The
        // connection runs with native prepared statements, where PDO maps each
        // placeholder to exactly one bound value, so a repeated name asks for a
        // parameter that was never supplied and the query fails with HY093
        // before it reaches the database. Binding the same value twice keeps
        // both columns indexable, which a CONCAT or a LIKE rewrite would not.
        $user = $this->db->selectOne(
            'SELECT * FROM users
             WHERE (username = :username OR email = :email) AND deleted_at IS NULL
             LIMIT 1',
            ['username' => $login, 'email' => $login]
        );

        if ($user === null) {
            $this->logLogin(null, $login, false, 'Invalid credentials', $request);

            return ['success' => false, 'message' => 'The credentials you entered are not valid.'];
        }

        if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
            $this->logLogin((int) $user['id'], $login, false, 'Account locked', $request);

            return [
                'success' => false,
                'message' => 'This account is temporarily locked. Please try again later.',
            ];
        }

        if ($user['status'] !== 'active') {
            $this->logLogin((int) $user['id'], $login, false, 'Account not active', $request);

            return [
                'success' => false,
                'message' => 'This account is not active. Contact the administrator.',
            ];
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->registerFailedAttempt($user);
            $this->logLogin((int) $user['id'], $login, false, 'Wrong password', $request);

            return ['success' => false, 'message' => 'The credentials you entered are not valid.'];
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->update(
                'users',
                ['password_hash' => password_hash($password, PASSWORD_DEFAULT)],
                'id = :id',
                ['id' => $user['id']]
            );
        }

        $this->db->update(
            'users',
            [
                'last_login_at'   => $now,
                'last_login_ip'   => $request->ip(),
                'failed_attempts' => 0,
                'locked_until'    => null,
            ],
            'id = :id',
            ['id' => $user['id']]
        );

        $this->login($user);
        $this->logLogin((int) $user['id'], $login, true, null, $request);

        Audit::log('login', 'auth', 'user', (int) $user['id'], (string) $user['username']);

        return ['success' => true, 'message' => 'Signed in successfully.', 'user' => $user];
    }

    /**
     * @param array<string,mixed> $user
     */
    private function registerFailedAttempt(array $user): void
    {
        $maxAttempts = (int) ($this->config['max_login_attempts'] ?? 5);
        $attempts    = (int) $user['failed_attempts'] + 1;

        $data = ['failed_attempts' => $attempts];

        if ($attempts >= $maxAttempts) {
            $data['locked_until'] = date('Y-m-d H:i:s', time() + 900);
            $attempts            = 0;
        }

        $this->db->update('users', $data, 'id = :id', ['id' => $user['id']]);
    }

    private function logLogin(?int $userId, string $attempted, bool $success, ?string $reason, Request $request): void
    {
        $this->db->insert('login_histories', [
            'user_id'             => $userId,
            'username_attempted'  => substr($attempted, 0, 150),
            'success'             => $success ? 1 : 0,
            'reason'              => $reason,
            'ip_address'          => $request->ip(),
            'user_agent'          => $request->userAgent(),
        ]);
    }

    /**
     * @param array<string,mixed> $user
     */
    public function login(array $user): void
    {
        session_regenerate_id(true);

        $_SESSION['user_id']    = (int) $user['id'];
        $_SESSION['login_time'] = time();
        $_SESSION['fingerprint'] = $this->fingerprint($user);

        $this->user        = $user;
        $this->permissions = null;
    }

    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        session_destroy();
        session_start();

        $this->user        = null;
        $this->permissions = null;
    }

    /**
     * @param array<string,mixed> $user
     */
    private function fingerprint(array $user): string
    {
        return hash_hmac(
            'sha256',
            ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . $user['password_hash'],
            (string) ($this->config['app_key'] ?? 'cahu')
        );
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Authenticates a machine client using a bearer token issued from the user
     * profile screen. The token is looked up by hash, so a stolen database dump
     * cannot be used to call the API.
     */
    public function authenticateToken(?string $token, Request $request): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $row = $this->db->selectOne(
            'SELECT t.id AS token_id, t.expires_at, t.revoked_at, t.scopes, u.*
             FROM api_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :hash LIMIT 1',
            ['hash' => hash('sha256', $token)]
        );

        if ($row === null) {
            return false;
        }

        if ($row['revoked_at'] !== null || $row['status'] !== 'active' || $row['deleted_at'] !== null) {
            return false;
        }

        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
            return false;
        }

        $this->db->update(
            'api_tokens',
            ['last_used_at' => date('Y-m-d H:i:s'), 'device_info' => substr($request->userAgent(), 0, 255)],
            'id = :id',
            ['id' => $row['token_id']]
        );

        $this->apiTokenId = (int) $row['token_id'];

        // The token table's columns must not leak into the user session array.
        unset($row['token_id'], $row['scopes'], $row['expires_at'], $row['revoked_at']);

        $this->user        = $row;
        $this->permissions = null;

        Audit::log('api_login', 'auth', 'user', (int) $row['id'], (string) $row['username']);

        return true;
    }

    /**
     * The api_tokens id that authenticated the current request, or null when the
     * request was authenticated by a session cookie instead.
     */
    public function apiTokenId(): ?int
    {
        return $this->apiTokenId;
    }

    /**
     * Issues a new API token and returns the plaintext value. The plaintext is
     * shown to the user exactly once.
     */
    public function issueApiToken(int $userId, string $name, ?int $days = 365): string
    {
        $token = bin2hex(random_bytes(32));

        $this->db->insert('api_tokens', [
            'user_id'    => $userId,
            'name'       => $name,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $days === null ? null : date('Y-m-d H:i:s', strtotime('+' . $days . ' days')),
            'created_by' => $this->id(),
        ]);

        return $token;
    }

    public function revokeApiToken(int $tokenId, int $userId): void
    {
        $this->db->update(
            'api_tokens',
            ['revoked_at' => date('Y-m-d H:i:s')],
            'id = :id AND user_id = :user',
            ['id' => $tokenId, 'user' => $userId]
        );
    }

    /**
     * Revokes every live token for a user except the one making the request.
     *
     * Called after a password change from the device that made it. A password
     * change is the user's way of saying "something may have gone wrong", and
     * leaving a token on a lost handset alive would make that gesture useless.
     * The current token survives so the user is not signed out of the app they
     * just used to change the password.
     */
    public function revokeOtherApiTokens(int $userId, ?int $exceptTokenId = null): int
    {
        $sql    = 'UPDATE api_tokens SET revoked_at = :now
                   WHERE user_id = :user AND revoked_at IS NULL';
        $values = ['now' => date('Y-m-d H:i:s'), 'user' => $userId];

        if ($exceptTokenId !== null) {
            $sql             .= ' AND id <> :except';
            $values['except'] = $exceptTokenId;
        }

        return $this->db->query($sql, $values)->rowCount();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function user(): ?array
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $id = $_SESSION['user_id'] ?? null;

        if ($id === null) {
            return null;
        }

        $user = $this->db->selectOne(
            'SELECT id, uuid, username, email, phone, first_name, last_name, profile_photo, status,
                    must_change_password, password_hash
             FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $id]
        );

        if ($user === null || $user['status'] !== 'active') {
            $this->logout();

            return null;
        }

        if (($_SESSION['fingerprint'] ?? null) !== $this->fingerprint($user)) {
            $this->logout();

            return null;
        }

        $this->user = $user;

        return $this->user;
    }

    public function id(): ?int
    {
        $user = $this->user();

        return $user === null ? null : (int) $user['id'];
    }

    public function farmerId(): ?int
    {
        $user = $this->user();

        if ($user === null) {
            return null;
        }

        $row = $this->db->selectOne('SELECT id FROM farmers WHERE user_id = :uid LIMIT 1', ['uid' => $user['id']]);

        return $row === null ? null : (int) $row['id'];
    }

    public function officerId(): ?int
    {
        $user = $this->user();

        if ($user === null) {
            return null;
        }

        $row = $this->db->selectOne('SELECT id FROM field_officers WHERE user_id = :uid LIMIT 1', ['uid' => $user['id']]);

        return $row === null ? null : (int) $row['id'];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function roles(): array
    {
        if (!$this->check()) {
            return [];
        }

        return $this->db->select(
            'SELECT r.* FROM roles r
             INNER JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = :uid',
            ['uid' => $this->id()]
        );
    }

    /**
     * @return array<int,string>
     */
    public function roleCodes(): array
    {
        return array_column($this->roles(), 'code');
    }

    public function isSuperAdmin(): bool
    {
        return in_array(self::SUPER_ADMIN_ROLE, $this->roleCodes(), true);
    }

    /**
     * @return array<int,string>
     */
    public function permissions(): array
    {
        if ($this->permissions !== null) {
            return $this->permissions;
        }

        if (!$this->check()) {
            return $this->permissions = [];
        }

        if ($this->isSuperAdmin()) {
            return $this->permissions = ['*'];
        }

        return $this->permissions = $this->db->column(
            'SELECT DISTINCT p.code
             FROM permissions p
             INNER JOIN role_permissions rp ON rp.permission_id = p.id
             INNER JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = :uid',
            ['uid' => $this->id()]
        );
    }

    public function can(string $permission): bool
    {
        $permissions = $this->permissions();

        if (in_array('*', $permissions, true) || in_array($permission, $permissions, true)) {
            return true;
        }

        // A module.view permission implies read access to that module's sub-resources.
        [$module] = array_pad(explode('.', $permission, 2), 2, null);

        return in_array($module . '.view', $permissions, true);
    }

    public function canAny(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        $user = $this->db->selectOne('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId]);

        if ($user === null) {
            return ['success' => false, 'message' => 'Account not found.'];
        }

        if (!password_verify($currentPassword, (string) $user['password_hash'])) {
            return ['success' => false, 'message' => 'Your current password is not correct.'];
        }

        $strength = self::passwordStrength($newPassword);
        if ($strength['valid'] !== true) {
            return ['success' => false, 'message' => $strength['message']];
        }

        $this->db->update('users', [
            'password_hash'       => password_hash($newPassword, PASSWORD_DEFAULT),
            'password_changed_at' => date('Y-m-d H:i:s'),
            'must_change_password' => 0,
        ], 'id = :id', ['id' => $userId]);

        Audit::log('password_change', 'auth', 'user', $userId);

        return ['success' => true, 'message' => 'Your password has been updated.'];
    }

    /**
     * @return array{valid:bool,message:string}
     */
    public static function passwordStrength(string $password): array
    {
        if (strlen($password) < 8) {
            return ['valid' => false, 'message' => 'The password must be at least 8 characters long.'];
        }

        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return ['valid' => false, 'message' => 'The password must contain both letters and numbers.'];
        }

        return ['valid' => true, 'message' => 'Password accepted.'];
    }

    /**
     * Validates a self-registration and creates the account as pending.
     *
     * Shared by the web form and the mobile endpoint so the two cannot drift
     * apart — a rule that exists in only one of them is a rule somebody will
     * find the gap in, by registering on the other.
     *
     * The account is created pending and with no role attached. `attempt()`
     * refuses any status but 'active', and `permissions()` reads only
     * `user_roles`, so an unapproved signup can neither sign in nor reach a
     * module even if the status check were ever relaxed. An administrator
     * approves the account and assigns the role from the user screen.
     *
     * @return array{success:bool,errors:array<string,string>,user_id?:int,message:string}
     */
    public function createPendingRegistration(
        string $firstName,
        string $lastName,
        string $email,
        string $password,
        string $confirmation
    ): array {
        $email    = strtolower(trim($email));
        $errors   = [];

        if ($firstName === '') {
            $errors['first_name'] = 'First name is required.';
        }

        if ($lastName === '') {
            $errors['last_name'] = 'Last name is required.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email is required.';
        }

        $strength = self::passwordStrength($password);

        if ($strength['valid'] !== true) {
            $errors['password'] = $strength['message'];
        }

        if ($password !== $confirmation) {
            $errors['password_confirmation'] = 'The two passwords do not match.';
        }

        if ($errors === [] && $this->db->scalar('SELECT 1 FROM users WHERE email = :e LIMIT 1', ['e' => $email]) !== null) {
            $errors['email'] = 'That email is already registered.';
        }

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors, 'message' => 'Please check the details you entered.'];
        }

        $userId = $this->db->insert('users', [
            'uuid'          => bin2hex(random_bytes(18)),
            'username'      => explode('@', $email)[0] . random_int(100, 999),
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'first_name'    => $firstName,
            'last_name'     => $lastName,
            'status'        => 'pending',
        ]);

        Audit::log('user_registered', 'auth', 'user', (int) $userId);

        return [
            'success'  => true,
            'errors'   => [],
            'user_id'  => (int) $userId,
            'message'  => 'Your account has been created and is waiting for approval. '
                        . 'An administrator will review it and assign your access, after which you can sign in.',
        ];
    }
}
