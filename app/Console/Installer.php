<?php
declare(strict_types=1);

namespace Console;

/**
 * The installation itself, independent of how it was started.
 *
 * The six steps a new server needs, in an order where each depends only on the
 * ones before it:
 *
 *   1. Confirm PHP and the extensions the platform needs are present.
 *   2. Write config/.env, generating APP_KEY and preserving existing values.
 *   3. Create the database and import database/schema.sql.
 *   4. Seed permissions, roles, reference data and a super administrator.
 *   5. Create the writable upload, cache and log directories.
 *   6. Optionally send a real email to prove SMTP works.
 *
 * It never overwrites config/.env without permission, and never drops a
 * database: importing the schema into a database that already has tables is
 * reported and skipped unless the operator confirms it.
 *
 * Why this is a class and not a script
 * ------------------------------------
 * This used to be install.php in full, with the answers read from STDIN and the
 * progress printed with echo, which meant it could only ever run as
 * `php install.php`. The browser installer needs the same six steps, and there
 * is no way to reach them from an HTTP request without either duplicating all
 * of this or shelling out to a PHP binary that a shared host may not have. So
 * the steps live here, answers arrive in an options array, progress goes to a
 * callback, and both entry points - install.php and public/install.php - are
 * thin front ends over this one implementation.
 *
 * Nothing in here reads input or writes output directly, and nothing depends on
 * the SAPI, so the identical code path runs from a terminal and from a browser.
 */
final class Installer
{
    public const VERSION = '1.0.0';

    /** Extensions the platform cannot run without, and what each one is for. */
    private const REQUIRED_EXTENSIONS = [
        'pdo_mysql' => 'MySQL database access',
        'mbstring'  => 'multibyte string handling',
        'json'      => 'JSON encoding for the API',
        'openssl'   => 'random_bytes() and TLS for SMTP',
    ];

    /** Written by web install.php to stop the installer running a second time. */
    public const LOCK_FILE = 'storage/install.lock';

    private string $root;

    /** @var array<string, mixed> */
    private array $options;

    /** @var callable(string, string): void */
    private $out;

    private bool $failed = false;

    /** @var array<string, string> The values that end up in config/.env. */
    private array $values = [];

    /** @var array<string, mixed> Facts the caller needs afterwards. */
    private array $result = [];

    /**
     * @param array<string, mixed> $options
     *        url, scheme, domain, db_host, db_port, db_name, db_user, db_pass,
     *        admin_user, admin_email, admin_pass, skip_db, skip_seed, skip_smtp,
     *        demo, demo_pass, force, import_schema
     * @param callable(string, string): void $out Receives (message, level) where
     *        level is one of step, info, ok, warn or error.
     */
    public function __construct(array $options = [], ?callable $out = null, ?string $root = null)
    {
        $this->root    = $root ?? dirname(__DIR__, 2);
        $this->options = $options;
        $this->out     = $out ?? static function (string $message, string $level = 'info'): void {
            echo $message . PHP_EOL;
        };
    }

    /* ------------------------------------------------------------------ */
    /* Output                                                              */
    /* ------------------------------------------------------------------ */

    private function line(string $message, string $level = 'info'): void
    {
        ($this->out)($message, $level);
    }

    private function ok(string $message): void
    {
        $this->line($message, 'ok');
    }

    private function warn(string $message): void
    {
        $this->line($message, 'warn');
    }

    /**
     * A fatal condition: printed, remembered, and reported through the exit
     * status so the installer is safe to run from a deployment script. Steps
     * that cannot continue throw instead; this is for conditions worth pressing
     * on with.
     */
    private function fail(string $message): void
    {
        $this->failed = true;

        $this->line($message, 'error');
    }

    private function step(string $title): void
    {
        $this->line($title, 'step');
    }

    public function failed(): bool
    {
        return $this->failed;
    }

    /**
     * @return array<string, mixed>
     */
    public function result(): array
    {
        return $this->result;
    }

    private function envPath(string $relative): string
    {
        return $this->root . '/' . ltrim($relative, '/');
    }

    private function flag(string $name): bool
    {
        return ($this->options[$name] ?? false) === true;
    }

