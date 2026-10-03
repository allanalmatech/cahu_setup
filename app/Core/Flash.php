<?php
declare(strict_types=1);

namespace Core;

/**
 * One-shot session messages for redirects.
 */
final class Flash
{
    private const KEY = '_flash';

    public static function add(string $type, string $message): void
    {
        $_SESSION[self::KEY][] = ['type' => $type, 'message' => $message];
    }

    public static function success(string $message): void
    {
        self::add('success', $message);
    }

    public static function error(string $message): void
    {
        self::add('danger', $message);
    }

    public static function warning(string $message): void
    {
        self::add('warning', $message);
    }

    public static function info(string $message): void
    {
        self::add('info', $message);
    }

    /**
     * @return array<int,array{type:string,message:string}>
     */
    public static function pull(): array
    {
        $messages = $_SESSION[self::KEY] ?? [];
        unset($_SESSION[self::KEY]);

        return $messages;
    }

    /**
     * Keeps submitted values across a validation redirect.
     *
     * @param array<string,mixed> $data
     */
    public static function withInput(array $data): void
    {
        unset($data['_token'], $data['password'], $data['password_confirmation'], $data['current_password']);

        $_SESSION['_old_input'] = $data;
    }

    /**
     * Returns submitted values kept across a validation redirect. The value is
     * cached for the rest of the request so a form with many fields can read
     * it repeatedly.
     *
     * @return array<string,mixed>
     */
    public static function input(): array
    {
        if (self::$input === null) {
            self::$input = $_SESSION['_old_input'] ?? [];
            unset($_SESSION['_old_input']);
        }

        return self::$input;
    }

    private static ?array $input = null;

    /** @var array<string,string> */
    public static function errors(): array
    {
        if (self::$errors === null) {
            self::$errors = $_SESSION['_errors'] ?? [];
            unset($_SESSION['_errors']);
        }

        return self::$errors;
    }

    private static ?array $errors = null;
}
