<?php

declare(strict_types=1);

require_once __DIR__ . '/platform-schema.php';
require_once __DIR__ . '/../settings-repository.php';

/** @return array<int, array<string, mixed>> */
function listPayrollAlerts(PDO $pdo, int $limit = 50, bool $includeResolved = false): array
{
    ensurePlatformMaturitySchema($pdo);
    $limit = max(1, min($limit, 200));

    $sql = 'SELECT * FROM platform_payroll_alerts';
    if (!$includeResolved) {
        $sql .= ' WHERE resolved_at IS NULL';
    }
    $sql .= ' ORDER BY created_at DESC LIMIT ' . $limit;

    try {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function countPayrollAlerts(PDO $pdo, bool $openOnly = true): int
{
    ensurePlatformMaturitySchema($pdo);
    try {
        $sql = 'SELECT COUNT(*) FROM platform_payroll_alerts';
        if ($openOnly) {
            $sql .= ' WHERE resolved_at IS NULL';
        }

        return (int) $pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function resolvePayrollAlert(PDO $pdo, int $alertId): bool
{
    ensurePlatformMaturitySchema($pdo);
    try {
        $stmt = $pdo->prepare('UPDATE platform_payroll_alerts SET resolved_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $alertId]);

        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function payrollAlertTypeLabel(string $type): string
{
    return match ($type) {
        'duplicate_payments'  => 'Duplicate hours rows',
        'unpaid_staff'        => 'Missing hours log',
        'missing_hours'       => 'Missing hours',
        'overtime'            => 'Overtime',
        'attendance_mismatch' => 'Attendance mismatch',
        default               => ucfirst(str_replace('_', ' ', $type)),
    };
}

function insertPayrollAlert(PDO $pdo, string $type, string $severity, string $title, string $body, ?int $relatedId = null): void
{
    ensurePlatformMaturitySchema($pdo);
    if (!in_array($severity, ['info', 'warn', 'critical'], true)) {
        $severity = 'warn';
    }

    try {
        if ($relatedId !== null && $relatedId > 0) {
            $dup = $pdo->prepare(
                'SELECT COUNT(*) FROM platform_payroll_alerts
                 WHERE alert_type = :type AND related_id = :related_id AND resolved_at IS NULL'
            );
            $dup->execute(['type' => substr($type, 0, 50), 'related_id' => $relatedId]);
            if ((int) $dup->fetchColumn() > 0) {
                return;
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO platform_payroll_alerts (alert_type, severity, title, body, related_id)
            VALUES (:type, :severity, :title, :body, :related_id)
        ");
        $stmt->execute([
            'type'       => substr($type, 0, 50),
            'severity'   => $severity,
            'title'      => substr($title, 0, 200),
            'body'       => $body,
            'related_id' => $relatedId,
        ]);
    } catch (Throwable $e) {
        error_log('[EventStaff] payroll alert: ' . $e->getMessage());
    }
}

/** @return array<int, array<string, mixed>> */
function listPayrollAlertsForEvent(PDO $pdo, int $eventId): array
{
    ensurePlatformMaturitySchema($pdo);
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM platform_payroll_alerts
            WHERE resolved_at IS NULL AND related_id = :eid
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute(['eid' => $eventId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Scan for payroll anomalies and persist alerts. @return array<string, int> */
function runPayrollIntelligenceScan(PDO $pdo): array
{
    ensurePlatformMaturitySchema($pdo);
    $found = [
        'missing_hours'       => 0,
        'duplicate_payments'  => 0,
        'unpaid_staff'        => 0,
        'overtime'            => 0,
        'attendance_mismatch' => 0,
    ];

    try {
        $rows = $pdo->query("
            SELECT sr.id AS reg_id, sr.email, sr.event_id, e.name AS event_name,
                   a.checked_in_at,
                   COALESCE((SELECT SUM(hours) FROM work_hours wh WHERE wh.registration_id = sr.id), 0) AS logged_hours
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            LEFT JOIN attendance a ON a.registration_id = sr.id
            WHERE sr.status = 'approved' AND e.event_date < CURDATE()
            LIMIT 500
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $regId   = (int) ($row['reg_id'] ?? 0);
            $hours   = (float) ($row['logged_hours'] ?? 0);
            $checked = !empty($row['checked_in_at']);

            if ($checked && $hours <= 0) {
                insertPayrollAlert(
                    $pdo,
                    'missing_hours',
                    'warn',
                    'Missing work hours after check-in',
                    (string) ($row['email'] ?? '') . ' — ' . (string) ($row['event_name'] ?? ''),
                    $regId
                );
                $found['missing_hours']++;
            }

            if (!$checked && $hours > 0) {
                insertPayrollAlert(
                    $pdo,
                    'attendance_mismatch',
                    'critical',
                    'Hours logged without check-in',
                    (string) ($row['email'] ?? '') . ' — ' . (string) ($row['event_name'] ?? ''),
                    $regId
                );
                $found['attendance_mismatch']++;
            }

            if ($hours > 12) {
                insertPayrollAlert(
                    $pdo,
                    'overtime',
                    'warn',
                    'Overtime threshold exceeded',
                    (string) ($row['email'] ?? '') . ' logged ' . $hours . 'h',
                    $regId
                );
                $found['overtime']++;
            }
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] payroll scan: ' . $e->getMessage());
    }

    try {
        $dupes = $pdo->query("
            SELECT registration_id, COUNT(*) AS cnt
            FROM work_hours
            GROUP BY registration_id, work_date
            HAVING cnt > 1
            LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($dupes as $d) {
            insertPayrollAlert(
                $pdo,
                'duplicate_payments',
                'critical',
                'Duplicate work hour entries',
                'Registration #' . (int) ($d['registration_id'] ?? 0),
                (int) ($d['registration_id'] ?? 0)
            );
            $found['duplicate_payments']++;
        }
    } catch (Throwable $e) {
        // work_hours schema optional
    }

    try {
        $unpaid = $pdo->query("
            SELECT sr.id, sr.email, e.name
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            INNER JOIN attendance a ON a.registration_id = sr.id AND a.checked_in_at IS NOT NULL
            LEFT JOIN work_hours wh ON wh.registration_id = sr.id
            WHERE sr.status = 'approved' AND wh.id IS NULL AND e.event_date < DATE_SUB(CURDATE(), INTERVAL 3 DAY)
            LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($unpaid as $u) {
            insertPayrollAlert(
                $pdo,
                'unpaid_staff',
                'warn',
                'Checked in but no hours recorded',
                (string) ($u['email'] ?? '') . ' — ' . (string) ($u['name'] ?? ''),
                (int) ($u['id'] ?? 0)
            );
            $found['unpaid_staff']++;
        }
    } catch (Throwable $e) {
        // optional
    }

    return $found;
}

/** @return array<string, mixed> */
function getPayrollIntelligenceSummary(PDO $pdo): array
{
    $open = listPayrollAlerts($pdo, 100, false);
    $openOnly = array_filter($open, static fn(array $a): bool => empty($a['resolved_at']));

    $byType = [];
    foreach ($openOnly as $alert) {
        $type = (string) ($alert['alert_type'] ?? 'other');
        $byType[$type] = ($byType[$type] ?? 0) + 1;
    }

    return [
        'open_count'   => count($openOnly),
        'by_type'      => $byType,
        'recent'       => array_slice($openOnly, 0, 15),
        'last_scan_at' => getSetting($pdo, 'payroll_intelligence_last_scan', ''),
    ];
}
