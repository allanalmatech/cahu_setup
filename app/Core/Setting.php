<?php
declare(strict_types=1);

namespace Core;

/**
 * Cached access to the system_settings table so configuration such as the
 * institution name, currency or map defaults can be changed by an
 * administrator without a code change.
 */
final class Setting
{
    private static ?Database $db = null;
    /** @var array<string,mixed> */
    private static array $cache = [];

    public static function bind(Database $db): void
    {
        self::$db = $db;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        if (self::$db === null) {
            return $default;
        }

        try {
            $row = self::$db->selectOne(
                'SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1',
                ['key' => $key]
            );
            self::$cache[$key] = $row['setting_value'] ?? $default;
        } catch (\Throwable) {
            self::$cache[$key] = $default;
        }

        return self::$cache[$key];
    }

    public static function put(string $key, mixed $value, string $group = 'general', string $description = '', bool $secret = false): void
    {
        if (self::$db === null) {
            return;
        }

        self::$db->query(
            'INSERT INTO system_settings (setting_key, setting_value, setting_group, description, is_secret)
             VALUES (:key, :value, :group_name, :description, :secret)
             ON DUPLICATE KEY UPDATE setting_value = :value, setting_group = :group_name,
                                     description = :description, is_secret = :secret',
            [
                'key'         => $key,
                'value'       => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                'group_name'  => $group,
                'description' => $description,
                'secret'      => $secret ? 1 : 0,
            ]
        );

        self::$cache[$key] = $value;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function group(string $group): array
    {
        if (self::$db === null) {
            return [];
        }

        return self::$db->select(
            'SELECT * FROM system_settings WHERE setting_group = :group ORDER BY setting_key',
            ['group' => $group]
        );
    }
}
