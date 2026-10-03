<?php
declare(strict_types=1);

/**
 * Checks that the settings screen's read-only classification still matches the
 * code.
 *
 * SettingController decides which rows in system_settings may be edited, and a
 * wrong answer is quiet: a row that is offered a text box but is read from
 * config/.env saves a value that nothing acts on, and it looks authoritative the
 * whole time. The alternative to checking is trusting a hand-written list.
 *
 * For every key the seeder writes, this asserts two things independently of the
 * controller:
 *
 *   - a key shown as editable really is read through the setting helper, and
 *   - a key shown as coming from the environment really is present in the boot
 *     config, under the same name or a documented rename.
 *
 * Keys that nothing reads are listed too. They are not a failure, since showing
 * them read-only is correct, but they are the ones worth knowing about: the
 * seeder documents a policy that the code never applies.
 *
 * Usage: php tools/check-settings.php
 */

/*
 * Command line only, for the same reason as the other tools in this directory.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This check may only be run from the command line.\n");
}

$root      = dirname(__DIR__);
$appRoot   = $root . '/app';
$seedFile  = $root . '/database/seed.sql';
$configFile = $root . '/config/config.php';

/**
 * Blanks comments out while leaving every other token where it was, so a
 * docblock naming a class is not mistaken for code that calls it.
 */
function withoutComments(string $source): string
{
    $stripped = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
            $stripped .= ' ';
            continue;
        }

        $stripped .= is_array($token) ? $token[1] : $token;
    }

    return $stripped;
}

/** Collects the php files under a directory. */
function phpFilesIn(string $directory): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

// The controller extends Core\Controller, so the application's own layout has to
// be loadable before the class can be reflected on.
spl_autoload_register(static function (string $class) use ($appRoot): void {
    $path = $appRoot . '/' . str_replace('\\', '/', $class) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

/**
 * Mirrors the renames in SettingController, which exist because the seeder and
 * the config do not share a vocabulary: the settings table calls the mail group
 * "email" where the config calls it "mail", and session limits sit under the app
 * section however the table spells them.
 */
const SEEDED_PREFIX_TO_CONFIG = [
    'security.' => 'app.',
    'email.'    => 'mail.',
];

const SEEDED_LEAF_TO_CONFIG = [
    'mail.from_email' => 'mail.from',
];

$configKeyFor = static function (string $key): string {
    foreach (SEEDED_PREFIX_TO_CONFIG as $seededPrefix => $configPrefix) {
        if (str_starts_with($key, $seededPrefix)) {
            return $configPrefix . substr($key, strlen($seededPrefix));
        }
    }

    return $key;
};

// --- the real boot config ------------------------------------------------
if (!is_file($configFile)) {
    exit("config.php not found at {$configFile}\n");
}

/** @var array<string,mixed> $config */
$config = require $configFile;

if (!is_array($config)) {
    exit("config.php did not return an array.\n");
}

/** Whether the boot config actually holds a value under this name. */
$inConfig = static function (string $key) use ($config, $configKeyFor): bool {
    $names = [$configKeyFor($key)];

    // The seeder's spelling of a renamed leaf is the looser of the two, so both
    // are tried before the key counts as absent.
    $aliased = SEEDED_LEAF_TO_CONFIG[$names[0]] ?? $names[0];

    if ($aliased !== $names[0]) {
        array_unshift($names, $aliased);
    }

    foreach ($names as $name) {
        $node = $config;

        foreach (explode('.', $name) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                continue 2;
            }

            $node = $node[$segment];
        }

        return true;
    }

    return false;
};

// --- keys the application reads through the setting helper ----------------
$readByHelper = [];

foreach (phpFilesIn($appRoot) as $path) {
    $code = withoutComments((string) file_get_contents($path));

    if (preg_match_all("/(?:Setting::get|setting|setting_url)\(\s*'([a-z0-9_.]+)'/i", $code, $matches)) {
        foreach ($matches[1] as $key) {
            $readByHelper[strtolower($key)] = true;
        }
    }
}

// The scoring weights and the forecast factors are built at the call site, so
// they are never spelled out literally in code.
$readByFamily = static function (string $key): bool {
    return str_starts_with($key, 'score.weight.') || str_starts_with($key, 'forecast.factor.');
};

// --- what the seeder writes ----------------------------------------------
if (!is_file($seedFile)) {
    exit("seed.sql not found at {$seedFile}\n");
}

$seeded = [];

preg_match_all("/\('([a-z0-9_]+(?:\.[a-z0-9_]+)*)'\s*,\s*'[^']*'\s*,\s*'([a-z0-9_]+)'/i", (string) file_get_contents($seedFile), $matches, PREG_SET_ORDER);

foreach ($matches as $row) {
    $seeded[strtolower($row[1])] = strtolower($row[2]);
}

ksort($seeded);

// --- what the screen claims ----------------------------------------------
require $appRoot . '/Http/Controllers/Admin/SettingController.php';

// The controller asks the running application for its config, so a stand-in has
// to answer the way the real one does for this check to mean anything.
$GLOBALS['cahu_app'] = new class($config) {
    public function __construct(private array $config) {}

    public function config(string $key, mixed $default = null): mixed
    {
        $node = $this->config;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }

            $node = $node[$segment];
        }

        return $node;
    }
};

