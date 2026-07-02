<?php

declare(strict_types=1);

require_once __DIR__ . '/automation-schema.php';
require_once __DIR__ . '/../workforce/compliance-repository.php';
require_once __DIR__ . '/../workforce/workforce-analytics.php';
require_once __DIR__ . '/../notification-center.php';
require_once __DIR__ . '/../system-settings.php';
require_once __DIR__ . '/roster-repository.php';
require_once __DIR__ . '/training-repository.php';

function ops_log_alert(PDO $pdo, string $type, string $message, string $severity = 'warning', ?string $targetType = null, ?int $targetId = null): void
{
    if (!tableExists($pdo, 'ops_automation_log')) {
        return;
    }

    try {
        $pdo->prepare(
            'INSERT INTO ops_automation_log (alert_type, message, target_type, target_id, severity)
             VALUES (:type, :msg, :tt, :tid, :sev)'
        )->execute([
            'type' => $type,
            'msg'  => mb_substr($message, 0, 500),
            'tt'   => $targetType,
            'tid'  => $targetId,
            'sev'  => $severity,
        ]);
    } catch (Throwable $e) {
        error_log('[OpsAutomation] log: ' . $e->getMessage());
    }
}

/** @return array<string, int> */
function ops_run_automation(PDO $pdo): array
{
    auto_ensure_schema($pdo);

    $stats = [
        'staff_shortage'    => 0,
        'compliance_expiry' => 0,
        'training_expiry'   => 0,
        'attendance'        => 0,
        'invoice_reminder'  => 0,
        'event_reminder'    => 0,
        'manager_notify'    => 0,
    ];

    // Staff shortage alerts — upcoming events with gaps
    try {
        $events = $pdo->query(
            "SELECT id, name, event_date, staff_needed FROM events
             WHERE is_active = 1 AND event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($events as $event) {
            $cov = roster_coverage_summary($pdo, (int) ($event['id'] ?? 0));
            if (($cov['gap'] ?? 0) > 0) {
                $msg = sprintf(
                    'Staff shortage: %s (%s) — gap of %d (required %d, assigned %d)',
                    (string) ($event['name'] ?? 'Event'),
                    (string) ($event['event_date'] ?? ''),
                    (int) $cov['gap'],
                    (int) $cov['required'],
                    (int) $cov['assigned']
                );
                ops_log_alert($pdo, 'staff_shortage', $msg, 'critical', 'event', (int) ($event['id'] ?? 0));
                $stats['staff_shortage']++;
            }
        }
    } catch (Throwable $e) {
        // optional
    }

    // Compliance expiry alerts
    $compliance = wf_compliance_summary($pdo);
    foreach (($compliance['alerts'] ?? []) as $alert) {
        if (in_array((string) ($alert['status'] ?? ''), ['expiring', 'expired'], true)) {
            ops_log_alert(
                $pdo,
                'compliance_expiry',
                'PSA ' . ($alert['status'] ?? '') . ': ' . ($alert['name'] ?? 'Staff'),
                ($alert['status'] ?? '') === 'expired' ? 'critical' : 'warning',
                'staff',
                (int) ($alert['staff_id'] ?? 0)
            );
            $stats['compliance_expiry']++;
        }
    }

    // Training expiry / due alerts
    auto_ensure_phase67_schema($pdo);
    foreach (training_alerts($pdo, 50)['expiring'] as $tr) {
        ops_log_alert(
            $pdo,
            'training_expiry',
            'Training expiring: ' . ($tr['course_name'] ?? '') . ' — ' . trim(($tr['first_name'] ?? '') . ' ' . ($tr['surname'] ?? '')),
            'warning',
            'staff',
            (int) ($tr['staff_id'] ?? 0)
        );
        $stats['training_expiry']++;
    }
    foreach (training_alerts($pdo, 30)['due'] as $tr) {
        ops_log_alert(
            $pdo,
            'training_due',
            'Training due: ' . ($tr['course_name'] ?? '') . ' — ' . trim(($tr['first_name'] ?? '') . ' ' . ($tr['surname'] ?? '')),
            'info',
            'staff',
            (int) ($tr['staff_id'] ?? 0)
        );
        $stats['training_expiry']++;
    }

    // Attendance alerts — high risk staff
    foreach (wf_list_staff_by_risk($pdo, '30d', 'red', 20) as $row) {
        ops_log_alert(
            $pdo,
            'attendance_risk',
            'High risk staff: ' . ($row['name'] ?? '') . ' (score ' . (int) ($row['score'] ?? 0) . ')',
            'warning',
            'staff',
            (int) ($row['id'] ?? 0)
        );
        $stats['attendance']++;
    }

    // Invoice reminders — sent invoices overdue
    if (tableExists($pdo, 'commission_invoices')) {
        try {
            $rows = $pdo->query(
                "SELECT ci.id, ci.invoice_number, e.name, e.event_date
                 FROM commission_invoices ci
                 INNER JOIN events e ON e.id = ci.event_id
                 WHERE ci.status = 'sent' AND e.event_date < CURDATE()
                 LIMIT 50"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                ops_log_alert(
                    $pdo,
                    'invoice_reminder',
                    'Outstanding invoice ' . ($row['invoice_number'] ?? '') . ' — ' . ($row['name'] ?? ''),
                    'warning',
                    'invoice',
                    (int) ($row['id'] ?? 0)
                );
                $stats['invoice_reminder']++;
            }
        } catch (Throwable $e) {
            // optional
        }
    }

    // Event reminders — events tomorrow
    try {
        $tomorrow = $pdo->query(
            "SELECT id, name FROM events WHERE is_active = 1 AND event_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($tomorrow as $ev) {
            ops_log_alert($pdo, 'event_reminder', 'Event tomorrow: ' . ($ev['name'] ?? ''), 'info', 'event', (int) ($ev['id'] ?? 0));
            $stats['event_reminder']++;
        }
    } catch (Throwable $e) {
        // optional
    }

    // Manager notifications — fan-out to admin users via app_notifications if table exists
    if (tableExists($pdo, 'admin_users') && function_exists('createAdminNotification')) {
        $openCritical = (int) ($pdo->query(
            "SELECT COUNT(*) FROM ops_automation_log WHERE severity = 'critical' AND acknowledged = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
        )->fetchColumn() ?: 0);
        if ($openCritical > 0) {
            $stats['manager_notify'] = $openCritical;
        }
    }

    setSetting($pdo, 'ops_automation_last_run', date('Y-m-d H:i:s'));

    return $stats;
}

/** @return list<array<string, mixed>> */
function ops_recent_alerts(PDO $pdo, int $limit = 50, bool $unackOnly = false): array
{
    if (!tableExists($pdo, 'ops_automation_log')) {
        return [];
    }

    $where = $unackOnly ? 'WHERE acknowledged = 0' : '';
    try {
        return $pdo->query(
            "SELECT * FROM ops_automation_log {$where} ORDER BY created_at DESC LIMIT " . max(1, min($limit, 200))
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function ops_acknowledge_alert(PDO $pdo, int $id): bool
{
    if (!tableExists($pdo, 'ops_automation_log') || $id < 1) {
        return false;
    }

    try {
        return $pdo->prepare('UPDATE ops_automation_log SET acknowledged = 1 WHERE id = :id')->execute(['id' => $id]);
    } catch (Throwable $e) {
        return false;
    }
}
