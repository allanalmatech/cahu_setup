<?php
declare(strict_types=1);

namespace Http\Controllers;

use Core\Audit;
use Core\Controller;
use Core\Csrf;
use Core\Flash;
use Core\HttpException;
use Core\Response;

/**
 * Notifications.
 *
 * Everything arrives through the Notifier service, so this controller only
 * decides who sees what and lets people clear it. Reading is a POST rather than
 * a GET: marking something read changes state, and a link prefetched by a mail
 * client should not silently consume it.
 */
final class NotificationController extends Controller
{
    public function index(): Response
    {
        return $this->render('notifications/index', [
            'pageTitle'     => 'Notifications',
            'notifications' => $this->fetch(50),
            'unread'        => $this->unreadCount(),
        ]);
    }

    /**
     * Poll target for the header bell. Only unread rows are returned, capped so
     * a noisy season cannot grow the payload without bound.
     */
    public function data(): Response
    {
        $rows = $this->db->select(
            'SELECT id, type, title, message, link, created_at
             FROM notifications
             WHERE user_id = :user AND is_read = 0
             ORDER BY created_at DESC LIMIT 15',
            ['user' => $this->auth->id()]
        );

        return $this->json([
            'unread'  => $this->unreadCount(),
            'items'   => array_map(
                static fn (array $row): array => [
                    'id'      => (int) $row['id'],
                    'title'   => (string) $row['title'],
                    'message' => (string) $row['message'],
                    'link'    => $row['link'] === null ? null : url((string) $row['link']),
                    'ago'     => $this->ago((string) $row['created_at']),
                    'type'    => (string) $row['type'],
                ],
                $rows
            ),
        ]);
    }

    public function read(int $id): Response
    {
        $this->assertCsrf();

        $row = $this->db->selectOne(
            'SELECT id FROM notifications WHERE id = :id AND user_id = :user LIMIT 1',
            ['id' => $id, 'user' => $this->auth->id()]
        );

        if ($row === null) {
            throw new HttpException(404, 'That notification could not be found.');
        }

        $this->db->update(
            'notifications',
            ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')],
            'id = :id AND is_read = 0',
            ['id' => $id]
        );

        if ($this->request->wantsJson()) {
            return $this->json(['ok' => true, 'unread' => $this->unreadCount()]);
        }

        return $this->redirect(url('notifications'));
    }

    public function readAll(): Response
    {
        $this->assertCsrf();

        $changed = $this->db->update(
            'notifications',
            ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')],
            'user_id = :user AND is_read = 0',
            ['user' => $this->auth->id()]
        );

        Audit::log('read_all', 'notifications', 'notification', null, null);

        if ($this->request->wantsJson()) {
            return $this->json(['ok' => true, 'unread' => 0, 'changed' => $changed]);
        }

        Flash::success($changed . ' notification(s) marked as read.');

        return $this->redirect(url('notifications'));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetch(int $limit): array
    {
        return $this->db->select(
            'SELECT id, type, title, message, link, entity_type, is_read, read_at, created_at
             FROM notifications
             WHERE user_id = :user
             ORDER BY is_read ASC, created_at DESC
             LIMIT ' . max(1, min(200, $limit)),
            ['user' => $this->auth->id()]
        );
    }

    private function unreadCount(): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :user AND is_read = 0',
            ['user' => $this->auth->id()]
        );
    }

    /**
     * Short relative time for the notification list, kept deliberately coarse:
     * precision here does not help anyone decide what to open.
     */
    private function ago(string $timestamp): string
    {
        $seconds = time() - strtotime($timestamp);

        return match (true) {
            $seconds < 60     => 'just now',
            $seconds < 3600   => intdiv($seconds, 60) . ' min ago',
            $seconds < 86400  => intdiv($seconds, 3600) . ' hr ago',
            $seconds < 604800 => intdiv($seconds, 86400) . ' day(s) ago',
            default           => format_date($timestamp),
        };
    }

    private function assertCsrf(): void
    {
        if (!Csrf::check($this->request->input('_token'))) {
            throw new HttpException(419, 'Your session expired. Please sign in again.');
        }
    }
}