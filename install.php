<?php
declare(strict_types=1);

/**
 * One-shot installer for the CAHU platform, from the command line.
 *
 * schema.sql creates the tables but no data, so a fresh install has no users,
 * no roles and no permissions: the login screen rejects every credential. This
 * script closes that gap by walking through everything a new server needs, in
 * an order where each step depends only on the ones before it.
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
 * Usage:
 *   php install.php
 *   php install.php --help
 *
 * Options:
 *   --url=URL           Application base URL
 *   --domain=HOST       Host only, combined with --scheme (defaults to https)
 *   --scheme=NAME       https or http
 *   --base-path=PATH    Path the site is served under, e.g. /cahu3.
 *                       Empty for a site at the root of a domain. Worked out
 *                       automatically from --url when not given.
 *   --db-host=HOST      Database host
 *   --db-port=PORT      Database port
 *   --db-name=NAME      Database name
 *   --db-user=USER      Database user
 *   --db-pass=PASS      Database password
 *   --admin-user=NAME   Super administrator username
 *   --admin-email=MAIL  Super administrator email
 *   --admin-pass=PASS   Super administrator password
 *   --skip-db           Do not create the database or import the schema
 *   --skip-seed         Do not run the seeder
 *   --skip-smtp         Do not send a test email
 *   --demo              Also create one demo account per role, each of which
 *                       must replace its password on first sign-in
 *   --demo-pass=PASS    Shared demo password (default: CahuDemo#2026)
 *   --force             Overwrite existing answers in config/.env
 *   --import-schema     Import the schema even into a database that already
 *                       has tables (off by default: it never drops data)
 *   --non-interactive   Never prompt; use options and existing values only
 *
 * Installing from a browser instead? public/install.php does the same six steps
 * through a form. The work itself lives in Console\Installer, which both entry
 * points share; this file is only the terminal front end.
 */

use Console\Installer;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This installer may only be run from the command line.\n");
}

$root = __DIR__;

require $root . '/app/bootstrap.php';

/* ------------------------------------------------------------------ */
/* Arguments                                                           */
/* ------------------------------------------------------------------ */

$options = getopt('', [
    'url:',
    'domain:',
    'scheme:',
    'base-path:',
    'db-host:',
    'db-port:',
    'db-name:',
    'db-user:',
    'db-pass:',
    'admin-user:',
    'admin-email:',
    'admin-pass:',
    'skip-db',
    'skip-seed',
    'skip-smtp',
    'demo',
    'demo-pass:',
    'force',
    'import-schema',
    'non-interactive',
    'help',
]);

$has = static fn (string $name): bool => array_key_exists($name, $options);
$val = static fn (string $name, ?string $default = null): ?string => $has($name)
    ? (string) $options[$name]
    : $default;

if ($has('help')) {
    echo preg_replace('/^\s*\*ted?/m', '', preg_replace('/^\s*\* ?/m', '', (static function (): string {
        $source = file_get_contents(__FILE__) ?: '';

        preg_match('#/\*\*(.*?)\*/#s', $source, $match);

        return $match[1] ?? '';
    })()));

    exit(0);
}

/* ------------------------------------------------------------------ */
/* Presentation                                                       */
/* ------------------------------------------------------------------ */

$useColour = getenv('NO_COLOR') === false && DIRECTORY_SEPARATOR !== '\\';

$style = static fn (string $code, string $text): string => $useColour
    ? "\033[" . $code . 'm' . $text . "\033[0m"
    : $text;

$bold   = static fn (string $t): string => $style('1', $t);
$green  = static fn (string $t): string => $style('32', $t);
$yellow = static fn (string $t): string => $style('33', $t);
$red    = static fn (string $t): string => $style('31', $t);
$cyan   = static fn (string $t): string => $style('36', $t);

/* ------------------------------------------------------------------ */
/* Prompts                                                            */
/* ------------------------------------------------------------------ */

$isTty = stream_isatty(STDIN);

/**
 * Asks for a value, preferring anything already supplied on the command line
 * and otherwise falling back to the current value in config/.env.
 *
 * A secret supplied as a command-line option is still not echoed, because the
 * shell and any process listing will already have recorded it.
 */
