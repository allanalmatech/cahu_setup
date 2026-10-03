<?php
declare(strict_types=1);

namespace Core;

/**
 * Audit trail writer. Records who changed what, when, from where, and the
 * before/after values for the affected record.
 */
final class Audit
{
    private static ?Database $db = null;
    private static ?array $user = null;
    private static ?Request $request = null;

    public static function bind(Database $db, ?array $user, ?Request $request): void
    {
        self::$db      = $db;
        self::$user    = $user;
        self::$request = $request;
    }

    public static function log(
        string $action,
        string $module,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $entityLabel = null,
        ?array $old = null,
        ?array $new = null
    ): void {
        if (self::$db === null) {
            return;
        }

        try {
            self::$db->insert('audit_logs', [
                'user_id'      => self::$user['id'] ?? null,
                'user_name'    => self::$user['username'] ?? 'system',
                'action'       => $action,
                'module'       => $module,
                'entity_type'  => $entityType,
                'entity_id'    => $entityId,
                'entity_label' => $entityLabel,
                'old_values'   => $old === null ? null : json_encode(self::diff($old, $new ?? []), JSON_UNESCAPED_UNICODE),
                'new_values'   => $new === null ? null : json_encode(self::redact($new), JSON_UNESCAPED_UNICODE),
                'ip_address'   => self::$request?->ip(),
                'user_agent'   => self::$request?->userAgent(),
            ]);
        } catch (\Throwable $exception) {
            Logger::error('Audit write failed: ' . $exception->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     * @return array<string,mixed>
     */
    private static function diff(array $old, array $new): array
    {
        $redactedOld = self::redact($old);

        return array_intersect_key($redactedOld, self::redact($new));
    }

    /**
     * Removes fields that must never reach the audit table.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('/password|secret|token|api_key|hash/i', (string) $key) === 1) {
                $data[$key] = '********';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
