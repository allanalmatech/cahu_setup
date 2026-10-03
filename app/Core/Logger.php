<?php
declare(strict_types=1);

namespace Core;

/**
 * File based logger. Technical errors (SQL failures, stack traces, file paths)
 * go here; they are never shown to end users.
 */
final class Logger
{
    private static ?string $path = null;

    public static function bind(string $path): void
    {
        self::$path = rtrim($path, '/\\');

        if (!is_dir(self::$path)) {
            @mkdir(self::$path, 0770, true);
        }
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        if (self::$path === null) {
            return;
        }

        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            $level,
            $message,
            $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES)
        );

        @file_put_contents(self::$path . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function exception(\Throwable $exception): void
    {
        self::error($exception->getMessage(), [
            'type'  => $exception::class,
            'file'  => $exception->getFile() . ':' . $exception->getLine(),
            'trace' => array_slice(explode("\n", $exception->getTraceAsString()), 0, 10),
        ]);
    }
}
