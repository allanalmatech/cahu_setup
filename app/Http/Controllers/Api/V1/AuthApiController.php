<?php
declare(strict_types=1);

namespace Http\Controllers\Api\V1;

use Core\Auth;
use Core\Request;
use Core\Response;

/**
 * Token authentication for the mobile client.
 *
 * The web application uses a session cookie; the mobile app cannot, so it
 * exchanges credentials for a long-lived bearer token that it stores on the
 * device. Tokens are stored hashed, and a token can be revoked without
 * affecting the user's session or password.
 */
final class AuthApiController extends ApiController
{
    /**
     * POST /api/v1/auth/login
     */
    public function login(): Response
    {
        $login    = $this->request->string('username');
        $password = (string) $this->request->input('password', '');

        $errors = [];

        if ($login === '') {
            $errors['username'] = 'Enter your username or email address.';
        }

        if ($password === '') {
            $errors['password'] = 'Enter your password.';
        }

        if ($errors !== []) {
            return $this->validationFailed($errors);
        }

        $result = $this->auth->attempt($login, $password, $this->request);

        if (($result['success'] ?? false) !== true) {
            return $this->fail((string) ($result['message'] ?? 'Sign in failed.'), 401);
        }

        $user = $result['user'] ?? null;

        if (!is_array($user)) {
            return $this->fail('Sign in failed.', 401);
        }

        // The device name lets a user recognise and revoke a token later.
        $device = $this->request->string('device_name', 'mobile');

        $token = $this->auth->issueApiToken((int) $user['id'], substr($device, 0, 80));

        return $this->ok([
            'token' => $token,
            'user'  => $this->presentUser($user),
        ]);
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(): Response
    {
        $userId = $this->auth->id();
        $tokenId = $this->auth->apiTokenId();

        if ($userId !== null && $tokenId !== null) {
            $this->auth->revokeApiToken($tokenId, $userId);
        }

        return $this->ok(['message' => 'Signed out.']);
    }

    /**
     * POST /api/v1/auth/register
     *
     * Public self-registration, so a farmer can create an account from the
     * handset without an administrator typing it in. No token is issued: the
     * account is pending until somebody approves it, and `attempt()` refuses
     * anything but 'active', so there is nothing here to sign in with yet.
     */
    public function register(): Response
    {
        $result = $this->auth->createPendingRegistration(
            $this->request->string('first_name'),
            $this->request->string('last_name'),
            $this->request->string('email'),
            $this->request->string('password'),
            $this->request->string('password_confirmation')
        );

        if ($result['success'] !== true) {
            return $this->validationFailed($result['errors']);
        }

        return $this->created([
            'message' => $result['message'],
            'status'  => 'pending',
        ]);
    }

    /**
     * POST /api/v1/auth/password
     *
     * The app forces this screen for any account flagged
     * `must_change_password`, which every seeded demo account carries, so it
     * needs to exist on the API and not only in the web profile.
     */
    public function changePassword(): Response
    {
        $userId = $this->auth->id();

        if ($userId === null) {
            return $this->unauthorized();
        }

        $current  = $this->request->string('current_password');
        $password = $this->request->string('password');
        $confirm  = $this->request->string('password_confirmation');

        $errors = [];

        if ($current === '') {
            $errors['current_password'] = 'Enter the password you use now.';
        }

        $strength = Auth::passwordStrength($password);

        if ($password === '') {
            $errors['password'] = 'Enter the new password.';
        } elseif ($strength['valid'] !== true) {
            $errors['password'] = $strength['message'];
        }

        if ($password !== $confirm) {
            $errors['password_confirmation'] = 'The two passwords do not match.';
        }

        if ($errors !== []) {
            return $this->validationFailed($errors);
        }

        $result = $this->auth->changePassword($userId, $current, $password);

        if ($result['success'] !== true) {
            return $this->validationFailed(['current_password' => $result['message']]);
        }

        // Changing the password is how a user revokes a handset they have lost.
        // Every other live token for this account dies with the change; the one
        // making the request survives so they are not signed out of the app they
        // just used.
        $revoked = $this->auth->revokeOtherApiTokens($userId, $this->auth->apiTokenId());

        return $this->ok([
            'message'              => 'Your password has been changed.',
            'other_sessions_ended' => $revoked,
        ]);
    }

    /**
     * GET /api/v1/auth/me
     *
     * The client calls this on launch to decide whether the stored token is
     * still usable before showing the sign-in screen.
     */
    public function me(): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->unauthorized();
        }

        return $this->ok([
            'user'  => $this->presentUser($user),
            'scope' => [
                'farmer_id'  => $this->auth->farmerId(),
                'officer_id' => $this->auth->officerId(),
                'roles'      => $this->auth->roleCodes(),
                'can'        => $this->permissions(),
            ],
        ]);
    }

    /**
     * Only the fields the client needs, and never the password hash.
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    private function presentUser(array $user): array
    {
        // The users table stores the name in two columns; there is no full_name
        // column, so it is composed here rather than read straight off the row.
        $first = (string) ($user['first_name'] ?? '');
        $last  = (string) ($user['last_name'] ?? '');

        return [
            'id'          => (int) $user['id'],
            'uuid'        => (string) ($user['uuid'] ?? ''),
            'username'    => (string) $user['username'],
            'first_name'  => $first,
            'last_name'   => $last,
            'full_name'   => trim($first . ' ' . $last),
            'email'       => (string) $user['email'],
            'phone'       => (string) ($user['phone'] ?? ''),
            'status'      => (string) ($user['status'] ?? ''),
            'must_change_password' => (int) ($user['must_change_password'] ?? 0) === 1,
        ];
    }

    /**
     * The caller's permission set, so the app can hide actions it cannot use
     * instead of offering a button that would come back as a 403.
     *
     * @return array<int,string>
     */
    private function permissions(): array
    {
        return array_values(array_filter($this->auth->permissions()));
    }
}