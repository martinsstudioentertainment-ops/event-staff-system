<?php

declare(strict_types=1);

require_once __DIR__ . '/../events-repository.php';
require_once __DIR__ . '/../staff-repository.php';
require_once __DIR__ . '/../attendance-repository.php';
require_once __DIR__ . '/../attendance-gps-phase1.php';
require_once __DIR__ . '/../feature-flags.php';
require_once __DIR__ . '/payroll-intelligence.php';
require_once __DIR__ . '/google-sheets-control.php';

/** @return array<string, mixed>|null */
function getEventHubSnapshot(PDO $pdo, int $eventId): ?array
{
    if ($eventId < 1) {
        return null;
    }

    $event = getEventById($pdo, $eventId);
    if ($event === null) {
        return null;
    }

    $assigned   = getApprovedStaffForEvent($pdo, $eventId);
    foreach ($assigned as &$staffRow) {
        $att = getAttendanceByRegistration($pdo, (int) ($staffRow['id'] ?? 0));
        $staffRow['checked_in_at']      = $att['checked_in_at'] ?? null;
        $staffRow['attendance_status']  = (string) ($att['attendance_status'] ?? '');
    }
    unset($staffRow);
    $pending    = 0;
    $checkins   = 0;
    $gpsIssues  = 0;
    $workHours  = 0.0;

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM staff_registrations WHERE event_id = :eid AND status = 'pending'");
        $stmt->execute(['eid' => $eventId]);
        $pending = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM attendance a
             INNER JOIN staff_registrations sr ON sr.id = a.registration_id
             WHERE sr.event_id = :eid AND a.checked_in_at IS NOT NULL"
        );
        $stmt->execute(['eid' => $eventId]);
        $checkins = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM attendance a
             INNER JOIN staff_registrations sr ON sr.id = a.registration_id
             WHERE sr.event_id = :eid AND a.attendance_status IN ('gps_failed','manual_review','no_show')"
        );
        $stmt->execute(['eid' => $eventId]);
        $gpsIssues = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(hours), 0) FROM work_hours wh
             INNER JOIN staff_registrations sr ON sr.id = wh.registration_id
             WHERE sr.event_id = :eid"
        );
        $stmt->execute(['eid' => $eventId]);
        $workHours = (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        // optional columns
    }

    $sheetUrl = trim((string) ($event['google_sheet_url'] ?? ''));
    $sheetLog = getSheetsSyncLogForEvent($pdo, $eventId, 5);

    return [
        'event'           => $event,
        'assigned_count'  => count($assigned),
        'assigned_staff'  => array_slice($assigned, 0, 20),
        'pending_count'   => $pending,
        'checkins'        => $checkins,
        'gps_issues'      => $gpsIssues,
        'gps_flag_on'     => isGpsAttendanceV2Enabled($pdo),
        'work_hours'      => $workHours,
        'payroll_alerts'  => listPayrollAlertsForEvent($pdo, $eventId),
        'sheet_linked'    => $sheetUrl !== '',
        'sheet_url'       => $sheetUrl,
        'sheet_sync_log'  => $sheetLog,
        'staff_needed'    => (int) ($event['staff_needed'] ?? 0),
        'coverage_gap'    => max(0, (int) ($event['staff_needed'] ?? 0) - count($assigned)),
    ];
}

/** @return array<int, array<string, mixed>> */
function listEventsForHubPicker(PDO $pdo, int $limit = 30): array
{
    $limit = max(1, min($limit, 100));

    try {
        $stmt = $pdo->query("
            SELECT e.id, e.name, e.event_date, e.is_active,
                   (SELECT COUNT(*) FROM staff_registrations sr WHERE sr.event_id = e.id AND sr.status = 'approved') AS approved_count,
                   (SELECT COUNT(*) FROM staff_registrations sr WHERE sr.event_id = e.id AND sr.status = 'pending') AS pending_count
            FROM events e
            WHERE e.is_active = 1
            ORDER BY e.event_date ASC
            LIMIT {$limit}
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
