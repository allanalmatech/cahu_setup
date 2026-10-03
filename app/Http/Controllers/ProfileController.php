<?php
declare(strict_types=1);

namespace Http\Controllers;

use Core\Audit;
use Core\Auth;
use Core\Controller;
use Core\Csrf;
use Core\Flash;
use Core\Form;
use Core\HttpException;
use Core\Response;

/**
 * The signed-in user's own profile: the details they are allowed to correct,
 * the roles and records attached to their account, and the personal API tokens
 * the mobile application authenticates with.
 *
 * Almost nothing here is editable. A username, a role and a token are all
 * issued by somebody else: a username because it is the identity an audit
 * trail is written against, a role because it decides what the account can see,
 * and a token because it is a credential. The screen therefore shows the
 * issued state plainly and only offers the four fields a person actually
 * maintains themselves.
 */
final class ProfileController extends Controller
{
    public function __construct(\Core\Database $db, \Core\Auth $auth, \Core\View $view, \Core\Request $request)
    {
        parent::__construct($db, $auth, $view, $request);
    }

    public function show(): Response
    {
        $user = $this->currentUser();

        return $this->render('profile/show', [
            'pageTitle' => 'My profile',
            'user'        => $user,
            'fullName'    => $this->fullName($user),
            'roles'       => $this->auth->roles(),
            'permissions' => $this->auth->permissions(),
            'farmer'      => $this->farmerRecord(),
            'officer'     => $this->officerRecord(),
            'tokens'      => $this->apiTokens(),
            'sections'    => $this->sections(),
        ]);
    }

    /**
     * Saves the four self-maintained fields. A username posted alongside them
     * is refused rather than ignored: silently dropping it would leave the user
     * believing a change had been made that never happened.
     */
    public function update(): Response
    {
        $this->assertCsrf();

        $user   = $this->currentUser();
        $input  = $this->request->all();
        $fields = array_merge(...array_column($this->sections(), 'fields'));

        $validator = Form::validate($fields, $input);
        $validator->unique('email', 'users', 'email', (int) $user['id'], 'Email address');

        $postedUsername = $this->request->string('username');
        if ($postedUsername !== '' && $postedUsername !== (string) $user['username']) {
            $validator->addError('username', 'A username is issued by an administrator and cannot be changed here.');
        }

        if ($validator->fails()) {
            return $this->backWithErrors(url('profile'), $validator->errors(), $input);
        }

        $values = Form::extract($fields, $input, ['last_name', 'phone']);

        $this->db->update('users', $values, 'id = :id', ['id' => $user['id']]);

        Audit::log('update', 'profile', 'user', (int) $user['id'], $user['username'], $user, $values);

        Flash::success('Your details have been saved.');

        return $this->redirect(url('profile'));
    }

    /**
     * The change-password screen. The session the change is being made from is
     * shown alongside it, so a user who suspects someone else is signed in can
     * see it before they change anything.
     */
    public function passwordForm(): Response
    {
        $user = $this->currentUser();

        return $this->render('profile/password', [
            'pageTitle' => 'Change password',
            'user'      => $user,
            'session'   => $this->currentSession(),
            'sessions'  => $this->recentSessions(),
        ]);
    }

    /**
     * Changes the password through Auth, which verifies the current one, applies
     * the strength rule and writes the audit entry. On success the stored hash
     * is read back and the new password verified against it, so a silent write
     * failure cannot leave a user believing they are protected when they are not.
     */
    public function changePassword(): Response
    {
        $this->assertCsrf();

        $user     = $this->currentUser();
        $current  = $this->request->string('current_password');
        $password = (string) $this->request->input('password', '');
        $confirm  = (string) $this->request->input('password_confirmation', '');

        $errors = [];

        if ($current === '') {
            $errors['current_password'] = 'Enter the password you use now.';
        }

        if ($password === '') {
            $errors['password'] = 'Enter the new password.';
        } else {
            $strength = Auth::passwordStrength($password);
            if ($strength['valid'] !== true) {
                $errors['password'] = $strength['message'];
            }
        }

        if ($password !== $confirm) {
            $errors['password_confirmation'] = 'The two passwords do not match.';
        }

        if ($errors !== []) {
            return $this->backWithErrors(url('profile/password'), $errors);
        }

        $result = $this->auth->changePassword((int) $user['id'], $current, $password);

        if ($result['success'] !== true) {
            return $this->backWithErrors(url('profile/password'), ['current_password' => $result['message']]);
        }

        $stored = $this->db->scalar('SELECT password_hash FROM users WHERE id = :id', ['id' => $user['id']]);

        if (!is_string($stored) || !password_verify($password, $stored)) {
            Audit::log('password_change_failed', 'profile', 'user', (int) $user['id'], $user['username']);

            return $this->backWithErrors(
                url('profile/password'),
                ['password' => 'The new password could not be stored. Nothing has been changed; please try again.']
            );
        }

        Audit::log('password_change', 'profile', 'user', (int) $user['id'], $user['username']);

        Flash::success('Your password has been changed. Use the new one the next time you sign in.');

        return $this->redirect(url('profile'));
    }

