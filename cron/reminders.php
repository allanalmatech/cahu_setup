<?php
declare(strict_types=1);

/**
 * Housekeeping and status transitions.
 *
 *  - Activities that passed their due date move to 'overdue', and activities
 *    whose date has arrived move to 'due'. The screens count overdue as
 *    "overdue OR past due and still open" so they stay truthful between runs,
 *    but the stored status is what every filter sorts on, so it has to be right.
 *  - Expired password resets and stale sessions are cleared.
 *  - Accounts whose lockout window has elapsed are unlocked.
 *  - Deliveries that were expected but never arrived are flagged for attention.
 *
 * Schedule:  0 1 * * *   php cron/reminders.php
 */

require __DIR__ . '/bootstrap.php';

if (!cahu_cron_lock('reminders', 1800)) {
    cahu_cron_say('Another reminders run is still in progress. Skipping.');
    exit(0);
}

$db   = cahu_cron_db();
$days = cahu_cron_days();
$log  = [];

try {
    $due = $db->update(
        'crop_activities',
        ['status' => 'due'],
        "status = 'pending' AND due_date <= :today AND due_date >= DATE_SUB(:today2, INTERVAL 7 DAY)",
        ['today' => date('Y-m-d'), 'today2' => date('Y-m-d')]
    );

    $log['activities_due'] = $due;

    // Anything already past its due date and still open is overdue. The lower
    // bound keeps a long-neglected backlog from being swept in one go; re-run
    // with --days=N to widen the window.
    $overdue = $db->update(
        'crop_activities',
        ['status' => 'overdue'],
        "status IN ('pending', 'due')
           AND due_date < :today
           AND due_date >= DATE_SUB(:today2, INTERVAL :days DAY)",
        ['today' => date('Y-m-d'), 'today2' => date('Y-m-d'), 'days' => $days]
    );

    $log['activities_overdue'] = $overdue;

    // Inspections that are still unperformed move to overdue for the same reason.
    $inspections = $db->update(
        'inspections',
        ['status' => 'overdue'],
        "status IN ('scheduled', 'pending') AND inspection_date < :today",
        ['today' => date('Y-m-d')]
    );

    $log['inspections_overdue'] = $inspections;

    // Password resets that were never used have expired.
    $resets = $db->delete(
        'password_resets',
        'expires_at < :now AND used_at IS NULL',
        ['now' => date('Y-m-d H:i:s')]
    );

    $log['password_resets_purged'] = $resets;

    // Sessions idle for more than a day, and sessions for deactivated accounts.
    $sessions = $db->delete(
        'user_sessions',
        'last_activity < DATE_SUB(:now, INTERVAL 1 DAY)',
        ['now' => date('Y-m-d H:i:s')]
    );

    $log['stale_sessions_purged'] = $sessions;

    $db->delete(
        'user_sessions',
        'user_id IN (SELECT id FROM users WHERE status = :status OR deleted_at IS NOT NULL)',
        ['status' => 'deactivated']
    );

    // An expired lockout must not leave a farmer locked out of their account
    // forever, because nobody would think to look.
    $unlocked = $db->update(
        'users',
        ['locked_until' => null, 'failed_attempts' => 0],
        'locked_until IS NOT NULL AND locked_until < :now',
        ['now' => date('Y-m-d H:i:s')]
    );

    $log['accounts_unlocked'] = $unlocked;

    // Deliveries expected in the past that have not been delivered.
    $deliveries = $db->select(
        'SELECT delivery_code, expected_date FROM deliveries
         WHERE status IN (:in_transit, :pending)
           AND expected_date IS NOT NULL
           AND expected_date < :today
         LIMIT 200',
        ['in_transit' => 'in_transit', 'pending' => 'pending', 'today' => date('Y-m-d')]
    );

    $log['deliveries_late'] = count($deliveries);

    // The sweep itself is worth an audit trail entry: it is the only record of
    // when statuses were moved by a machine rather than by a person.
    Core\Audit::log('cron', 'maintenance', 'reminder_sweep', null, 'nightly', null, $log);
} catch (Throwable $exception) {
    cahu_cron_say('FAILED: ' . $exception->getMessage());
    cahu_cron_unlock('reminders');
    exit(1);
}

cahu_cron_say('Reminders complete.');

foreach ($log as $key => $value) {
    cahu_cron_say('  ' . $key . ': ' . $value);
}

cahu_cron_unlock('reminders');