    private function given(string $name): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /* ------------------------------------------------------------------ */
    /* Run                                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Runs every step. Returns a summary; throws only when a step cannot
     * continue at all.
     *
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $this->checkPrerequisites();
        $this->writeEnvironment();
        $this->prepareDatabase();
        $this->seedData();
        $this->createDirectories();
        $this->verifyMail();

        $this->result['failed'] = $this->failed;

        return $this->result;
    }

    /* ------------------------------------------------------------------ */
    /* 1. Prerequisites                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{php: string, extensions: array<string, bool>, missing: string[]}
     */
    public function checkPrerequisites(): array
    {
        $this->step('1. Checking prerequisites');

        /*
         * 8.0 is the floor because that is what the application is written
         * against: str_starts_with/str_contains/str_ends_with, match, nullsafe
         * calls and constructor promotion all arrived in 8.0, and nothing in the
         * tree uses an 8.1 or 8.2 only feature. Refusing to install on the PHP
         * that XAMPP, WAMP and MAMP all ship would only stop people using the
         * version their own server arrived with.
         */
        $minimumPhp = '8.0.0';

        if (version_compare(PHP_VERSION, $minimumPhp, '<')) {
            throw new \RuntimeException(
                'PHP ' . $minimumPhp . ' or newer is required; this is ' . PHP_VERSION . '.'
            );
        }

        $this->ok('PHP ' . PHP_VERSION);

        $extensions = [];
        $missing    = [];

        foreach (self::REQUIRED_EXTENSIONS as $extension => $why) {
            if (extension_loaded($extension)) {
                $extensions[$extension] = true;
                $this->ok('ext/' . $extension . ' - ' . $why);
            } else {
                $extensions[$extension] = false;
                $missing[]              = $extension;
                $this->fail('ext/' . $extension . ' is missing - required for ' . $why . '.');
            }
        }

        if ($missing !== []) {
            throw new \RuntimeException(
                'Install the missing extension(s) [' . implode(', ', $missing) . '] and run this again.'
            );
        }

        if (!function_exists('fsockopen')) {
            $this->warn('fsockopen() is unavailable, so email cannot be sent from this server.');
        }

        $this->result['php']        = PHP_VERSION;
        $this->result['extensions'] = $extensions;

        return ['php' => PHP_VERSION, 'extensions' => $extensions, 'missing' => $missing];
    }

    /* ------------------------------------------------------------------ */
    /* 2. Environment file                                                */
    /* ------------------------------------------------------------------ */

    private function existingEnv(): array
    {
        $values = [];
        $file   = $this->envPath('config/.env');

        if (!is_file($file)) {
            return $values;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $values[trim($key)] = trim(trim($value), "\"'");
        }

        // A minimal or hand-written .env has only some of these, and an absent
        // key means "not set" rather than an undefined index. Filling them in
        // here keeps every caller that reads them with ?: or ?? honest, and
        // stops a sparse file producing a wall of warnings on the success page
        // instead of the finished install it actually is.
        return $values + array_fill_keys([
            'APP_NAME', 'APP_SHORT_NAME', 'APP_ENV', 'APP_DEBUG', 'APP_URL',
            'APP_BASE_PATH', 'APP_TIMEZONE', 'APP_LOCALE', 'APP_CURRENCY', 'APP_KEY',
            'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
            'SESSION_NAME', 'SESSION_LIFETIME', 'SESSION_SECURE_COOKIE', 'MAX_LOGIN_ATTEMPTS',
            'UPLOAD_PATH', 'UPLOAD_URL', 'MAX_IMAGE_KB', 'MAX_DOC_KB', 'MAX_VIDEO_KB',
            'SMS_ENABLED', 'SMS_PROVIDER', 'SMS_API_URL', 'SMS_API_KEY', 'SMS_SENDER_ID',
            'MAIL_TRANSPORT', 'MAIL_SMTP_HOST', 'MAIL_SMTP_PORT', 'MAIL_SMTP_USER',
            'MAIL_SMTP_PASS', 'MAIL_SMTP_ENCRYPTION', 'MAIL_SMTP_AUTH', 'MAIL_FROM_EMAIL',
            'MAIL_FROM_NAME', 'MAIL_REPLY_TO', 'MAIL_HELO',
            'MAP_TILE_URL', 'MAP_ATTRIBUTION',
            'MAP_DEFAULT_LAT', 'MAP_DEFAULT_LNG', 'MAP_DEFAULT_ZOOM',
        ], '');
    }

