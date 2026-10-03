<?php
declare(strict_types=1);

/**
 * Installs the data a fresh database needs before anyone can sign in.
 *
 * schema.sql creates 60+ empty tables. It deliberately contains no INSERT
 * statements, so a new install has no users, no roles and no permissions, and
 * the login screen rejects every credential. This script fills that gap:
 *
 *   - the permission catalogue the router and navigation check against
 *   - the standard roles, with permissions attached
 *   - one super administrator, prompted for interactively
 *   - the reference rows the forms assume exist: crops, seasons, locations,
 *     activity types, problem types, input categories and knowledge categories
 *
 * It is safe to re-run: every write is an upsert keyed on a natural column, so
 * running it twice changes nothing the second time. The one deliberate
 * exception is system_settings.setting_value, which is left alone once it
 * exists so that re-seeding never discards a value an operator has changed.
 *
 * The work itself lives in Console\Seeder. This file is only the command line
 * front end: it reads the options, asks the questions a terminal can ask, and
 * hands the answers over. That split is what allows the browser installer to
 * seed the first administrator without a PHP binary anywhere in sight.
 *
 * Usage:
 *   php database/seed.php
 *   php database/seed.php --demo
 *
 * Options:
 *   --admin-username=NAME   Super administrator username (default: admin)
 *   --admin-email=EMAIL     Super administrator email
 *   --admin-password=PASS   Super administrator password
 *   --force-password        Use --admin-password even though it is on the
 *                           command line, where it may end up in shell history
 *   --skip-admin            Seed only the reference data; create no account
 *   --demo                  Also create one demo account per role, each flagged
 *                           must_change_password so it is replaced on first use
 *   --demo-password=PASS    Shared demo password (default: CahuDemo#2026)
 */

use Core\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

$root = dirname(__DIR__);

require $root . '/app/bootstrap.php';

/* ------------------------------------------------------------------ */
/* Arguments                                                           */
/* ------------------------------------------------------------------ */

$options = getopt('', [
    'admin-username::',
    'admin-email::',
    'admin-password::',
    'force-password',
    'skip-admin',
    'demo',
    'demo-password::',
]);

$hasDemo  = array_key_exists('demo', $options);
$isTty    = stream_isatty(STDIN);

/**
 * Reads a value, preferring anything already supplied on the command line and
 * otherwise prompting. Either way the question is skipped when the value is
 * known, which is what lets the installer drive this script non-interactively.
 */
$ask = static function (string $label, string $default, ?string $given) use ($isTty): string {
    if ($given !== null && $given !== '') {
        return $given;
    }

    if (!$isTty) {
        return $default;
    }

    echo $label . ($default === '' ? ': ' : ' [' . $default . ']: ');

    $answer = trim((string) fgets(STDIN));

    return $answer === '' ? $default : $answer;
};

/**
 * Reads a password without echoing it back to the terminal.
 *
 * POSIX terminals hide the input with stty. Windows has no stty, so a
 * PowerShell SecureString prompt is used instead; if neither is available the
 * value is read visibly and the operator is warned about it.
 */
$askSecret = static function (string $label) use ($options, $isTty): string {
    if (!$isTty && isset($options['admin-password'])) {
        return (string) $options['admin-password'];
    }

    if (!$isTty) {
        // Nothing to prompt with and nothing supplied: let the seeder report the
        // missing value rather than inventing one.
        return '';
    }

    echo $label . ': ';

    $canHide = function_exists('shell_exec');

    if ($canHide && DIRECTORY_SEPARATOR === '\\') {
        // Windows: read the password as a SecureString and marshal it back out
        // so it never appears on screen or in the console history.
        $command = '$s = Read-Host -AsSecureString;'
            . ' $b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s);'
            . ' try { [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b) }'
            . ' finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b) }';

        $answer = @shell_exec('powershell -NoProfile -Command "' . $command . '"');

        if (is_string($answer)) {
            echo PHP_EOL;

            return trim($answer);
        }

        echo PHP_EOL
            . '  Secure input is unavailable here, so the password is visible as you type.' . PHP_EOL;
        $canHide = false;
    }

    $saved = $canHide ? @shell_exec('stty -g 2>/dev/null') : null;

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

/* ------------------------------------------------------------------ */
/* Answers                                                             */
/* ------------------------------------------------------------------ */

$skipAdmin = array_key_exists('skip-admin', $options);

$answers = [
    'admin_username' => 'admin',
    'admin_email'    => '',
    'admin_password' => '',
    'create_admin'   => !$skipAdmin,
    'demo'           => $hasDemo,
    'demo_password'  => (string) ($options['demo-password'] ?? 'CahuDemo#2026'),
];

if (!$skipAdmin) {
    $answers['admin_username'] = $ask('Super administrator username', 'admin', $options['admin-username'] ?? null);
    $answers['admin_email']    = $ask('Super administrator email', 'admin@cahu.ug', $options['admin-email'] ?? null);
    $answers['admin_password'] = $askSecret('Super administrator password (minimum 8 characters)');
}

/* ------------------------------------------------------------------ */
/* Seed                                                                */
/* ------------------------------------------------------------------ */

$config = require $root . '/config/config.php';

try {
    $seeder = new Console\Seeder(
        Database::instance($config['database']),
        $answers,
        static function (string $message, string $level = 'info'): void {
            echo $message . PHP_EOL;
        }
    );

    $summary = $seeder->run();
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

if ($summary['demo_accounts'] !== null) {
    echo PHP_EOL . '  username                role            password' . PHP_EOL;

    foreach ($summary['demo_accounts']['accounts'] as $account) {
        printf(
            "  %-22s %-15s %s\n",
            $account['username'],
            $account['role'],
            $account['password']
        );
    }

    echo PHP_EOL . 'Every demo account must change its password on first sign-in.' . PHP_EOL;
    echo 'Delete these rows before exposing the platform to real users.' . PHP_EOL;
}

exit(0);