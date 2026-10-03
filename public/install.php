<?php
declare(strict_types=1);

/**
 * Browser installer for the CAHU platform.
 *
 * The same six steps the command line installer runs - check PHP, write
 * config/.env, create the database and import the schema, seed roles and the
 * first administrator, create the writable directories, optionally prove
 * email works. The steps themselves live in Console\Installer; this file is
 * only the form, the token check and the log rendering around them.
 *
 * Why this file can run at all
 * ----------------------------
 * A fresh server has no configuration, no database and no application code
 * loaded, so nothing here may depend on the framework being installed. That is
 * why the installer cannot simply be a route in the application: by the time
 * the router runs, config/config.php has already been required and a missing
 * .env has already turned into a 503. So this is a standalone script that
 * requires only the autoloader, and the seeder is a plain class rather than a
 * `php database/seed.php` subprocess - there is no guarantee a PHP CLI binary
 * exists on a host running PHP as an Apache module, and shared hosting
 * routinely disables exec, passthru and shell_exec outright.
 *
 * How it is kept from being abused
 * -------------------------------
 * This file creates the first administrator and writes the file holding the
 * database password and APP_KEY, so anyone who can reach it before the
 * operator finishes owns the installation. Three things stand in the way:
 *
 *   1. A token. storage/install.token holds a random value; the form is only
 *      usable by presenting it. A request from the loopback interface is
 *      trusted without one, which is the normal case for a local or XAMPP
 *      install and keeps the first step frictionless. From anywhere else the
 *      token has to be read off the server and typed in.
 *   2. A lock. storage/install.lock is written when a run finishes, and its
 *      presence stops this script doing anything except say so. A finished
 *      install cannot be silently repeated.
 *   3. No credential is ever echoed back. The database password, the
 *      administrator password and the APP_KEY are write-only here: they are
 *      accepted in the POST body, never rendered into the page.
 *
 * After a successful install, delete this file. The lock stops it running
 * again, but a removed file is not something to rely on a rewrite rule for.
 *
 * Usage: open /install.php in a browser.
 */

use Console\Installer;

/* ------------------------------------------------------------------ */
/* Boot                                                                */
/* ------------------------------------------------------------------ */

define('CAHU_INSTALLER', true);

$root = dirname(__DIR__);

require $root . '/app/bootstrap.php';

$tokenFile = $root . '/storage/install.token';
$storage   = $root . '/storage';

if (!is_dir($storage)) {
    @mkdir($storage, 0775, true);
}

/*
 * The installer writes config/.env and creates directories, and both fail on a
 * host where PHP has been denied write access. Saying so plainly beats a blank
 * page or a fatal error, so the writability of the two places that matter is
 * checked before anything else is attempted.
 */
$writable = [
    'config/' => is_dir($root . '/config') && is_writable($root . '/config'),
    'storage/' => is_writable($storage),
];

/* ------------------------------------------------------------------ */
/* Token                                                               */
/* ------------------------------------------------------------------ */

/**
 * A request from this machine. Not a security control in itself - it is
 * convenience, so that installing on a laptop does not begin with reading a
 * file off the disk - but it is what keeps the token meaningful for the case
 * that actually matters: the server sitting on a host with a public address.
 */
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', ''], true);

$token = is_file($tokenFile) ? trim((string) file_get_contents($tokenFile)) : '';

if ($token === '' && isset($writable['storage/']) && $writable['storage/']) {
    $token = bin2hex(random_bytes(24));

    @file_put_contents($tokenFile, $token . PHP_EOL);
    @chmod($tokenFile, 0600);
}

$supplied = (string) ($_POST['token'] ?? $_GET['token'] ?? '');

$tokenOk = $isLocal || ($token !== '' && hash_equals($token, $supplied));

/* ------------------------------------------------------------------ */
/* State                                                               */
/* ------------------------------------------------------------------ */

$installer = new Installer([], static function (): void {
}, $root);

$locked  = $installer->isLocked();
$method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$isHttps = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';

/** @var array<int, array{level: string, message: string}> */
$log = [];

$ran        = false;
$fatal      = null;
$summary    = [];
$finishedOk = false;

