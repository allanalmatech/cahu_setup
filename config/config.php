<?php
declare(strict_types=1);

/**
 * Loads environment variables from config/.env and returns the application
 * configuration array. No credential is ever hard-coded in the source tree:
 * everything sensitive comes from the .env file which stays outside version
 * control.
 */

$root = dirname(__DIR__);

$envFile = $root . '/config/.env';

if (!is_file($envFile)) {
    http_response_code(500);
    exit(
        "Configuration file not found.\n\n"
        . "Copy config/.env.example to config/.env and fill in your database details:\n"
        . "  cp config/.env.example config/.env\n"
    );
}

$loader = static function (string $path): void {
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        if (
            strlen($value) >= 2
            && (($value[0] === '"' && str_ends_with($value, '"'))
                || ($value[0] === "'" && str_ends_with($value, "'")))
        ) {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
};

$loader($envFile);

// An empty value in .env is treated as "not set" so a blank UPLOAD_PATH cannot
// silently point uploads at the current working directory. Defaults for
// genuinely optional values (base path, database password) are themselves blank,
// so this stays safe for them.
$env  = static fn (string $key, mixed $default = null): mixed => (($value = getenv($key)) === false || $value === '') ? $default : $value;
$bool = static fn (string $key, bool $default = false): bool => filter_var($env($key, $default), FILTER_VALIDATE_BOOL);

return [
    'app' => [
        'name'        => (string) $env('APP_NAME', 'CAHU Agribusiness Platform'),
        'short_name'  => (string) $env('APP_SHORT_NAME', 'CAHU'),
        'env'         => (string) $env('APP_ENV', 'production'),
        'debug'       => $bool('APP_DEBUG', false),
        'url'         => rtrim((string) $env('APP_URL', 'http://localhost'), '/'),
        'base_path'   => rtrim((string) $env('APP_BASE_PATH', ''), '/'),
        'timezone'    => (string) $env('APP_TIMEZONE', 'Africa/Kampala'),
        'locale'      => (string) $env('APP_LOCALE', 'en_UG'),
        'currency'    => (string) $env('APP_CURRENCY', 'UGX'),
        'app_key'     => (string) $env('APP_KEY', ''),
        'layout'      => 'layouts/app',
        'version'     => '1.0.0',

        'session_name'     => (string) $env('SESSION_NAME', 'CAHUSESSID'),
        'session_lifetime' => (int) $env('SESSION_LIFETIME', 1800),
        'session_secure'   => $bool('SESSION_SECURE_COOKIE', false),
        'max_login_attempts' => (int) $env('MAX_LOGIN_ATTEMPTS', 5),
    ],

    'database' => [
        'host'     => (string) $env('DB_HOST', '127.0.0.1'),
        'port'     => (int) $env('DB_PORT', 3306),
        'database' => (string) $env('DB_DATABASE', 'cahu_platform'),
        'username' => (string) $env('DB_USERNAME', 'root'),
        'password' => (string) $env('DB_PASSWORD', ''),
        'timezone' => (string) $env('APP_TIMEZONE', 'Africa/Kampala'),
    ],

    'uploads' => [
        'path'          => (string) $env('UPLOAD_PATH', $root . '/storage/uploads'),
        'url'           => (string) $env('UPLOAD_URL', '/storage/uploads'),
        'max_image_kb'  => (int) $env('MAX_IMAGE_KB', 4096),
        'max_doc_kb'    => (int) $env('MAX_DOC_KB', 10240),
        'max_video_kb'  => (int) $env('MAX_VIDEO_KB', 102400),
    ],

    'sms' => [
        'enabled'   => $bool('SMS_ENABLED', false),
        'provider'  => (string) $env('SMS_PROVIDER', 'generic'),
        'api_url'   => (string) $env('SMS_API_URL', ''),
        'api_key'   => (string) $env('SMS_API_KEY', ''),
        'sender_id' => (string) $env('SMS_SENDER_ID', 'CAHU'),
    ],

    'mail' => [
        'transport'  => (string) $env('MAIL_TRANSPORT', 'smtp'),
        // Deliberately blank: a default hostname would silently send mail through
        // somebody else's server. The installer writes real values into .env, and
        // Services\Mailer reports "not configured" until they are present.
        'host'       => (string) $env('MAIL_SMTP_HOST', ''),
        'port'       => (int) $env('MAIL_SMTP_PORT', 465),
        'username'   => (string) $env('MAIL_SMTP_USER', ''),
        'password'   => (string) $env('MAIL_SMTP_PASS', ''),
        // Port 465 negotiates TLS during the handshake, so "ssl" is the correct
        // default for it. Port 587 needs "tls" (STARTTLS) instead.
        'encryption' => (string) $env('MAIL_SMTP_ENCRYPTION', 'ssl'),
        'auth'       => $bool('MAIL_SMTP_AUTH', true),
        'from'       => (string) $env('MAIL_FROM_EMAIL', ''),
        'from_name'  => (string) $env('MAIL_FROM_NAME', $env('APP_SHORT_NAME', 'CAHU')),
        'reply_to'   => (string) $env('MAIL_REPLY_TO', ''),
        'helo'       => (string) $env('MAIL_HELO', ''),
    ],

    'map' => [
        'tile_url'      => (string) $env('MAP_TILE_URL', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'attribution'   => (string) $env('MAP_ATTRIBUTION', '&copy; OpenStreetMap contributors'),
        'default_lat'   => (float) $env('MAP_DEFAULT_LAT', 1.3733),
        'default_lng'   => (float) $env('MAP_DEFAULT_LNG', 32.8883),
        'default_zoom'  => (int) $env('MAP_DEFAULT_ZOOM', 7),
    ],

    'paths' => [
        'root'    => $root,
        'app'     => $root . '/app',
        'views'   => $root . '/app/Views',
        'storage' => $root . '/storage',
        'routes'  => $root . '/app/routes.php',
    ],
];