$ask = static function (string $label, string $default, ?string $given, bool $secret = false) use ($isTty): string {
    if ($given !== null && $given !== '') {
        echo '  ' . $label . ': ' . ($secret ? '(supplied)' : $given) . PHP_EOL;

        return $given;
    }

    if (!$isTty) {
        // Piped or redirected input: take the default rather than blocking a
        // non-interactive run on a prompt nobody can answer. A secret is never
        // printed here, because this output may end up in a CI log.
        echo '  ' . $label . ': ' . ($secret ? '(kept existing value)' : $default) . ' (default)' . PHP_EOL;

        return $default;
    }

    echo '  ' . $label . ($default === '' ? ': ' : ' [' . $default . ']: ');

    if (!$secret) {
        $answer = trim((string) fgets(STDIN));

        return $answer === '' ? $default : $answer;
    }

    if (DIRECTORY_SEPARATOR === '\\' && function_exists('shell_exec')) {
        $command = '$s = Read-Host -AsSecureString;'
            . ' $b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s);'
            . ' try { [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b) }'
            . ' finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b) }';

        $answer = @shell_exec('powershell -NoProfile -Command "' . $command . '"');

        if (is_string($answer)) {
            echo PHP_EOL;

            return trim($answer);
        }
    }

    $saved = @shell_exec('stty -g 2>/dev/null');

    if (is_string($saved) && $saved !== '') {
        @shell_exec('stty -echo 2>/dev/null');
    }

    $answer = trim((string) fgets(STDIN));

    if (is_string($saved) && $saved !== '') {
        @shell_exec('stty ' . escapeshellarg(trim($saved)) . ' 2>/dev/null');
        echo PHP_EOL;
    }

    return $answer;
};

$confirm = static function (string $question, bool $default = false) use ($isTty, $yellow): bool {
    if (!$isTty) {
        return $default;
    }

    echo '  ' . $question . ' ' . ($default ? '[Y/n]' : '[y/N]') . ' ';

    $answer = strtolower(trim((string) fgets(STDIN)));

    if ($answer === '') {
        return $default;
    }

    return $answer === 'y' || $answer === 'yes';
};

/**
 * Reads a value already present in config/.env, so re-running the installer
 * keeps the answers that are still there.
 */
$existingEnv = static function () use ($root): array {
    $values = [];
    $file   = $root . '/config/.env';

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

    return $values;
};

$existing = $existingEnv();

echo $bold('CAHU Platform installer') . PHP_EOL;
echo $bold('=======================') . PHP_EOL;
echo '  ' . $root . PHP_EOL . PHP_EOL;

foreach ([
    'config/.env.example'     => 'config/.env.example (template)',
    'database/schema.sql'     => 'database/schema.sql (schema)',
    'database/seed.php'       => 'database/seed.php (seeder)',
    'app/Console/Installer.php' => 'app/Console/Installer.php (installer)',
] as $path => $label) {
    if (!is_file($root . '/' . $path)) {
        echo $red('  [x] ') . 'Missing ' . $label . PHP_EOL;
        exit(1);
    }
}

echo is_file($root . '/config/.env')
    ? '  ' . $green('[ok]') . ' Found an existing config/.env; its values become the defaults.' . PHP_EOL
    : '  ' . $green('[ok]') . ' No config/.env yet; one will be created.' . PHP_EOL;

/* ------------------------------------------------------------------ */
/* Answers                                                            */
/* ------------------------------------------------------------------ */

$scheme = strtolower($val('scheme', 'https'));
$domain = $val('domain');

if ($domain === null && ($existing['APP_URL'] ?? '') !== '') {
    $domain = (string) parse_url($existing['APP_URL'], PHP_URL_HOST);
}

$domain   = $ask('Public domain or host', (string) ($domain ?: ''), $val('domain'));
$scheme   = $ask('Scheme (https or http)', $scheme, $val('scheme'));
$appUrl   = $val('url') !== null ? $val('url') : ($scheme === 'http' ? 'http://' : 'https://') . $domain;

// Only prompted for when there is no --url to derive it from, since deriving it
// is right far more often than asking a question the shell installer cannot
// answer better than it can compute.
$basePath = $val('base-path');

if ($basePath === null) {
    $basePath = rtrim((string) parse_url((string) $appUrl, PHP_URL_PATH), '/');

    if (($existing['APP_BASE_PATH'] ?? '') !== '') {
        $basePath = (string) $existing['APP_BASE_PATH'];
    }

    $basePath = $ask('Base path (blank when the site is at the root of a domain)', $basePath, null);
}

$dbHost   = $ask('Database host', $val('db-host', $existing['DB_HOST'] ?: '127.0.0.1'), $val('db-host'));
$dbPort   = $ask('Database port', $val('db-port', $existing['DB_PORT'] ?: '3306'), $val('db-port'));
$dbName   = $ask('Database name', $val('db-name', $existing['DB_DATABASE'] ?: 'cahu_platform'), $val('db-name'));
$dbUser   = $ask('Database user', $val('db-user', $existing['DB_USERNAME'] ?: 'root'), $val('db-user'));
$dbPass   = $ask('Database password', $val('db-pass', $existing['DB_PASSWORD'] ?: ''), $val('db-pass'), true);

$adminUser  = $has('skip-seed') ? 'admin' : $ask('Administrator username', $val('admin-user', 'admin'), $val('admin-user'));
$adminEmail = $has('skip-seed') ? '' : $ask('Administrator email', $val('admin-email', ''), $val('admin-email'));
$adminPass  = $has('skip-seed') ? '' : $ask('Administrator password', $val('admin-pass', ''), $val('admin-pass'), true);