$instance = (new ReflectionClass(\Http\Controllers\Admin\SettingController::class))->newInstanceWithoutConstructor();
$origin   = new ReflectionMethod($instance, 'origin');
$note     = new ReflectionMethod($instance, 'originNote');

$counts     = ['database' => 0, 'environment' => 0, 'not-wired' => 0];
$wrongEdit  = [];
$wrongEnv   = [];
$unread     = [];

foreach (array_keys($seeded) as $key) {
    $claimed = $origin->invoke($instance, $key);
    $counts[$claimed] = ($counts[$claimed] ?? 0) + 1;

    if ($claimed === 'database') {
        // The dangerous direction: an editable box for a key nothing reads.
        if (!isset($readByHelper[$key]) && !$readByFamily($key)) {
            $wrongEdit[] = $key;
        }

        continue;
    }

    if ($claimed === 'environment') {
        if (!$inConfig($key)) {
            $wrongEnv[] = $key;
        }

        continue;
    }

    // not-wired is safe to display, but worth reporting.
    if (isset($readByHelper[$key]) || $readByFamily($key) || $inConfig($key)) {
        $wrongEnv[] = $key;
    } else {
        $unread[] = $key;
    }
}

printf(
    "Seeded keys: %d\n  editable, read from the database : %d\n  read-only, from the environment : %d\n  read-only, nothing reads them   : %d\n\n",
    count($seeded),
    $counts['database'],
    $counts['environment'],
    $counts['not-wired']
);

$failed = false;

if ($wrongEdit !== []) {
    $failed = true;
    echo "Offered an editor, but nothing reads the key:\n";

    foreach ($wrongEdit as $key) {
        echo "  - {$key}\n";
    }

    echo "\n";
}

if ($wrongEnv !== []) {
    $failed = true;
    echo "Labelled incorrectly against the boot config:\n";

    foreach ($wrongEnv as $key) {
        echo "  - {$key}\n";
    }

    echo "\n";
}

if ($unread !== []) {
    echo "Documented in the seeder but never read by the application.\n";
    echo "They are shown read-only, which is correct, but the policy they describe is not in force:\n";

    foreach ($unread as $key) {
        echo "  - {$key}\n";
    }

    echo "\n";
}

// The two read-only notes must never be blank, or the row explains nothing.
foreach (['environment', 'not-wired'] as $originName) {
    if (trim((string) $note->invoke($instance, $originName)) === '') {
        $failed = true;
        echo "The note for '{$originName}' rows is empty.\n";
    }
}

if ($failed) {
    echo "check-settings failed.\n";
    exit(1);
}

echo "The settings screen and the code agree about every seeded key.\n";
exit(0);
