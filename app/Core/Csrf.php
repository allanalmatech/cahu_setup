<?php
declare(strict_types=1);

namespace Core;

/**
 * Per-session CSRF tokens. A single token per session is used across all forms
 * and AJAX requests; state changing requests are rejected without it.
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function check(?string $token): bool
    {
        return is_string($token)
            && $token !== ''
            && !empty($_SESSION[self::KEY])
            && hash_equals((string) $_SESSION[self::KEY], $token);
    }

    public static function rotate(): void
    {
        $_SESSION[self::KEY] = bin2hex(random_bytes(32));
    }
}