    /**
     * @return array<string,mixed>
     */
    private function currentUser(): array
    {
        $user = $this->auth->user();

        if ($user === null) {
            throw new HttpException(401, 'Please sign in to view your profile.');
        }

        return $this->db->selectOne(
            'SELECT u.id, u.uuid, u.username, u.email, u.phone, u.first_name, u.last_name,
                    u.profile_photo, u.status, u.must_change_password, u.last_login_at, u.last_login_ip,
                    u.password_changed_at, u.created_at
             FROM users u WHERE u.id = :id AND u.deleted_at IS NULL LIMIT 1',
            ['id' => $user['id']]
        ) ?? $user;
    }

    /**
     * @param array<string,mixed> $user
     */
    private function fullName(array $user): string
    {
        $name = trim((string) $user['first_name'] . ' ' . (string) ($user['last_name'] ?? ''));

        return $name === '' ? (string) $user['username'] : $name;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function farmerRecord(): ?array
    {
        $farmerId = $this->auth->farmerId();

        if ($farmerId === null) {
            return null;
        }

        return $this->db->selectOne(
            'SELECT f.id, f.farmer_code, f.full_name, f.phone, f.status, f.registration_date,
                    (SELECT COUNT(*) FROM gardens g WHERE g.farmer_id = f.id AND g.deleted_at IS NULL) AS garden_count,
                    (SELECT COUNT(*) FROM plantings p WHERE p.farmer_id = f.id) AS planting_count
             FROM farmers f
             WHERE f.id = :id AND f.deleted_at IS NULL LIMIT 1',
            ['id' => $farmerId]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function officerRecord(): ?array
    {
        $officerId = $this->auth->officerId();

        if ($officerId === null) {
            return null;
        }

        return $this->db->selectOne(
            'SELECT o.id, o.officer_code, o.full_name, o.phone, o.email, o.employee_no, o.status,
                    r.name AS region_name, d.name AS district_name,
                    (SELECT COUNT(*) FROM officer_assignments a WHERE a.officer_id = o.id AND a.active = 1) AS assignment_count
             FROM field_officers o
             LEFT JOIN regions r ON r.id = o.region_id
             LEFT JOIN districts d ON d.id = o.district_id
             WHERE o.id = :id AND o.deleted_at IS NULL LIMIT 1',
            ['id' => $officerId]
        );
    }

    /**
     * The API tokens issued against this account, newest first. Only the hash is
     * stored in the table, and it is not selected: the token itself is never
     * recoverable, so there is nothing here worth exposing.
     *
     * @return array<int,array<string,mixed>>
     */
    private function apiTokens(): array
    {
        return $this->db->select(
            'SELECT t.id, t.name, t.device_info, t.scopes, t.last_used_at, t.expires_at, t.revoked_at, t.created_at
             FROM api_tokens t
             WHERE t.user_id = :uid
             ORDER BY t.created_at DESC',
            ['uid' => $this->auth->id()]
        );
    }

    /**
     * The session this request is being served from, so the change-password
     * screen can name the address the account is currently in use from.
     *
     * @return array<string,mixed>|null
     */
    private function currentSession(): ?array
    {
        $sessionId = session_id();

        if ($sessionId === false || $sessionId === '') {
            return null;
        }

        return $this->db->selectOne(
            'SELECT s.id, s.session_id, s.ip_address, s.user_agent, s.last_activity, s.created_at
             FROM user_sessions s
             WHERE s.user_id = :uid AND s.session_id = :sid
             LIMIT 1',
            ['uid' => $this->auth->id(), 'sid' => $sessionId]
        );
    }

    /**
     * The account's recent sign-in history, so a user can recognise a sign-in
     * they do not recognise. The session in use is flagged in the template by
     * comparing against the current session id.
     *
     * @return array<int,array<string,mixed>>
     */
    private function recentSessions(): array
    {
        return $this->db->select(
            'SELECT s.id, s.session_id, s.ip_address, s.user_agent, s.last_activity, s.created_at
             FROM user_sessions s
             WHERE s.user_id = :uid
             ORDER BY s.last_activity DESC
             LIMIT 10',
            ['uid' => $this->auth->id()]
        );
    }

    /**
     * @return array<int,array{title:string,fields:array<int,array<string,mixed>>}>
     */
    private function sections(): array
    {
        return [
            [
                'title'  => 'Your details',
                'fields' => [
                    ['name' => 'username', 'label' => 'Username', 'type' => Form::TEXT, 'readonly' => true,
                     'help' => 'Issued by an administrator. It is the name written against everything you record, so it cannot be changed here.'],
                    ['name' => 'first_name', 'label' => 'First name', 'type' => Form::TEXT, 'required' => true, 'colspan' => 6, 'maxlength' => 80],
                    ['name' => 'last_name', 'label' => 'Last name', 'type' => Form::TEXT, 'colspan' => 6, 'maxlength' => 80],
                    ['name' => 'email', 'label' => 'Email address', 'type' => Form::EMAIL, 'required' => true, 'colspan' => 6,
                     'help' => 'Used for advisories and password resets.'],
                    ['name' => 'phone', 'label' => 'Phone number', 'type' => Form::TEL, 'colspan' => 6,
                     'help' => 'Used for SMS reminders about work that is due.'],
                ],
            ],
        ];
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }
}
