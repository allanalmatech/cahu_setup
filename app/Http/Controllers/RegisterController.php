<?php
declare(strict_types=1);

namespace Http\Controllers;

use Core\Controller;
use Core\Csrf;
use Core\Flash;
use Core\HttpException;
use Core\Response;
use Services\Mailer;

final class RegisterController extends Controller
{
    public function showRegistration(): Response
    {
        return $this->render('auth/register', ['pageTitle' => 'Create an Account'], 'layouts/auth');
    }

    public function register(): Response
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please try again.');
        }

        // Validation and the insert live in the shared registration helper on
        // the auth service, which POST /api/v1/auth/register also calls, so the
        // web and the handset cannot accept different sets of signups.
        $result = $this->auth->createPendingRegistration(
            $this->request->string('first_name'),
            $this->request->string('last_name'),
            $this->request->string('email'),
            $this->request->string('password'),
            $this->request->string('password_confirmation')
        );

        if ($result['success'] !== true) {
            return $this->backWithErrors(url('register'), $result['errors']);
        }

        $this->sendWelcomeEmail(
            $this->request->string('email'),
            $this->request->string('first_name')
        );

        Flash::success($result['message']);

        return $this->redirect(url('login'));
    }

    /**
     * Sends the welcome message.
     *
     * A mail failure must not undo a registration that already succeeded, so
     * the outcome is logged and the signup is still treated as complete. The
     * link is absolute because url() returns a path relative to the
     * application root, which is unusable from an email client.
     */
    private function sendWelcomeEmail(string $email, string $firstName): void
    {
        $loginUrl = rtrim((string) $this->config('app.url'), '/') . '/' . ltrim((string) url('login'), '/');

        try {
            $html = $this->view->render('emails/registration-pending', [
                'firstName' => $firstName,
                'loginUrl'  => $loginUrl,
                'subject'   => 'Your CAHU account is awaiting approval',
                'preheader' => 'Your registration was received. An administrator will review it and assign your access.',
            ], 'emails/layout');
        } catch (\Throwable $exception) {
            \Core\Logger::error('Welcome email could not be rendered: ' . $exception->getMessage());

            return;
        }

        $sent = (new Mailer($this->db))->send(
            $email,
            'Welcome to ' . $this->config('app.name'),
            $html
        );

        if ($sent === false) {
            \Core\Logger::info('Welcome email not delivered to ' . $email . '.');
        }
    }
}
