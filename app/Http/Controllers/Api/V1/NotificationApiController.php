<?php
declare(strict_types=1);

namespace Http\Controllers\Api\V1;

use Core\Response;

/**
 * Notification API for the mobile client.
 *
 * A notification is only ever returned to the account it was addressed to. The
 * `channel` column tells the app how to surface it: a row written for the SMS
 * channel has already been delivered as a text message and must not be repeated
 * in-app.
 */
final class NotificationApiController extends ApiController
{
    /**
     * GET /api/v1/notifications
     */
    public function index(): Response
    {
        $params = $this->listParams(50);
        $values = ['user' => $this->auth->id()];
        $where  = ['n.user_id = :user'];

        $type = $this->request->string('type');

        if ($type !== '') {
            $where[]          = 'n.type = :type';
            $values['type']   = $type;
        }

        if ($this->request->bool('unread_only')) {
            $where[] = 'n.is_read = 0';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM notifications n WHERE ' . $whereSql,
            $values
        );

        $rows = $this->db->select(
            'SELECT n.id, n.type, n.title, n.message, n.link, n.entity_type, n.entity_id,
                    n.channel, n.is_read, n.read_at, n.created_at
             FROM notifications n
             WHERE ' . $whereSql . '
             ORDER BY n.is_read ASC, n.created_at DESC
             LIMIT ' . $params['per_page'] . ' OFFSET ' . (($params['page'] - 1) * $params['per_page']),
            $values
        );

        $response = $this->paginate($rows, $total, $params, 'notifications');

        return $this->withUnreadCount($response);
    }

    /**
     * POST /api/v1/notifications/{id}/read
     *
     * A POST, not a GET: marking something read changes state, and a link
     * prefetched by a mail client should not silently consume it.
     */
    public function read(int $id): Response
    {
        $row = $this->db->selectOne(
            'SELECT id FROM notifications WHERE id = :id AND user_id = :user LIMIT 1',
            ['id' => $id, 'user' => $this->auth->id()]
        );

        if ($row === null) {
            return $this->notFound('Notification not found.');
        }

        $this->db->update(
            'notifications',
            ['is_read' => 1, 'read_at' => date('Y-m-d H:i:s')],
            'id = :id AND is_read = 0',
            ['id' => $id]
        );

        return $this->ok([
            'id'     => $id,
            'unread' => $this->unreadCount(),
        ]);
    }

    /**
     * The badge count rides along with the list so the app does not need a
     * second round trip every time it opens the screen.
     */
    private function withUnreadCount(Response $response): Response
    {
        $payload = json_decode((string) $response->body(), true);

        if (is_array($payload)) {
            $payload['meta']['unread'] = $this->unreadCount();

            return Response::json($payload);
        }

        return $response;
    }

    private function unreadCount(): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :user AND is_read = 0',
            ['user' => $this->auth->id()]
        );
    }
}