if ($method === 'POST' && $tokenOk && !$locked) {
    $ran = true;

    // An import of 60-odd tables plus the seeder is not a quick request, and the
    // default 30 second limit is a real risk on a shared host.
    @set_time_limit(0);

    $post = static fn (string $key): string => trim((string) ($_POST[$key] ?? ''));

    $adminPass = (string) ($_POST['admin_pass'] ?? '');
    $dbPass    = (string) ($_POST['db_pass'] ?? '');

    $options = [
        'url'             => $post('url'),
        'base_path'       => $post('base_path'),
        'db_host'         => $post('db_host') ?: '127.0.0.1',
        'db_port'         => $post('db_port') ?: '3306',
        'db_name'         => $post('db_name') ?: 'cahu_platform',
        'db_user'         => $post('db_user') ?: 'root',
        'db_pass'         => $dbPass,
        'admin_user'      => $post('admin_user') ?: 'admin',
        'admin_email'     => $post('admin_email'),
        'admin_pass'      => $adminPass,
        'skip_db'         => isset($_POST['skip_db']),
        'skip_seed'       => isset($_POST['skip_seed']),
        'skip_smtp'       => !isset($_POST['test_mail']),
        'demo'            => isset($_POST['demo']),
        'demo_pass'       => (string) ($_POST['demo_pass'] ?? 'CahuDemo#2026'),
        'force'           => isset($_POST['force']),
        'import_schema'   => isset($_POST['import_schema']),
        'mail_host'       => $post('mail_host'),
        'mail_port'       => $post('mail_port') ?: '465',
        'mail_user'       => $post('mail_user'),
        'mail_pass'       => (string) ($_POST['mail_pass'] ?? ''),
        'mail_encryption' => $post('mail_encryption') ?: 'ssl',
        'mail_from'       => $post('mail_from'),
        'mail_from_name'  => $post('mail_from_name') ?: 'CAHU',
        'mail_helo'       => $post('mail_helo'),
    ];

    // Only ask for a real address when there is in fact an SMTP server to use.
    $options['mail_test_to'] = ($options['mail_host'] !== '' && $post('test_to') !== '')
        ? $post('test_to')
        : null;

    // Validate what can be checked without touching the database, so a
    // mistyped email address is a form error rather than a half-finished
    // install that leaves no administrator.
    $errors = [];

    if (!filter_var($options['url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'The application URL is not a valid URL.';
    }

    if (!$options['skip_seed']) {
        if ($adminPass === '' || strlen($adminPass) < 8) {
            $errors[] = 'The administrator password must be at least 8 characters.';
        } elseif ($adminPass !== (string) ($_POST['admin_pass_confirm'] ?? '')) {
            $errors[] = 'The two administrator passwords do not match.';
        }

        if (!filter_var($options['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'The administrator email address is not valid.';
        }
    }

    if (isset($_POST['demo']) && strlen((string) $options['demo_pass']) < 8) {
        $errors[] = 'The demo password must be at least 8 characters.';
    }

    if ($errors === []) {
        $installer = new Installer(
            $options,
            static function (string $message, string $level = 'info') use (&$log): void {
                $log[] = ['level' => $level, 'message' => $message];
            },
            $root
        );

        try {
            $summary = $installer->run();
            $finishedOk = !$installer->failed();

            if ($finishedOk) {
                $installer->lock();
            }
        } catch (Throwable $exception) {
            $fatal = $exception->getMessage();
        }
    } else {
        $fatal = implode(' ', $errors);
    }
}

/* ------------------------------------------------------------------ */
/* Defaults for the form                                               */
/* ------------------------------------------------------------------ */

/**
 * Reads the current config/.env so the form opens on the values already in
 * place rather than on guesses.
 */
$currentEnv = static function () use ($root): array {
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

$env = $currentEnv();

/**
 * The URL this installer is being reached at, used as the default so that an
 * operator on a fresh server does not have to retype the address they are
 * already looking at.
 */
$guessUrl = static function (): string {
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    // A port in the Host header is part of the URL a local install needs.
    return ($https ? 'https://' : 'http://') . $host;
};

/**
 * The form's field names are not the names config/.env uses, so a lookup has to
 * be translated. Without this the form opens blank on every field even when the
 * file is full of perfectly good values, which reads as though nothing were
 * configured and invites the operator to retype it all.
 */
$envKeys = [
    'url'       => 'APP_URL',
    'base_path' => 'APP_BASE_PATH',
    'db_host'   => 'DB_HOST',
    'db_port'   => 'DB_PORT',
    'db_name'   => 'DB_DATABASE',
    'db_user'   => 'DB_USERNAME',
    'db_pass'   => 'DB_PASSWORD',
];

$value = static function (string $key, string $fallback = '') use ($env, $envKeys): string {
    $posted = $_POST[$key] ?? null;

    if (is_string($posted) && $posted !== '') {
        return $posted;
    }

    $stored = (string) ($env[$envKeys[$key] ?? $key] ?? '');

    // An empty stored value yields to the fallback only when there is one. That
    // lets base_path be pre-filled with the guess on a fresh install without an
    // empty string in a checked-in .env quietly winning over it, and leaves
    // every field with no fallback behaving exactly as before.
    if ($stored === '' && $fallback !== '') {
        return $fallback;
    }

    return $stored;
};

/**
 * The path the application is served under, guessed from this script's own
 * location.
 *
 * The installer sits in public/ and the project's own .htaccess forwards every
 * request from the root through to public/index.php without touching
 * REQUEST_URI, so the application is reached one segment higher than the
 * installer: /cahu3/public/install.php means the application lives at /cahu3.
 * Shown as the default so it does not normally have to be touched, but it is
 * editable: a server that rewrites requests can make this wrong, and a wrong
 * value here makes every route 404.
 */
$guessBasePath = static function (): string {
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($script === '') {
        return '';
    }

    $dir = rtrim(trim(dirname($script), '/'), '/');

    if (strtolower(basename($dir)) === 'public') {
        $dir = rtrim(trim(dirname($dir), '/'), '/');
    }

    return $dir === '' ? '' : '/' . $dir;
};

/**
 * Where the finished application will be: the guessed host plus the base path.
 * Used for the links on the success screen so they point at a page that
 * actually exists rather than at the site root.
 */
$loginUrl = static function () use ($value, $guessUrl, $guessBasePath): string {
    $base = $value('base_path', $guessBasePath());

    return rtrim($guessUrl(), '/') . '/' . trim($base, '/') . '/login';
};

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

$e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

/**
 * Renders one log line. The installer reports through a callback so it can be
 * reused verbatim by the command line front end, which means every message here
 * is arbitrary text and is escaped here.
 */
$renderLog = static function (array $lines) use ($e): string {
    $html = '';

    foreach ($lines as $line) {
        $level   = $line['level'];
        $message = $line['message'];

        if ($level === 'step') {
            $html .= '<h2>' . $e($message) . '</h2>';

            continue;
        }

        $mark = ['ok' => 'ok', 'warn' => 'warn', 'error' => 'fail'][$level] ?? 'info';

        $html .= '<div class="row ' . $mark . '"><span class="tag">' . $mark . '</span>'
            . '<span>' . nl2br($e($message)) . '</span></div>';
    }

    return $html;
};

$field = static function (
    string $name,
    string $label,
    string $type = 'text',
    string $hint = '',
    bool $required = false,
    string $fallback = ''
) use ($e, $value): void {
    $isSecret = $type === 'password';
    $filled   = $isSecret ? '' : $value($name, $fallback);

    echo '<label class="field' . ($required ? ' required' : '') . '">';
    echo '<span class="label">' . $e($label) . ($required ? ' <em>*</em>' : '') . '</span>';
    echo '<input type="' . $e($type) . '" name="' . $e($name) . '" value="' . $e($filled) . '"'
        . ($required ? ' required' : '')
        . ($isSecret ? ' autocomplete="new-password"' : '')
        . '>';
    echo '<span class="hint">' . $e($hint) . '</span>';
    echo '</label>';
};

/* ------------------------------------------------------------------ */
/* Page                                                                */
/* ------------------------------------------------------------------ */

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Install CAHU Platform</title>
<style>
    :root {
        --ink: #22301f;
        --muted: #6b7a68;
        --line: #d9ded6;
        --bg: #f6f7f5;
        --card: #ffffff;
        --ok: #1f7a3d;
        --warn: #8a6100;
        --fail: #b3261e;
        --accent: #2f6b3a;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        padding: 2.5rem 1.25rem 4rem;
        background: var(--bg);
        color: var(--ink);
        font: 16px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    }
    main { max-width: 46rem; margin: 0 auto; }
    h1 { font-size: 1.6rem; margin: 0 0 .25rem; }
    h2 {
        font-size: .82rem;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: var(--accent);
        margin: 2rem 0 .6rem;
        padding-bottom: .35rem;
        border-bottom: 1px solid var(--line);
    }
    .lede { color: var(--muted); margin: 0 0 1.75rem; }
    .path { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .82rem; color: var(--muted); }
    .card {
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: 8px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }
    .row {
        display: grid;
        grid-template-columns: 3.25rem 1fr;
        gap: .6rem;
        padding: .3rem 0;
        align-items: start;
        font-size: .92rem;
    }
    .tag {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: .74rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        padding: .1rem .35rem;
        border-radius: 3px;
        background: #eceeea;
        color: var(--muted);
        text-align: center;
    }
    .ok .tag { background: #e4f2e8; color: var(--ok); }
    .warn .tag { background: #fbf1da; color: var(--warn); }
    .fail .tag { background: #fbe6e4; color: var(--fail); }
    .fail { color: var(--fail); }
    .field { display: block; margin-bottom: 1.1rem; }
    .field .label { display: block; font-weight: 600; font-size: .9rem; margin-bottom: .3rem; }
    .field .label em { color: var(--fail); font-style: normal; }
    .field input {
        width: 100%;
        padding: .55rem .65rem;
        border: 1px solid var(--line);
        border-radius: 5px;
        font-size: .95rem;
        background: #fdfefd;
        color: var(--ink);
    }
    .field input:focus { outline: 2px solid var(--accent); outline-offset: 1px; border-color: var(--accent); }
    .field .hint { display: block; font-size: .8rem; color: var(--muted); margin-top: .25rem; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }
    @media (max-width: 34rem) { .grid { grid-template-columns: 1fr; } }
    .check { display: flex; gap: .55rem; align-items: flex-start; margin-bottom: .55rem; font-size: .9rem; }
    .check input { margin-top: .25rem; }
    .check span em { font-style: normal; font-weight: 600; }
    .check span small { display: block; color: var(--muted); }
    button {
        background: var(--accent);
        color: #fff;
        border: 0;
        border-radius: 5px;
        padding: .7rem 1.4rem;
        font-size: .98rem;
        font-weight: 600;
        cursor: pointer;
    }
    button:hover { background: #265a30; }
    button[disabled] { background: #9aa79a; cursor: not-allowed; }
    .banner { border-left: 4px solid var(--fail); background: #fbe6e4; padding: .9rem 1.1rem; border-radius: 4px; margin-bottom: 1.5rem; }
    .banner.good { border-color: var(--ok); background: #e4f2e8; }
    .banner h3 { margin: 0 0 .4rem; font-size: 1rem; }
    .banner p { margin: .35rem 0; font-size: .9rem; }
    .token {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        background: #eceeea;
        padding: .3rem .5rem;
        border-radius: 4px;
        font-size: .88rem;
        word-break: break-all;
        user-select: all;
    }
    ol { padding-left: 1.3rem; }
    ol li { margin-bottom: .3rem; font-size: .92rem; }
    code { background: #eceeea; padding: .1rem .3rem; border-radius: 3px; font-size: .88em; }
    footer { margin-top: 2.5rem; font-size: .82rem; color: var(--muted); }
</style>
</head>
<body>
<main>

<h1>Install CAHU Platform</h1>
<p class="lede">Six steps: check PHP, write <code>config/.env</code>, create the database, import the
schema, seed roles and the first administrator, create the writable directories.</p>
<p class="path"><?= $e($root) ?></p>

<?php if (!$writable['config/'] || !$writable['storage/']): ?>
    <div class="banner">
        <h3>The web server cannot write to this installation</h3>
        <?php if (!$writable['config/']): ?>
            <p><code>config/</code> is not writable, so <code>config/.env</code> cannot be written.
                Give the web server user write access to it, or run the installer from a shell instead:
                <code>php install.php</code>.</p>
        <?php endif; ?>
        <?php if (!$writable['storage/']): ?>
            <p><code>storage/</code> is not writable, so the installer token and the install lock
                cannot be stored. Give the web server user write access to it, or run
                <code>php install.php</code> from a shell instead.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($locked): ?>
    <div class="banner good">
        <h3>Already installed</h3>
        <p><code>storage/install.lock</code> exists, so the installer will not run again. That is
            what stops anyone reaching this page from resetting the database.</p>
        <p>Delete <code>public/install.php</code> now. If you need to install again, delete the
            lock file as well &mdash; and only if you mean it.</p>
    </div>
    <div class="card">
        <h3>Sign in</h3>
        <p><a href="<?= $e($loginUrl()) ?>">Open the application</a> and change
            the administrator password when prompted.</p>
    </div>

<?php elseif (!$tokenOk): ?>
    <div class="banner">
        <h3>This installer needs a token</h3>
        <p>It creates the first administrator and writes the file holding the database password, so
            it will not act for a request that cannot prove it is the operator.</p>
        <p>Read <code>storage/install.token</code> on the server and enter it below.</p>
    </div>
    <div class="card">
        <form method="post" autocomplete="off">
            <label class="field">
                <span class="label">Installer token <em>*</em></span>
                <input type="text" name="token" required autocomplete="off" autofocus>
                <span class="hint">Stored in <code>storage/install.token</code> on the server.</span>
            </label>
            <button type="submit">Continue</button>
        </form>
    </div>

<?php else: ?>
    <?php if ($fatal !== null): ?>
        <div class="banner">
            <h3>The installer stopped</h3>
            <p><?= nl2br($e($fatal)) ?></p>
            <p>Nothing was locked, so you can correct the values below and run it again. The database
                is never dropped, and an existing <code>config/.env</code> is kept unless you ask
                for it to be replaced.</p>
        </div>
    <?php endif; ?>

    <?php if ($ran && $log !== []): ?>
        <div class="card">
            <h2>Output</h2>
            <?= $renderLog($log) ?>
        </div>
    <?php endif; ?>

    <?php if ($ran && $finishedOk): ?>
        <div class="banner good">
            <h3>Installed</h3>
            <p>Delete <code>public/install.php</code>, then
                <a href="<?= $e($loginUrl()) ?>">sign in</a> and change the
                administrator password when prompted.</p>
        </div>
        <div class="card">
            <h2>Next steps</h2>
            <ol>
                <li>Delete <code>public/install.php</code> from the server.</li>
                <li>Point the document root at <code>public/</code>.</li>
                <li>Make sure <code>storage/</code> is writable by the web server user.</li>
                <li>Sign in and change the administrator password when prompted.</li>
            </ol>
        </div>
    <?php else: ?>
        <?php if (!$isHttps): ?>
            <div class="banner">
                <h3>This is not a secure connection</h3>
                <p>The database password and the administrator password are about to be submitted.
                    Over plain HTTP they cross the network in the clear. Use HTTPS, or run
                    <code>php install.php</code> from a shell on the server instead.</p>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <input type="hidden" name="token" value="<?= $e($supplied !== '' ? $supplied : $token) ?>">

            <div class="card">
                <h2>Application</h2>
                <?php $field('url', 'Application URL', 'text', 'Where the site will be reached, e.g. https://cahu.example.org', true); ?>
                <?php $field('base_path', 'Base path', 'text', 'Leave blank when the site is at the root of a domain. For a project in a subfolder it is the folder the site is reached at, e.g. /cahu3', false, $guessBasePath()); ?>
            </div>

            <div class="card">
                <h2>Database</h2>
                <div class="grid">
                    <?php $field('db_host', 'Host', 'text', 'Usually 127.0.0.1', true); ?>
                    <?php $field('db_port', 'Port', 'text', 'Usually 3306', true); ?>
                </div>
                <?php $field('db_name', 'Database name', 'text', 'Created if it does not exist', true); ?>
                <div class="grid">
                    <?php $field('db_user', 'Username', 'text', '', true); ?>
                    <?php
                    // Write-only. The current value is never sent back to the browser:
                    // rendering it into the page would put the password in the HTML,
                    // in the browser cache and in "view source".
                    echo '<label class="field"><span class="label">Password</span>'
                        . '<input type="password" name="db_pass" value="" autocomplete="new-password">'
                        . '<span class="hint">Leave blank to keep the value already in config/.env</span></label>';
                    ?>
                </div>
            </div>

            <div class="card">
                <h2>Administrator</h2>
                <div class="grid">
                    <?php $field('admin_user', 'Username', 'text', 'The first account', true); ?>
                    <?php $field('admin_email', 'Email address', 'text', 'Used for password resets', true); ?>
                </div>
                <div class="grid">
                    <?php $field('admin_pass', 'Password', 'password', 'At least 8 characters', true); ?>
                    <?php $field('admin_pass_confirm', 'Repeat password', 'password', '', true); ?>
                </div>
            </div>

            <div class="card">
                <h2>Email (optional)</h2>
                <p class="hint" style="margin-top:0">Leave the host blank to keep
                    <code>MAIL_TRANSPORT=log</code>, which writes mail to
                    <code>storage/logs</code> instead of sending it.</p>
                <div class="grid">
                    <?php $field('mail_host', 'SMTP host', 'text', 'e.g. smtp.gmail.com'); ?>
                    <?php $field('mail_port', 'SMTP port', 'text', '465 for ssl, 587 for tls'); ?>
                </div>
                <div class="grid">
                    <?php $field('mail_user', 'SMTP username', 'text'); ?>
                    <?php $field('mail_pass', 'SMTP password', 'password'); ?>
                </div>
                <div class="grid">
                    <?php $field('mail_encryption', 'Encryption', 'text', 'ssl or tls'); ?>
                    <?php $field('mail_from', 'Sender address', 'text'); ?>
                </div>
                <?php $field('test_to', 'Send a test message to', 'text', 'Only used when an SMTP host is set'); ?>
                <label class="check">
                    <input type="checkbox" name="test_mail" value="1" <?= isset($_POST['test_mail']) ? 'checked' : '' ?>>
                    <span><em>Send a test message during installation</em>
                        <small>Proves the SMTP settings actually work. Leave unticked on a
                            development machine with no mail server.</small></span>
                </label>
            </div>

            <div class="card">
                <h2>Options</h2>
                <label class="check">
                    <input type="checkbox" name="force" value="1" <?= isset($_POST['force']) ? 'checked' : '' ?>>
                    <span><em>Replace config/.env</em>
                        <small>Otherwise the existing file is left alone and its values are used.
                            The form above is then only read for the fields it can add.</small></span>
                </label>
                <label class="check">
                    <input type="checkbox" name="import_schema" value="1" <?= isset($_POST['import_schema']) ? 'checked' : '' ?>>
                    <span><em>Import the schema into a database that already has tables</em>
                        <small>Off by default. Nothing is ever dropped, so an import that collides
                            will fail part way rather than overwrite.</small></span>
                </label>
                <label class="check">
                    <input type="checkbox" name="skip_db" value="1" <?= isset($_POST['skip_db']) ? 'checked' : '' ?>>
                    <span><em>Do not create the database or import the schema</em></span>
                </label>
                <label class="check">
                    <input type="checkbox" name="skip_seed" value="1" <?= isset($_POST['skip_seed']) ? 'checked' : '' ?>>
                    <span><em>Do not seed roles or create an administrator</em>
                        <small>Nobody can sign in afterwards until the seeder has been run.</small></span>
                </label>
                <label class="check">
                    <input type="checkbox" name="demo" value="1" <?= isset($_POST['demo']) ? 'checked' : '' ?>>
                    <span><em>Create one demo account per role</em>
                        <small>Each must change its password on first sign-in, but the shared
                            password is public. Delete them before real users arrive.</small></span>
                </label>
            </div>

            <button type="submit">Install</button>
        </form>

        <?php if (!$isLocal && $token !== ''): ?>
            <div class="card">
                <h2>Token</h2>
                <p>This installer was reached from <code><?= $e((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) ?></code>,
                    so it needs the token from <code>storage/install.token</code> on the server.</p>
                <p class="token"><?= $e($token) ?></p>
                <p class="hint">Shown here only because this request already presented it.</p>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<footer>
    <p>The same installation runs from a shell:
        <code>php install.php</code>. Delete this file once it has finished.</p>
</footer>

</main>
</body>
</html>