    private function writeEnvironment(): void
    {
        $this->step('2. Configuring the environment');

        $existing = $this->existingEnv();
        $envFile  = $this->envPath('config/.env');

        $this->values = [
            'APP_NAME'        => $existing['APP_NAME'] ?: 'CAHU Agribusiness Platform',
            'APP_SHORT_NAME'  => $existing['APP_SHORT_NAME'] ?: 'CAHU',
            'APP_ENV'         => $existing['APP_ENV'] ?: 'production',
            'APP_DEBUG'       => $existing['APP_DEBUG'] ?: 'false',
            'APP_TIMEZONE'    => $existing['APP_TIMEZONE'] ?: 'Africa/Kampala',
            'APP_LOCALE'      => $existing['APP_LOCALE'] ?: 'en_UG',
            'APP_CURRENCY'    => $existing['APP_CURRENCY'] ?: 'UGX',
            'MAIL_TRANSPORT'  => $existing['MAIL_TRANSPORT'] ?? '',
            'MAIL_FROM_NAME'  => $existing['MAIL_FROM_NAME'] ?: 'CAHU',
            'MAIL_REPLY_TO'   => $existing['MAIL_REPLY_TO'] ?? '',
            'MAIL_HELO'       => $existing['MAIL_HELO'] ?? '',
            'UPLOAD_PATH'     => $existing['UPLOAD_PATH'] ?? '',
            'SMS_ENABLED'     => $existing['SMS_ENABLED'] ?: 'false',
            'SMS_PROVIDER'    => $existing['SMS_PROVIDER'] ?: 'generic',
            'SMS_API_URL'     => $existing['SMS_API_URL'] ?? '',
            'SMS_API_KEY'     => $existing['SMS_API_KEY'] ?? '',
            'MAIL_SMTP_AUTH'  => $existing['MAIL_SMTP_AUTH'] ?? '',
            'SESSION_NAME'    => $existing['SESSION_NAME'] ?: 'CAHUSESSID',
            'SESSION_LIFETIME' => $existing['SESSION_LIFETIME'] ?: '1800',
            'MAX_LOGIN_ATTEMPTS' => $existing['MAX_LOGIN_ATTEMPTS'] ?: '5',
            'UPLOAD_URL'      => $existing['UPLOAD_URL'] ?: '/storage/uploads',
            'MAX_IMAGE_KB'    => $existing['MAX_IMAGE_KB'] ?: '4096',
            'MAX_DOC_KB'      => $existing['MAX_DOC_KB'] ?: '10240',
            'MAX_VIDEO_KB'    => $existing['MAX_VIDEO_KB'] ?: '102400',
            'SMS_SENDER_ID'   => $existing['SMS_SENDER_ID'] ?: 'CAHU',
            'MAP_ATTRIBUTION' => $existing['MAP_ATTRIBUTION'] ?: '&copy; OpenStreetMap contributors',
        ];

        // A caller that already knows the answers wins; otherwise the current
        // .env is the default. Both front ends resolve the prompts themselves,
        // so by the time this runs every value is decided.
        $this->values['APP_URL'] = $this->resolveAppUrl($existing);
        $this->values['APP_BASE_PATH'] = $this->resolveBasePath($existing);
        $this->values['DB_HOST']     = $this->given('db_host') ?: ($existing['DB_HOST'] ?: '127.0.0.1');
        $this->values['DB_PORT']     = $this->given('db_port') ?: ($existing['DB_PORT'] ?: '3306');
        $this->values['DB_DATABASE'] = $this->given('db_name') ?: ($existing['DB_DATABASE'] ?: 'cahu_platform');
        $this->values['DB_USERNAME'] = $this->given('db_user') ?: ($existing['DB_USERNAME'] ?: 'root');
        $this->values['DB_PASSWORD'] = $this->given('db_pass') ?? ($existing['DB_PASSWORD'] ?? '');

        $appKey = $existing['APP_KEY'] ?? '';

        if ($appKey === '') {
            $appKey = bin2hex(random_bytes(32));
            $this->ok('Generated a new APP_KEY.');
        }

        $this->values['APP_KEY'] = $appKey;

        $mailHost       = $this->given('mail_host') ?? ($existing['MAIL_SMTP_HOST'] ?? '');
        $mailPort       = $this->given('mail_port') ?? ($existing['MAIL_SMTP_PORT'] ?? '465');
        $mailUser       = $this->given('mail_user') ?? ($existing['MAIL_SMTP_USER'] ?? '');
        $mailPass       = $this->given('mail_pass') ?? ($existing['MAIL_SMTP_PASS'] ?? '');
        $mailEncryption = strtolower($this->given('mail_encryption') ?? ($existing['MAIL_SMTP_ENCRYPTION'] ?: 'ssl'));
        $mailFrom       = $this->given('mail_from') ?? ($existing['MAIL_FROM_EMAIL'] ?? '');

        if ($mailHost !== '' && $mailPort !== '') {
            if ($mailEncryption === 'ssl' && $mailPort !== '465') {
                $this->warn('Implicit TLS (ssl) is normally used on port 465, not ' . $mailPort . '.');
            }

            if ($mailEncryption === 'tls' && $mailPort === '465') {
                $this->warn('STARTTLS (tls) is normally used on port 587, not 465. Port 465 already uses ssl.');
            }
        }

        $this->values['MAIL_TRANSPORT']       = $mailHost === '' ? 'log' : 'smtp';
        $this->values['MAIL_SMTP_HOST']       = $mailHost;
        $this->values['MAIL_SMTP_PORT']       = $mailPort !== '' ? $mailPort : '465';
        $this->values['MAIL_SMTP_USER']       = $mailUser;
        $this->values['MAIL_SMTP_PASS']       = $mailPass;
        $this->values['MAIL_SMTP_ENCRYPTION'] = $mailEncryption !== '' ? $mailEncryption : 'ssl';
        $this->values['MAIL_SMTP_AUTH']       = $mailUser === '' ? 'false' : 'true';
        $this->values['MAIL_FROM_EMAIL']      = $mailFrom;

        $this->values['SESSION_SECURE_COOKIE'] = $existing['SESSION_SECURE_COOKIE']
            ?? (str_starts_with($this->values['APP_URL'], 'https://') ? 'true' : 'false');

        $this->values['MAP_TILE_URL']    = $existing['MAP_TILE_URL'] ?: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
        $this->values['MAP_DEFAULT_LAT'] = $existing['MAP_DEFAULT_LAT'] ?: '1.3733';
        $this->values['MAP_DEFAULT_LNG'] = $existing['MAP_DEFAULT_LNG'] ?: '32.8883';
        $this->values['MAP_DEFAULT_ZOOM'] = $existing['MAP_DEFAULT_ZOOM'] ?: '7';

        $force = $this->flag('force');

        if (is_file($envFile) && !$force) {
            $this->warn('config/.env was not modified. Later steps use the values already in it.');

            $this->result['env_written'] = false;

            return;
        }

        $directory = dirname($envFile);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create ' . $directory . '. Check the directory is writable.');
        }

