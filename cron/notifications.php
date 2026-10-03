<?php
declare(strict_types=1);

/**
 * Daily notification sweep.
 *
 * Fans out the reminders the specification asks the platform to send: field
 * visits due today or tomorrow, activities falling due and going overdue,
 * inspections that are overdue, spray follow-ups falling due, sales becoming
 * overdue for payment, and problem reports still sitting unassigned.
 *
 * Every notification carries a deterministic dedupe_key derived from the record
 * it refers to, so re-running the job never sends the same reminder twice —
 * Notifier drops a write when the key already exists.
 *
 * Schedule:  0 6 * * *   php cron/notifications.php
 */

require __DIR__ . '/bootstrap.php';

use Services\Notifier;

if (!cahu_cron_lock('notifications', 1800)) {
    cahu_cron_say('Another notifications run is still in progress. Skipping.');
    exit(0);
}

$db       = cahu_cron_db();
$notifier = new Notifier($db);
$days     = cahu_cron_days();
$horizon  = date('Y-m-d', strtotime('+' . $days . ' days'));
$sent     = 0;

try {
    $db->transaction(static function () use ($db, $notifier, $horizon): void {
        // Visits scheduled within the next day.
        foreach ($db->select(
            'SELECT v.id, v.visit_code, v.visit_date, v.status,
                    f.full_name AS farmer_name, f.farmer_code, f.region_id, f.district_id,
                    o.user_id AS officer_user_id, o.full_name AS officer_name
             FROM field_visits v
             INNER JOIN farmers f ON f.id = v.farmer_id
             LEFT JOIN field_officers o ON o.id = v.officer_id
             WHERE v.visit_date BETWEEN CURDATE() AND :horizon
               AND v.status = :planned AND f.deleted_at IS NULL',
            ['horizon' => $horizon, 'planned' => 'planned']
        ) as $visit) {
            if ($visit['officer_user_id'] === null) {
                continue;
            }

            $notifier->notify([
                'type'        => 'visit_due',
                'title'       => 'Field visit due',
                'message'     => 'Visit to ' . $visit['farmer_name'] . ' on ' . $visit['visit_date'] . '.',
                'user_id'     => (int) $visit['officer_user_id'],
                'farmer_id'   => null,
                'entity_type' => 'field_visit',
                'entity_id'   => (int) $visit['id'],
                'link'        => '/visits/' . $visit['visit_code'],
                'channels'    => ['system', 'sms'],
                'dedupe_key'  => 'visit_due:' . $visit['visit_code'],
            ]);
        }

        // Activities falling due within the horizon, and anything already late.
        foreach ($db->select(
            'SELECT a.id, a.activity_code, a.activity_type, a.due_date, a.status,
                    g.garden_code, f.farmer_code,
                    o.user_id AS officer_user_id, fu.user_id AS farmer_user_id
             FROM crop_activities a
             INNER JOIN gardens g ON g.id = a.garden_id
             INNER JOIN farmers f ON f.id = a.farmer_id
             LEFT JOIN field_officers o ON o.id = a.officer_id
             LEFT JOIN users fu ON fu.farmer_id = f.id AND fu.deleted_at IS NULL
             WHERE a.status IN (:pending, :due)
               AND a.due_date <= :horizon
               AND g.deleted_at IS NULL',
            ['pending' => 'pending', 'due' => 'due', 'horizon' => $horizon]
        ) as $activity) {
            $late = strtotime((string) $activity['due_date']) < strtotime(date('Y-m-d'));

            // Address the activity to whoever is accountable for it: the farmer
            // when they hold a portal account, otherwise the officer who scheduled it.
            $recipientId = $activity['farmer_user_id'] !== null
                ? (int) $activity['farmer_user_id']
                : ($activity['officer_user_id'] === null ? null : (int) $activity['officer_user_id']);

            if ($recipientId === null) {
                continue;
            }

            $notifier->notify([
                'type'        => $late ? 'activity_overdue' : 'activity_due',
                'title'       => $late ? 'Activity overdue' : 'Activity due',
                'message'     => ucfirst(str_replace('_', ' ', (string) $activity['activity_type']))
                    . ' on garden ' . $activity['garden_code'] . ' was due ' . $activity['due_date'] . '.',
                'user_id'     => $recipientId,
                'entity_type' => 'crop_activity',
                'entity_id'   => (int) $activity['id'],
                'link'        => '/activities/' . $activity['activity_code'],
                'channels'    => ['system'],
                'dedupe_key'  => ($late ? 'activity_overdue:' : 'activity_due:') . $activity['activity_code'],
            ]);
        }

        // Inspections that were due and have not been carried out.
        foreach ($db->select(
            'SELECT i.id, i.inspection_code, i.inspection_date, i.status,
                    g.garden_code, o.user_id AS officer_user_id, o.full_name AS officer_name
             FROM inspections i
             INNER JOIN gardens g ON g.id = i.garden_id
             LEFT JOIN field_officers o ON o.id = i.officer_id
             WHERE i.inspection_date < CURDATE()
               AND i.status IN (:scheduled, :pending)
               AND g.deleted_at IS NULL',
            ['scheduled' => 'scheduled', 'pending' => 'pending']
        ) as $inspection) {
            if ($inspection['officer_user_id'] === null) {
                continue;
            }

            $notifier->notify([
                'type'        => 'inspection_overdue',
                'title'       => 'Inspection overdue',
                'message'     => 'Inspection of garden ' . $inspection['garden_code']
                    . ' was due ' . $inspection['inspection_date'] . '.',
                'user_id'     => (int) $inspection['officer_user_id'],
                'entity_type' => 'inspection',
                'entity_id'   => (int) $inspection['id'],
                'link'        => '/inspections/' . $inspection['inspection_code'],
                'channels'    => ['system', 'sms'],
                'dedupe_key'  => 'inspection_overdue:' . $inspection['inspection_code'],
            ]);
        }

        // Spraying rounds whose next application date has arrived.
        foreach ($db->select(
            'SELECT s.id, s.spray_code, s.next_application_date, s.product_name,
                    g.garden_code, o.user_id AS officer_user_id, fu.user_id AS farmer_user_id
             FROM spraying_records s
             INNER JOIN gardens g ON g.id = s.garden_id
             INNER JOIN farmers f ON f.id = s.farmer_id
             LEFT JOIN field_officers o ON o.id = s.officer_id
             LEFT JOIN users fu ON fu.farmer_id = f.id AND fu.deleted_at IS NULL
             WHERE s.next_application_date IS NOT NULL
               AND s.next_application_date <= :horizon',
            ['horizon' => $horizon]
        ) as $spray) {
            $recipientId = $spray['farmer_user_id'] !== null
                ? (int) $spray['farmer_user_id']
                : ($spray['officer_user_id'] === null ? null : (int) $spray['officer_user_id']);

            if ($recipientId === null) {
                continue;
            }

            $notifier->notify([
                'type'        => 'spray_followup',
                'title'       => 'Spraying due',
                'message'     => 'Next application of ' . $spray['product_name'] . ' is due on '
                    . $spray['next_application_date'] . '.',
                'entity_type' => 'spraying_record',
                'entity_id'   => (int) $spray['id'],
                'link'        => '/spraying/' . $spray['spray_code'],
                'channels'    => ['system'],
                'dedupe_key'  => 'spray_next:' . $spray['spray_code'],
            ]);
        }

        // Sales past their payment due date and not yet settled.
        foreach ($db->select(
            'SELECT s.id, s.sale_code, s.total_value, s.amount_paid, s.payment_due_date,
                    b.name AS buyer_name
             FROM sales s
             INNER JOIN buyers b ON b.id = s.buyer_id
             WHERE s.payment_due_date < CURDATE()
               AND s.payment_status IN (:unpaid, :partial)
               AND s.status <> :cancelled',
            ['unpaid' => 'unpaid', 'partial' => 'partial', 'cancelled' => 'cancelled']
        ) as $sale) {
            $recipient = $db->selectOne(
                'SELECT u.id FROM users u
                 INNER JOIN user_roles ur ON ur.user_id = u.id
                 INNER JOIN roles r ON r.id = ur.role_id
                 WHERE r.code IN (:sales, :manager, :admin) AND u.deleted_at IS NULL
                 ORDER BY FIELD(r.code, :manager2, :admin2, :sales2) LIMIT 1',
                ['sales' => 'sales_manager', 'manager' => 'manager', 'admin' => 'super_admin',
                 'manager2' => 'manager', 'admin2' => 'super_admin', 'sales2' => 'sales_manager']
            );

            if ($recipient === null) {
                continue;
            }

            $notifier->notify([
                'type'        => 'payment_overdue',
                'title'       => 'Payment overdue',
                'message'     => 'Sale ' . $sale['sale_code'] . ' to ' . $sale['buyer_name']
                    . ' was due ' . $sale['payment_due_date'] . '.',
                'user_id'     => (int) $recipient['id'],
                'entity_type' => 'sale',
                'entity_id'   => (int) $sale['id'],
                'link'        => '/sales/' . $sale['sale_code'],
                'channels'    => ['system'],
                'dedupe_key'  => 'payment_overdue:' . $sale['sale_code'],
            ]);
        }

        // Problem reports sitting in the queue with nobody assigned.
        foreach ($db->select(
            'SELECT pr.id, pr.problem_code, pr.report_date, pr.severity,
                    f.full_name AS farmer_name, g.garden_code
             FROM problem_reports pr
             INNER JOIN farmers f ON f.id = pr.farmer_id
             LEFT JOIN gardens g ON g.id = pr.garden_id
             WHERE pr.assigned_officer_id IS NULL
               AND pr.status = :reported
               AND pr.report_date <= :today',
            ['reported' => 'reported', 'today' => date('Y-m-d')]
        ) as $problem) {
            $officer = $db->selectOne(
                'SELECT user_id FROM field_officers
                 WHERE deleted_at IS NULL AND user_id IS NOT NULL
                 ORDER BY id ASC LIMIT 1'
            );

            if ($officer === null) {
                continue;
            }

            $notifier->notify([
                'type'        => 'problem_unassigned',
                'title'       => 'Problem report awaiting assignment',
                'message'     => ucfirst((string) $problem['severity']) . ' problem reported by '
                    . $problem['farmer_name'] . ' on ' . $problem['report_date'] . '.',
                'user_id'     => (int) $officer['user_id'],
                'entity_type' => 'problem_report',
                'entity_id'   => (int) $problem['id'],
                'link'        => '/problems/' . $problem['problem_code'],
                'channels'    => ['system'],
                'dedupe_key'  => 'problem_unassigned:' . $problem['problem_code'],
            ]);
        }
    });
} catch (Throwable $exception) {
    cahu_cron_say('FAILED: ' . $exception->getMessage());
    cahu_cron_unlock('notifications');
    exit(1);
}

$sent = (int) $db->scalar('SELECT COUNT(*) FROM notifications WHERE created_at >= CURDATE()', []);

cahu_cron_say('Notifications sweep complete. ' . $sent . ' notification(s) created today.');
cahu_cron_unlock('notifications');
