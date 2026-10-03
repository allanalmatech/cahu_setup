<?php
declare(strict_types=1);

namespace Services;

use Core\Database;
use Core\Setting;

/**
 * Central notification writer.
 *
 * Notifications are fanned out to the people who can act on them: the farmer
 * owning the record (via their portal account), the assigned field officer, and
 * optionally every user holding a given role. SMS is sent in addition to the
 * in-system notification when the recipient has a phone number on file and
 * SMS is enabled.
 */
final class Notifier
{
    public function __construct(private Database $db, private ?Sms $sms = null)
    {
        $this->sms ??= new Sms($db);
    }

    /**
     * @param array{
     *     type:string, title:string, message:string,
     *     user_id?:int|null, role_id?:int|null, farmer_id?:int|null, officer_id?:int|null,
     *     link?:string|null, entity_type?:string|null, entity_id?:int|null,
     *     channels?:array<int,string>, dedupe_key?:string|null
     * } $data
     * @return int Number of notification rows written
     */
    public function notify(array $data): int
    {
        $channels = $data['channels'] ?? ['system'];
        $created  = 0;

        $recipients = $this->resolveRecipients($data);

        foreach ($recipients as $recipient) {
            if (isset($data['dedupe_key']) && $data['dedupe_key'] !== null) {
                $exists = $this->db->scalar(
                    'SELECT 1 FROM notifications WHERE dedupe_key = :key LIMIT 1',
                    ['key' => $data['dedupe_key']]
                );

                if ($exists !== null) {
                    return $created;
                }
            }

            $this->db->insert('notifications', [
                'user_id'     => $recipient['user_id'],
                'role_id'     => $recipient['role_id'] ?? null,
                'farmer_id'   => $data['farmer_id'] ?? null,
                'officer_id'  => $data['officer_id'] ?? null,
                'type'        => $data['type'],
                'title'       => $data['title'],
                'message'     => $data['message'],
                'link'        => $data['link'] ?? null,
                'entity_type' => $data['entity_type'] ?? null,
                'entity_id'   => $data['entity_id'] ?? null,
                'channel'     => 'system',
                'dedupe_key'  => $data['dedupe_key'] ?? null,
            ]);

            $created++;

            if (in_array('sms', $channels, true) && $recipient['phone'] !== null) {
                $this->sms->send(
                    $recipient['phone'],
                    $data['title'] . ': ' . $data['message'],
                    [
                        'recipient_type' => $recipient['type'],
                        'recipient_id'   => $recipient['user_id'],
                        'related_type'   => $data['entity_type'] ?? null,
                        'related_id'     => $data['entity_id'] ?? null,
                    ]
                );

                $this->db->insert('notifications', [
                    'user_id'     => $recipient['user_id'],
                    'farmer_id'   => $data['farmer_id'] ?? null,
                    'officer_id'  => $data['officer_id'] ?? null,
                    'type'        => $data['type'],
                    'title'       => $data['title'],
                    'message'     => $data['message'],
                    'link'        => $data['link'] ?? null,
                    'entity_type' => $data['entity_type'] ?? null,
                    'entity_id'   => $data['entity_id'] ?? null,
                    'channel'     => 'sms',
                    'dedupe_key'  => $data['dedupe_key'] === null ? null : $data['dedupe_key'] . ':sms',
                ]);
            }
        }

        return $created;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<int,array{user_id:?int,role_id:?int,phone:?string,type:string}>
     */
    private function resolveRecipients(array $data): array
    {
        $recipients = [];

        $userId = $data['user_id'] ?? null;

        if ($userId === null && isset($data['farmer_id'])) {
            $row = $this->db->selectOne(
                'SELECT u.id, u.phone FROM farmers f
                 LEFT JOIN users u ON u.id = f.user_id AND u.status = \'active\'
                 WHERE f.id = :id LIMIT 1',
                ['id' => $data['farmer_id']]
            );

            if ($row !== null && $row['id'] !== null) {
                $userId      = (int) $row['id'];
                $phone       = $row['phone'];
            } else {
                $phone = $this->db->scalar('SELECT phone FROM farmers WHERE id = :id', ['id' => $data['farmer_id']]);
            }

            $recipients[] = ['user_id' => $userId, 'role_id' => null, 'phone' => $phone, 'type' => 'farmer'];
        }

        if (isset($data['officer_id']) && $data['officer_id'] !== null) {
            $row = $this->db->selectOne(
                'SELECT u.id, u.phone FROM field_officers o
                 LEFT JOIN users u ON u.id = o.user_id AND u.status = \'active\'
                 WHERE o.id = :id LIMIT 1',
                ['id' => $data['officer_id']]
            );

            if ($row !== null) {
                $recipients[] = [
                    'user_id' => $row['id'] === null ? null : (int) $row['id'],
                    'role_id' => null,
                    'phone'   => $row['phone'],
                    'type'    => 'officer',
                ];
            }
        }

        if (isset($data['role_id']) && $data['role_id'] !== null) {
            $users = $this->db->select(
                'SELECT u.id, u.phone FROM users u
                 INNER JOIN user_roles ur ON ur.user_id = u.id
                 WHERE ur.role_id = :role AND u.status = \'active\'',
                ['role' => $data['role_id']]
            );

            foreach ($users as $user) {
                $recipients[] = [
                    'user_id' => (int) $user['id'],
                    'role_id' => null,
                    'phone'   => $user['phone'],
                    'type'    => 'user',
                ];
            }
        }

        if ($userId !== null && !isset($data['farmer_id'])) {
            $phone = $this->db->scalar('SELECT phone FROM users WHERE id = :id', ['id' => $userId]);
            $recipients[] = ['user_id' => (int) $userId, 'role_id' => null, 'phone' => $phone, 'type' => 'user'];
        }

        if (isset($data['user_id']) && $data['user_id'] !== null) {
            $phone = $this->db->scalar('SELECT phone FROM users WHERE id = :id', ['id' => $data['user_id']]);
            $recipients[] = ['user_id' => (int) $data['user_id'], 'role_id' => null, 'phone' => $phone, 'type' => 'user'];
        }

        return $recipients;
    }

    /**
     * Resolves the user id that should receive role-wide system alerts.
     */
    public function roleUserId(string $roleCode): ?int
    {
        $id = $this->db->scalar(
            'SELECT u.id FROM users u
             INNER JOIN user_roles ur ON ur.user_id = u.id
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE r.code = :code AND u.status = \'active\'
             ORDER BY u.id LIMIT 1',
            ['code' => $roleCode]
        );

        return $id === null ? null : (int) $id;
    }
}
