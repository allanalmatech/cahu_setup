<?php
declare(strict_types=1);

/**
 * Single entry point for all scheduled jobs.
 *
 * Usage:
 *   php cron/run.php notifications
 *   php cron/run.php forecasts
 *   php cron/run.php reminders
 *   php cron/run.php all --days=7
 *
 * A hosting panel that can only be pointed at one script uses this one. Each job
 * runs in its own process via PHP_BINARY so a fatal error in one cannot take the
 * rest of the queue with it.
 */

$jobs = ['notifications', 'forecasts', 'reminders'];

$requested = $argv[1] ?? 'all';
$options   = array_slice($argv, 2);
$root      = __DIR__;

if ($requested !== 'all' && !in_array($requested, $jobs, true)) {
    fwrite(STDERR, 'Unknown job: ' . $requested . PHP_EOL);
    fwrite(STDERR, 'Available jobs: ' . implode(', ', $jobs) . ', all' . PHP_EOL);
    exit(1);
}

$queue = $requested === 'all' ? $jobs : [$requested];
$failed = 0;

foreach ($queue as $job) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/' . $job . '.php');

    foreach ($options as $option) {
        $command .= ' ' . escapeshellarg($option);
    }

    fwrite(STDOUT, '>>> ' . $job . PHP_EOL);

    passthru($command, $status);

    if ($status !== 0) {
        $failed++;
        fwrite(STDERR, 'Job ' . $job . ' failed with status ' . $status . '.' . PHP_EOL);
    }
}

exit($failed === 0 ? 0 : 1);
