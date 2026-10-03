<?php
declare(strict_types=1);

namespace Http\Controllers;

use Core\Audit;
use Core\Auth;
use Core\Controller;
use Core\Csrf;
use Core\Flash;
use Core\HttpException;
use Core\Logger;
use Core\Response;
use Core\Setting;
use Services\Mailer;

/**
 * Sign in, sign out, password reset and account activation.
 */
final class AuthController extends Controller
{
    private const RESET_LIFETIME_MINUTES = 60;

    public function showLogin(): Response
    {
        return $this->render('auth/login', ['pageTitle' => 'Sign in'], 'layouts/auth');
    }

    public function login(): Response
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please try again.');
        }

        $result = $this->auth->attempt(
            $this->request->string('login'),
            (string) $this->request->input('password', ''),
            $this->request
        );

        if ($result['success'] !== true) {
            return $this->backWithErrors(url('login'), ['login' => $result['message']]);
        }

        Csrf::rotate();
        Flash::success('Welcome back, ' . ($result['user']['first_name'] ?? 'colleague') . '.');

        $target = $_SESSION['_intended'] ?? url('dashboard');
        unset($_SESSION['_intended']);

        return $this->redirect($target);
    }

    public function logout(): Response
    {
        if ($this->request->isPost() && !Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }

        if ($this->auth->check()) {
            Audit::log('logout', 'auth', 'user', $this->auth->id());
        }

        $this->auth->logout();
        Flash::info('You have been signed out.');

        return $this->redirect(url('login'));
    }

    public function showForgotPassword(): Response
    {
        return $this->render('auth/forgot-password', ['pageTitle' => 'Reset password'], 'layouts/auth');
    }

    public function sendResetLink(): Response
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please try again.');
        }

        $identity = $this->request->string('identity');

        if ($identity === '') {
            return $this->backWithErrors(url('forgot-password'), ['identity' => 'Enter your email address or username.']);
        }

        $user = $this->db->selectOne(
            'SELECT id, email, first_name FROM users
             WHERE (email = :identity OR username = :identity) AND deleted_at IS NULL AND status = :status
             LIMIT 1',
            ['identity' => $identity, 'status' => 'active']
        );

        // The response is identical whether or not the account exists, so the
        // form cannot be used to discover registered addresses.
        if ($user !== null) {
            $this->issueResetToken((int) $user['id']);
        }

        Flash::success('If that account exists, a password reset link has been sent. Please check your inbox.');

        return $this->redirect(url('login'));
    }

    private function issueResetToken(int $userId): void
    {
        // A new request supersedes any earlier one that has not been used, so
        // an inbox cannot accumulate several live links for the same account.
        $this->db->update(
            'password_resets',
            ['used_at' => date('Y-m-d H:i:s')],
            'user_id = :uid AND used_at IS NULL',
            ['uid' => $userId]
        );

        $token = bin2hex(random_bytes(32));

        $this->db->insert('password_resets', [
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + self::RESET_LIFETIME_MINUTES * 60),
            'ip_address' => $this->request->ip(),
        ]);

        $user = $this->db->selectOne('SELECT email, first_name FROM users WHERE id = :id', ['id' => $userId]);

        $link = $this->absoluteUrl('reset-password/' . $token);

        $body = $this->renderResetEmail((string) $user['first_name'], $link);

        $sent = (new Mailer($this->db))->send(
            (string) $user['email'],
            'Reset your ' . $this->config('app.name') . ' password',
            $body
        );

        // Without working mail the link is written to the log so a system
        // administrator can complete the reset manually.
        if ($sent === false) {
            Logger::info('Password reset link for user ' . $userId . ': ' . $link);
        }

        Audit::log('password_reset_requested', 'auth', 'user', $userId);
    }

    /**
     * Builds a link that works in an email client.
     *
     * url() returns a path relative to the application root, which is right for
     * a link inside a page but useless in an email: the recipient has no
     * current request to be relative to. APP_URL supplies the host.
     */
    private function absoluteUrl(string $path): string
    {
        return rtrim((string) $this->config('app.url'), '/') . '/' . ltrim($path, '/');
    }

    /**
     * The reset email, rendered through the shared HTML layout so it matches
     * every other message the platform sends.
     */
    private function renderResetEmail(string $firstName, string $link): string
    {
        try {
            return $this->view->render('emails/password-reset', [
                'firstName' => $firstName,
                'link'      => $link,
                'minutes'   => self::RESET_LIFETIME_MINUTES,
                'appName'   => (string) $this->config('app.name'),
                'subject'   => 'Reset your password',
                'preheader' => 'Your password reset link expires in ' . self::RESET_LIFETIME_MINUTES . ' minutes.',
            ], 'emails/layout');
        } catch (\Throwable) {
            // A mail template must never cost someone their password reset, so
            // a plain-text fallback is used if the HTML cannot be rendered.
            return "Hello {$firstName},\n\n"
                . "Use the link below to choose a new password. It expires in "
                . self::RESET_LIFETIME_MINUTES . " minutes.\n\n"
                . $link . "\n\n"
                . "If you did not request this, you can ignore this message.\n";
        }
    }

    public function showResetPassword(string $token): Response
    {
        $userId = $this->resolveResetToken($token);

        if ($userId === null) {
            Flash::error('That reset link is invalid or has expired. Please request a new one.');
            return $this->redirect(url('forgot-password'));
        }

        return $this->render('auth/reset-password', [
            'pageTitle' => 'Choose a new password',
            'token'     => $token,
        ], 'layouts/auth');
    }

    public function resetPassword(string $token): Response
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please try again.');
        }

        $userId = $this->resolveResetToken($token);

        if ($userId === null) {
            Flash::error('That reset link is invalid or has expired. Please request a new one.');
            return $this->redirect(url('forgot-password'));
        }

        $password = (string) $this->request->input('password', '');
        $confirm  = (string) $this->request->input('password_confirmation', '');

        $errors = [];

        $strength = Auth::passwordStrength($password);
        if ($strength['valid'] !== true) {
            $errors['password'] = $strength['message'];
        }

        if ($password !== $confirm) {
            $errors['password_confirmation'] = 'The two passwords do not match.';
        }

        $inUse = $this->db->scalar('SELECT 1 FROM password_resets WHERE user_id = :uid AND used_at IS NULL LIMIT 1', ['uid' => $userId]) === null;
        if ($inUse) {
            Flash::error('That reset link has already been used. Please request a new one.');
            return $this->redirect(url('forgot-password'));
        }

        if ($errors !== []) {
            return $this->backWithErrors(url('reset-password/' . $token), $errors);
        }

        $this->db->transaction(function () use ($userId, $password, $token): void {
            $this->db->update('users', [
                'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
                'password_changed_at'  => date('Y-m-d H:i:s'),
                'must_change_password' => 0,
            ], 'id = :id', ['id' => $userId]);

            $this->db->update(
                'password_resets',
                ['used_at' => date('Y-m-d H:i:s')],
                'token_hash = :hash',
                ['hash' => hash('sha256', $token)]
            );
        });

        Audit::log('password_reset_completed', 'auth', 'user', $userId);

        Flash::success('Your password has been updated. You can now sign in.');

        return $this->redirect(url('login'));
    }

    /**
     * Returns the user id for a valid, unexpired, unused reset token.
     */
    private function resolveResetToken(string $token): ?int
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT user_id FROM password_resets
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1',
            ['hash' => hash('sha256', $token)]
        );

        return $row === null ? null : (int) $row['user_id'];
    }

}