if (!$has('skip-seed')) {
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        echo '  ' . $yellow('[!]') . ' "' . $adminEmail . '" is not a valid email address; the seeder will not create the account.' . PHP_EOL;
    }

    if (strlen($adminPass) < 8) {
        echo '  ' . $yellow('[!]') . ' The password is shorter than 8 characters; the seeder will refuse to create the account.' . PHP_EOL;
    }
}

$mailHost       = $ask('SMTP host', $existing['MAIL_SMTP_HOST'] ?? '', null);
$mailPort       = $ask('SMTP port', $existing['MAIL_SMTP_PORT'] ?: '465', null);
$mailUser       = $ask('SMTP username', $existing['MAIL_SMTP_USER'] ?? '', null);
$mailPass       = $ask('SMTP password', $existing['MAIL_SMTP_PASS'] ?? '', null, true);
$mailEncryption = strtolower($ask('SMTP encryption (ssl or tls)', $existing['MAIL_SMTP_ENCRYPTION'] ?: 'ssl', null));
$mailFrom       = $ask('Sender address', $existing['MAIL_FROM_EMAIL'] ?? '', null);
$mailFromName   = $ask('Sender name', $existing['MAIL_FROM_NAME'] ?: 'CAHU', null);
$mailHelo       = $ask('SMTP EHLO hostname (blank uses the host)', $existing['MAIL_HELO'] ?? '', null);
$mailTestTo     = $ask('Send a test email to', $adminEmail, null);

$force = $has('force');

if (is_file($root . '/config/.env') && !$force) {
    if ($confirm('Overwrite the existing config/.env? Existing values are used as defaults.')) {
        $force = true;
    } else {
        echo '  ' . $yellow('[!]') . ' Keeping config/.env untouched. Re-run with --force to rewrite it.' . PHP_EOL;
    }
}

/* ------------------------------------------------------------------ */
/* Install                                                            */
/* ------------------------------------------------------------------ */

$installer = new Installer(
    [
        'url'             => $appUrl,
        'base_path'       => (string) $basePath,
        'db_host'         => $dbHost,
        'db_port'         => $dbPort,
        'db_name'         => $dbName,
        'db_user'         => $dbUser,
        'db_pass'         => $dbPass,
        'admin_user'      => $adminUser,
        'admin_email'     => $adminEmail,
        'admin_pass'      => $adminPass,
        'skip_db'         => $has('skip-db'),
        'skip_seed'       => $has('skip-seed'),
        'skip_smtp'       => $has('skip-smtp'),
        'demo'            => $has('demo'),
        'demo_pass'       => $val('demo-pass', 'CahuDemo#2026'),
        'force'           => $force,
        'import_schema'   => $has('import-schema'),
        'mail_host'       => $mailHost,
        'mail_port'       => $mailPort,
        'mail_user'       => $mailUser,
        'mail_pass'       => $mailPass,
        'mail_encryption' => $mailEncryption,
        'mail_from'       => $mailFrom,
        'mail_from_name'  => $mailFromName,
        'mail_helo'       => $mailHelo,
        'mail_test_to'    => $mailTestTo,
    ],
    static function (string $message, string $level = 'info') use ($bold, $cyan, $green, $yellow, $red): void {
        $prefix = [
            'step'  => $bold($cyan('== ')),
            'ok'    => '  ' . $green('[ok]') . ' ',
            'warn'  => '  ' . $yellow('[!]') . ' ',
            'error' => '  ' . $red('[x]') . ' ',
        ][$level] ?? '  ';

        echo $prefix . $message . PHP_EOL;
    },
    $root
);

try {
    $installer->run();
} catch (Throwable $exception) {
    echo PHP_EOL . '  ' . $red('[x] ') . $exception->getMessage() . PHP_EOL;
    exit(1);
}

/* ------------------------------------------------------------------ */
/* Summary                                                            */
/* ------------------------------------------------------------------ */

$result = $installer->result();

echo PHP_EOL . $bold('Done') . PHP_EOL . PHP_EOL;
echo '  ' . $green('[ok]') . ' Application URL: ' . $appUrl . PHP_EOL;
echo '  ' . $green('[ok]') . ' Database:        ' . ($result['database'] ?? $dbName) . ' on ' . $dbHost . PHP_EOL;

if (($result['schema_skipped'] ?? false) === true) {
    echo '  ' . $yellow('[!]') . ' Schema:          not imported, using existing tables' . PHP_EOL;
} else {
    echo '  ' . $green('[ok]') . ' Schema:          ' . ($result['schema_imported'] ?? 0) . ' statement(s) imported' . PHP_EOL;
}

echo PHP_EOL;
echo '  ' . $bold('Next steps') . PHP_EOL;
echo '    1. Point the web server document root at ' . $root . '/public' . PHP_EOL;
echo '    2. Make sure ' . $root . '/storage is writable by the web server user' . PHP_EOL;
echo '    3. Sign in at ' . $appUrl . '/login' . PHP_EOL;
echo '    4. Change the administrator password when prompted' . PHP_EOL;
echo PHP_EOL;

exit($installer->failed() ? 1 : 0);