        // Written via a temporary file and renamed, so a failure part-way
        // through cannot leave a truncated .env that stops the application
        // booting.
        $temp = $envFile . '.tmp';

        if (file_put_contents($temp, $this->renderEnvironment()) === false) {
            throw new \RuntimeException('Could not write ' . $temp . '. Check the directory is writable.');
        }

        if (!rename($temp, $envFile)) {
            @unlink($temp);

            throw new \RuntimeException('Could not replace config/.env.');
        }

        @chmod($envFile, 0640);

        $this->ok('Wrote config/.env');
        $this->result['env_written'] = true;
    }

    private function resolveAppUrl(array $existing): string
    {
        $given = $this->given('url');

        if ($given !== null) {
            $url = rtrim($given, '/');
        } else {
            $scheme = strtolower($this->given('scheme') ?? 'https');
            $host   = $this->given('domain');

            if ($host === null && ($existing['APP_URL'] ?? '') !== '') {
                $host = (string) parse_url($existing['APP_URL'], PHP_URL_HOST);
            }

            $url = ($scheme === 'http' ? 'http://' : 'https://') . (string) $host;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('"' . $url . '" is not a usable application URL.');
        }

        return $url;
    }

    /**
     * Works out the path the application is served under, for example
     * "/cahu3/public" for a project in a subfolder, or "" at the root of a
     * domain.
     *
     * This is not optional. Core\Request strips it off the front of every
     * incoming path before matching a route, and App::url() puts it back on the
     * front of every generated link, so a wrong value here means every page and
     * every API endpoint 404s while the installer reports success.
     *
     * An explicit answer wins, then a value an operator has already put in the
     * file, and only then is it derived - because an empty APP_BASE_PATH in a
     * checked-in .env is far more likely to be the unset default than a
     * deliberate choice.
     *
     * To derive it, the installer asks the web server where it is being served
     * from. It lives in public/, one level below the project root, and the
     * project's own .htaccess forwards every request from the root through to
     * public/index.php while leaving REQUEST_URI alone. So the application is
     * reached at the project directory, and the installer has to drop its own
     * public/ segment to find it: /cahu3/public/install.php means the
     * application is served under /cahu3.
     *
     * Without SCRIPT_NAME - a shell install, or a server that rewrote the
     * request - the path component of APP_URL is the next best thing, since a
     * URL for this platform names the project directory.
     */
    private function resolveBasePath(array $existing): string
    {
        // Checked for presence rather than with given(), because an empty value
        // here is meaningful: it is how a root-of-domain install says so, and
        // given() cannot tell that apart from the key simply not being supplied.
        if (array_key_exists('base_path', $this->options) && is_string($this->options['base_path'])) {
            return $this->normaliseBasePath($this->options['base_path']);
        }

        if (trim((string) ($existing['APP_BASE_PATH'] ?? '')) !== '') {
            return $this->normaliseBasePath((string) $existing['APP_BASE_PATH']);
        }

        if (PHP_SAPI !== 'cli') {
            $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

            if ($script !== '') {
                return $this->normaliseBasePath($this->stripPublicSegment(dirname($script)));
            }
        }

        return $this->normaliseBasePath(
            (string) parse_url((string) ($this->values['APP_URL'] ?? ''), PHP_URL_PATH)
        );
    }

    /**
     * Turns the directory the installer is served from into the directory the
     * application is reached through. They differ by exactly one segment, the
     * public/ directory, because public/.htaccess serves the application
     * directly while the project's own rules forward to it from the root.
     *
     * A deployment that aliases the public directory straight onto a URL prefix
     * has nothing left to strip, and is left alone.
     */
    private function stripPublicSegment(string $directory): string
    {
        $directory = str_replace('\\', '/', $directory);

        if (strtolower(basename($directory)) === 'public') {
            return (string) dirname($directory);
        }

        return $directory;
    }

    /**
     * Reduces a base path to the single leading-slash, no-trailing-slash form
     * that Core\Request and App::url() expect, so "/" becomes "" rather than a
     * path that would be prepended to every URL.
     */
    private function normaliseBasePath(string $value): string
    {
        $value = trim(str_replace('\\', '/', $value), '/');

        return $value === '' ? '' : '/' . $value;
    }

    /**
     * Quotes a value for .env. The loader strips one matching pair of quotes, so
     * a value containing spaces, quotes or a hash must be wrapped to survive
     * intact. A bare value is left bare, which keeps the file readable.
     */
    private function quote(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return '"' . addcslashes($value, "\"\\") . '"';
    }

    private function renderEnvironment(): string
    {
        $v = $this->values;

        // Written in the order an operator reads them: identity, then database,
        // then session, then uploads, then the integrations.
        $lines = [
            '# Generated by the CAHU installer on ' . date('Y-m-d H:i:s'),
            '# Secrets live in this file only. It is excluded from version control.',
            '',
            'APP_NAME=' . $this->quote($v['APP_NAME']),
            'APP_SHORT_NAME=' . $this->quote($v['APP_SHORT_NAME']),
            'APP_ENV=' . $v['APP_ENV'],
            'APP_DEBUG=' . $v['APP_DEBUG'],
            'APP_URL=' . $this->quote($v['APP_URL']),
            'APP_BASE_PATH=' . $this->quote($v['APP_BASE_PATH']),
            'APP_TIMEZONE=' . $this->quote($v['APP_TIMEZONE']),
            'APP_LOCALE=' . $v['APP_LOCALE'],
            'APP_CURRENCY=' . $v['APP_CURRENCY'],
            'APP_KEY=' . $this->quote($v['APP_KEY']),
            '',
            'DB_HOST=' . $this->quote($v['DB_HOST']),
            'DB_PORT=' . $v['DB_PORT'],
            'DB_DATABASE=' . $this->quote($v['DB_DATABASE']),
            'DB_USERNAME=' . $this->quote($v['DB_USERNAME']),
            'DB_PASSWORD=' . $this->quote($v['DB_PASSWORD']),
            '',
            'SESSION_NAME=' . $v['SESSION_NAME'],
            'SESSION_LIFETIME=' . $v['SESSION_LIFETIME'],
            'SESSION_SECURE_COOKIE=' . $v['SESSION_SECURE_COOKIE'],
            'MAX_LOGIN_ATTEMPTS=' . $v['MAX_LOGIN_ATTEMPTS'],
            '',
            'UPLOAD_PATH=' . $this->quote($v['UPLOAD_PATH']),
            'UPLOAD_URL=' . $v['UPLOAD_URL'],
            'MAX_IMAGE_KB=' . $v['MAX_IMAGE_KB'],
            'MAX_DOC_KB=' . $v['MAX_DOC_KB'],
            'MAX_VIDEO_KB=' . $v['MAX_VIDEO_KB'],
            '',
            'SMS_ENABLED=' . $v['SMS_ENABLED'],
            'SMS_PROVIDER=' . $v['SMS_PROVIDER'],
            'SMS_API_URL=' . $this->quote($v['SMS_API_URL']),
            'SMS_API_KEY=' . $this->quote($v['SMS_API_KEY']),
            'SMS_SENDER_ID=' . $v['SMS_SENDER_ID'],
            '',
            'MAIL_TRANSPORT=' . $v['MAIL_TRANSPORT'],
            'MAIL_SMTP_HOST=' . $this->quote($v['MAIL_SMTP_HOST']),
            'MAIL_SMTP_PORT=' . $v['MAIL_SMTP_PORT'],
            'MAIL_SMTP_USER=' . $this->quote($v['MAIL_SMTP_USER']),
            'MAIL_SMTP_PASS=' . $this->quote($v['MAIL_SMTP_PASS']),
            'MAIL_SMTP_ENCRYPTION=' . $v['MAIL_SMTP_ENCRYPTION'],
            'MAIL_SMTP_AUTH=' . $v['MAIL_SMTP_AUTH'],
            'MAIL_FROM_EMAIL=' . $this->quote($v['MAIL_FROM_EMAIL']),
            'MAIL_FROM_NAME=' . $this->quote($v['MAIL_FROM_NAME']),
            'MAIL_REPLY_TO=' . $this->quote($v['MAIL_REPLY_TO']),
            'MAIL_HELO=' . $this->quote($v['MAIL_HELO']),
            '',
            'MAP_TILE_URL=' . $v['MAP_TILE_URL'],
            'MAP_ATTRIBUTION=' . $this->quote($v['MAP_ATTRIBUTION']),
            'MAP_DEFAULT_LAT=' . $v['MAP_DEFAULT_LAT'],
            'MAP_DEFAULT_LNG=' . $v['MAP_DEFAULT_LNG'],
            'MAP_DEFAULT_ZOOM=' . $v['MAP_DEFAULT_ZOOM'],
        ];

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /* ------------------------------------------------------------------ */
    /* 3. Database and schema                                             */
    /* ------------------------------------------------------------------ */

    /**
     * The database settings this run will actually use.
     *
     * This matters when config/.env was left untouched: the values just resolved
     * are then only what the file already said, and reading the file back is the
     * honest way to find out what that was.
     *
     * @return array{host: string, port: int, database: string, username: string, password: string, timezone: string}
     */
    public function databaseSettings(): array
    {
        $config = require $this->envPath('config/config.php');

        return $config['database'];
    }

    /**
     * @return array{imported: int, skipped: bool, database: string, statements: string[]}
     */
    public function prepareDatabase(): array
    {
        $this->step('3. Preparing the database');

        $database = $this->databaseSettings();

        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=utf8mb4',
            $database['host'],
            $database['port']
        );

        try {
            $pdo = new \PDO($dsn, $database['username'], $database['password'], [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES   => false,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\PDOException $exception) {
            throw new \RuntimeException(
                'Could not connect to MySQL at ' . $database['host'] . ':' . $database['port'] . ' as '
                . $database['username'] . '. '
                . $exception->getMessage()
                . ' Check the host, port, user and password.'
            );
        }

        $this->ok('Connected to MySQL ' . $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION) . ' as ' . $database['username']);

        if ($this->flag('skip_db')) {
            $this->warn('Skipping database creation and schema import (--skip-db).');

            $this->result['schema_skipped'] = true;
            $this->result['database']       = $database['database'];

            return ['imported' => 0, 'skipped' => true, 'database' => $database['database'], 'statements' => []];
        }

        $name = str_replace('`', '', $database['database']);

        $exists = $pdo->query('SHOW DATABASES LIKE ' . $pdo->quote($database['database']))->fetchColumn();

        if ($exists === false) {
            try {
                $pdo->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $this->ok('Created database ' . $database['database']);
            } catch (\PDOException $exception) {
                throw new \RuntimeException(
                    'Could not create the database: ' . $exception->getMessage()
                    . ' Create it by hand, then run the installer again.'
                );
            }
        } else {
            $this->ok('Database ' . $database['database'] . ' already exists');
        }

        $pdo->exec('USE `' . $name . '`');

        $tableCount = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ' . $pdo->quote($database['database'])
        )->fetchColumn();

        $skipped = false;

        if ($tableCount > 0) {
            $this->warn($database['database'] . ' already holds ' . $tableCount . ' table(s).');
            $this->line('Importing schema.sql now would fail on tables that already exist, and the installer never drops data.');

            if ($this->flag('import_schema')) {
                $this->warn('Continuing with the import as requested.');
            } else {
                $this->ok('Skipped the schema import. Seeding will use the tables already present.');
                $skipped = true;
            }
        }

        $imported = 0;

        if (!$skipped) {
            $imported = $this->importSchema($pdo, $database['database']);
        }

        $this->result['schema_skipped'] = $skipped;
        $this->result['schema_imported'] = $imported;
        $this->result['database']        = $database['database'];

        return [
            'imported'   => $imported,
            'skipped'    => $skipped,
            'database'   => $database['database'],
            'statements' => [],
        ];
    }

    /**
     * Imports database/schema.sql into the database the connection is already
     * using.
     */
    private function importSchema(\PDO $pdo, string $databaseName): int
    {
        $schema = $this->envPath('database/schema.sql');

        $sql = is_file($schema) ? file_get_contents($schema) : false;

        if ($sql === false) {
            throw new \RuntimeException('Could not read database/schema.sql.');
        }

        $statements = self::splitStatements($sql);
        $imported   = 0;

        foreach ($statements as $statement) {
            try {
                $pdo->exec($statement);
                $imported++;
            } catch (\PDOException $exception) {
                /*
                 * Naming the statement matters more than the message: "Table
                 * 'roles' already exists" says nothing about which of sixty
                 * CREATE TABLEs in a 1500 line file produced it.
                 */
                throw new \RuntimeException(
                    'Statement ' . ($imported + 1) . ' of ' . count($statements) . ' failed in ' . $databaseName
                    . ': ' . $exception->getMessage() . "\n\n"
                    . self::summarise($statement)
                );
            }
        }

        $this->ok('Imported ' . $imported . ' statement(s) from schema.sql into ' . $databaseName);

        return $imported;
    }

    /**
     * The first line of a statement, for an error message.
     */
    private static function summarise(string $statement): string
    {
        $line = trim((string) strtok($statement, "\n"));

        return strlen($line) > 90 ? substr($line, 0, 90) . '...' : $line;
    }

    /**
     * Splits a .sql file into individual statements.
     *
     * This deliberately does not use explode(";\n"). That approach looks
     * reasonable and is wrong in a way that fails silently: a file laid out as
     *
     *     -- -------------------------------------------------
     *     -- 1. ACCESS CONTROL
     *     -- -------------------------------------------------
     *
     *     CREATE TABLE `users` (
     *
     * has its comment block and its CREATE TABLE joined into one chunk by the
     * split, because the comment carries no semicolon of its own. The chunk then
     * begins with "--", so a filter that drops comment lines throws away the
     * CREATE TABLE with it. The statement never runs, nothing is reported, and
     * the install finishes claiming success with the table missing - which is
     * exactly what happened here: `users` was skipped on every run and the
     * first sign of it was a collision on `roles` in the wrong database.
     *
     * So comments are removed first, while scanning, and only then is the text
     * split on semicolons. Tracking quotes as it goes means a "--" or a ";" in a
     * string literal or a quoted identifier is left alone.
     *
     * @return string[]
     */
    public static function splitStatements(string $sql): array
    {
        $length     = strlen($sql);
        $statements = [];
        $current    = '';
        $quote      = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            // Inside a quoted string or a quoted identifier: nothing is special.
            if ($quote !== null) {
                $current .= $char;

                if ($char === '\\' && $quote !== '`') {
                    // An escaped character, so the next one cannot close the quote.
                    if ($next !== '') {
                        $current .= $next;
                        $i++;
                    }

                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            // An opening quote of any of the three kinds MySQL uses.
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote    = $char;
                $current .= $char;

                continue;
            }

            // "--" starts a comment that runs to the end of the line. MySQL
            // requires the second dash to be followed by whitespace or the end of
            // the line, which is what keeps an expression such as `a` - -1 from
            // being mistaken for one.
            if ($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }

                $current .= "\n";

                continue;
            }

            // "/* ... */" block comment, which may span lines.
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);

                if ($end === false) {
                    // Unterminated: treat the rest of the file as comment.
                    $current .= "\n";
                    $i        = $length;

                    continue;
                }

                // Keep the newlines so that line numbers in a server error still
                // roughly line up with the file.
                $current .= str_repeat("\n", substr_count(substr($sql, $i, $end - $i), "\n"));
                $i        = $end + 1;

                continue;
            }

            // A semicolon ends the statement.
            if ($char === ';') {
                $trimmed = trim($current);

                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }

                $current = '';

                continue;
            }

            $current .= $char;
        }

        // A final statement with no trailing semicolon is still a statement.
        $trimmed = trim($current);

        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /* ------------------------------------------------------------------ */
    /* 4. Seed                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>|null
     */
    public function seedData(): ?array
    {
        $this->step('4. Seeding roles, permissions and reference data');

        if ($this->flag('skip_seed')) {
            $this->warn('Skipped seeding. No user can sign in until the seeder is run.');
            $this->result['seeded'] = false;

            return null;
        }

        require_once $this->root . '/app/Core/Database.php';

        $config = require $this->envPath('config/config.php');

        \Core\Database::reset();

        $seeder = new Seeder(
            \Core\Database::instance($config['database']),
            [
                'admin_username' => $this->given('admin_user') ?? 'admin',
                'admin_email'    => $this->given('admin_email') ?? '',
                'admin_password' => $this->given('admin_pass') ?? '',
                'create_admin'   => true,
                'demo'           => $this->flag('demo'),
                'demo_password'  => $this->given('demo_pass') ?? 'CahuDemo#2026',
            ],
            function (string $message, string $level = 'info'): void {
                $this->line($message, $level === 'error' ? 'error' : $level);
            }
        );

        $summary = $seeder->run();

        $this->result['seeded']    = true;
        $this->result['seed']      = $summary;

        if ($this->flag('demo') && $summary['demo_accounts'] !== null) {
            $this->warn('Demo accounts are active. Each one must change its password on first sign-in,');
            $this->line('but the shared password is still public. Remove these accounts with:');
            $this->line('    DELETE FROM users WHERE username LIKE "demo.%"');
        }

        return $summary;
    }

    /* ------------------------------------------------------------------ */
    /* 5. Writable directories                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, bool>
     */
    public function createDirectories(): array
    {
        $this->step('5. Creating writable directories');

        $directories = [
            $this->envPath('storage/logs'),
            $this->envPath('storage/uploads'),
            $this->envPath('bootstrap/cache'),
            $this->envPath('public/uploads'),
        ];

        $state = [];

        foreach ($directories as $directory) {
            $label = str_replace($this->root . '/', '', $directory);

            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                $this->fail('Could not create ' . $label);
                $state[$label] = false;

                continue;
            }

            @chmod($directory, 0775);

            if (is_writable($directory)) {
                $this->ok($label);
                $state[$label] = true;
            } else {
                $this->warn($label . ' exists but is not writable by this user.');
                $state[$label] = false;
            }
        }

        $this->result['directories'] = $state;

        return $state;
    }

    /* ------------------------------------------------------------------ */
    /* 6. Mail                                                            */
    /* ------------------------------------------------------------------ */

    public function verifyMail(): void
    {
        $this->step('6. Verifying email delivery');

        if ($this->flag('skip_smtp')) {
            $this->warn('Skipped the email test.');

            return;
        }

        $recipient = $this->given('mail_test_to');

        if ($recipient === null) {
            $this->warn('No SMTP host configured, so no test message was sent.');
            $this->line('    Set MAIL_SMTP_* in config/.env, then verify email from the mail settings screen.');

            return;
        }

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->warn('"' . $recipient . '" is not a valid address, so no test was sent.');

            return;
        }

        if (!function_exists('fsockopen') || !extension_loaded('openssl')) {
            $this->warn('This server cannot open outbound connections, so no test was sent.');

            return;
        }

        require_once $this->root . '/app/Core/Database.php';

        $config = require $this->envPath('config/config.php');

        try {
            $mailer = new \Services\Mailer($config);
            $mailer->send($recipient, 'CAHU installation test', 'This message confirms that outbound email works.');
            $this->ok('Test message accepted for delivery to ' . $recipient);
        } catch (\Throwable $exception) {
            $this->fail('The test message was not accepted: ' . $exception->getMessage());
        }
    }

    /* ------------------------------------------------------------------ */
    /* Lock                                                               */
    /* ------------------------------------------------------------------ */

    public function lockPath(): string
    {
        return $this->envPath(self::LOCK_FILE);
    }

    public function isLocked(): bool
    {
        return is_file($this->lockPath());
    }

    /**
     * Records that the installation finished, so the web installer can refuse to
     * run again.
     */
    public function lock(): void
    {
        $directory = dirname($this->lockPath());

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        @file_put_contents(
            $this->lockPath(),
            json_encode([
                'installed_at' => date('c'),
                'app_url'      => $this->values['APP_URL'] ?? '',
                'database'     => $this->result['database'] ?? '',
                'php'          => PHP_VERSION,
            ], JSON_PRETTY_PRINT) . PHP_EOL
        );
    }

    public function unlock(): void
    {
        @unlink($this->lockPath());
    }

    /* ------------------------------------------------------------------ */
    /* Report                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * The closing summary, shared by both front ends.
     *
     * @return string[]
     */
    public function summaryLines(): array
    {
        $lines   = [];
        $lines[] = $this->values['APP_URL'] ?? '';
        $lines[] = ($this->result['database'] ?? '') . ' on ' . ($this->values['DB_HOST'] ?? '');

        return $lines;
    }

    /**
     * The steps an operator still has to take by hand.
     *
     * @return string[]
     */
    public function nextSteps(string $basePath = ''): array
    {
        $prefix = rtrim($basePath, '/');

        return [
            'Sign in at ' . ($this->values['APP_URL'] ?? '') . '/login',
            'Change the administrator password when prompted',
            'Delete ' . $this->lockPath() . ' and this file if you need to install again',
        ];
    }
}