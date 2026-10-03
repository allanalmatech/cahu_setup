<?php
declare(strict_types=1);

/**
 * Shared bootstrap for the scheduled jobs.
 *
 * Cron jobs do not go through App, because routing an HTTP request to a
 * background task would mean running it inside a web request. They load the same
 * autoloader and the same config, then wire up the Database directly.
 *
 * Every job is guarded by a lock file so a slow run cannot overlap with the next
 * scheduled one, and every job is idempotent: re-running it produces no duplicate
 * rows and no duplicate messages.
 */

if (PHP_SAPI !== 'cli' && !defined('CAHU_CRON_HTTP')) {
    fwrite(STDERR, "This script may only be run from the command line.\n");
    exit(1);
}

define('CAHU_CRON', true);

require __DIR__ . '/../app/bootstrap.php';

$config = require __DIR__ . '/../config/config.php';

date_default_timezone_set((string) $config['app']['timezone']);
mb_internal_encoding('UTF-8');

$GLOBALS['cahu_config'] = $config;

/**
 * Bootstraps the database once per run and returns it.
 */
function cahu_cron_db(): Core\Database
{
    static $db = null;

    if ($db === null) {
        $db = Core\Database::instance((array) $GLOBALS['cahu_config']['database']);
    }

    return $db;
}

/**
 * Claims an exclusive lock for a job. Returns false when another run still
 * holds it, which is the signal to exit quietly rather than to duplicate work.
 */
function cahu_cron_lock(string $job, int $ttl = 3600): bool
{
    $path = cahu_cron_lock_path($job);

    if (is_file($path)) {
        $age = time() - (int) filemtime($path);

        if ($age < $ttl) {
            return false;
        }

        // The previous run died without releasing; its lock has expired.
        @unlink($path);
    }

    return @file_put_contents($path, (string) getmypid()) !== false;
}

function cahu_cron_unlock(string $job): void
{
    @unlink(cahu_cron_lock_path($job));
}

function cahu_cron_lock_path(string $job): string
{
    $directory = dirname(__DIR__) . '/storage/cache';

    if (!is_dir($directory)) {
        @mkdir($directory, 0770, true);
    }

    return $directory . '/cron-' . preg_replace('/[^a-z0-9_-]/i', '', $job) . '.lock';
}

function cahu_cron_say(string $message): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
}

/**
 * The cutoff for the lookback window, overridable with --days=N for an
 * operator who wants to re-scan a longer period after a downtime.
 */
function cahu_cron_days(): int
{
    $days = 1;

    foreach ($argv ?? [] as $argument) {
        if (preg_match('/^--days=(\d+)$/', (string) $argument, $matches) === 1) {
            $days = max(1, min(90, (int) $matches[1]));
        }
    }

    return $days;
}
