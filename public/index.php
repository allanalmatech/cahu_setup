<?php
declare(strict_types=1);

/**
 * Public entry point. Everything the browser requests is routed through here.
 *
 * The document root should point at this directory. The project root's
 * .htaccess rewrites requests here as well, so a host that was pointed at the
 * project root by mistake still lands on this file.
 */

define('CAHU_START', microtime(true));

require __DIR__ . '/../app/bootstrap.php';

/*
 * Read the configuration before anything else can fail.
 *
 * The order here matters more than it looks. APP_DEBUG is what decides whether
 * PHP shows error output at all, and it has to be applied before the first
 * statement that can fail - otherwise a host running a development php.ini
 * (which is the default in a surprising number of shared-hosting panels and in
 * Docker images built on php:apache) prints a raw PDO error to an anonymous
 * visitor, complete with the database host and username. Setting it from
 * configuration means APP_DEBUG=false is the control, not the php.ini.
 */
try {
    $config = require __DIR__ . '/../config/config.php';
} catch (\Throwable $exception) {
    /*
     * A missing or unreadable config/.env lands here, before Logger is bound and
     * before there is any configuration to honour. Nothing is revealed about the
     * filesystem: the reason goes to the server's own error log, and the
     * visitor gets a request to contact the administrator.
     */
    error_log('cahu: configuration could not be loaded - ' . $exception->getMessage());

    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: 600');

    echo "The platform is not available yet. Please contact the administrator.\n";

    exit;
}

$GLOBALS['cahu_config'] = $config;

$cahuDebug = (bool) ($config['app']['debug'] ?? false);

ini_set('display_errors', $cahuDebug ? '1' : '0');
ini_set('display_startup_errors', $cahuDebug ? '1' : '0');

error_reporting($cahuDebug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);

date_default_timezone_set($config['app']['timezone']);
mb_internal_encoding('UTF-8');

/*
 * Core\App::run() handles its own exceptions and renders them. The constructor
 * is the part that has no such protection, and it is the part that connects to
 * the database - so a database that is down, a config that is half written, or a
 * storage directory that cannot be created all fail here rather than inside the
 * application's error handling. Left uncaught, with display_errors off, that is
 * a blank page; left uncaught on a host that ignores our php.ini settings, it is
 * a stack trace with file paths in it. Neither is an acceptable answer, so the
 * boot is wrapped and a neutral page is returned.
 */
try {
    $app = new Core\App($config);
    $app->run();
} catch (\Throwable $exception) {
    try {
        Core\Logger::bind($config['paths']['storage'] . '/logs');
        Core\Logger::exception($exception);
    } catch (\Throwable) {
        // Never let the error handler be the thing that fails.
        error_log('cahu: boot failure - ' . $exception->getMessage());
    }

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }

    if ($cahuDebug) {
        echo '<pre>' . htmlspecialchars(
            $exception->getMessage() . "\n\n" . $exception->getTraceAsString(),
            ENT_QUOTES,
            'UTF-8'
        ) . '</pre>';

        exit;
    }

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Platform unavailable</title>'
        . '<style>body{font-family:system-ui,sans-serif;margin:0;padding:3rem 1.5rem;'
        . 'background:#f6f7f5;color:#22301f}main{max-width:32rem;margin:0 auto}'
        . 'h1{font-size:1.35rem;margin:0 0 .5rem}p{line-height:1.6;margin:0 0 1rem;opacity:.8}'
        . 'code{background:#eceeea;padding:.1rem .35rem;border-radius:.2rem}</style></head><body><main>'
        . '<h1>The platform is temporarily unavailable</h1>'
        . '<p>The application could not start. This is usually the database being '
        . 'unreachable, or an installation step that has not been completed.</p>'
        . '<p>If you administer this site, check <code>storage/logs</code> for the '
        . 'full error and confirm that <code>config/.env</code> is present.</p>'
        . '</main></body></html>';
}
