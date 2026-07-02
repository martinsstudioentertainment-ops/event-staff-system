<?php

declare(strict_types=1);

require_once __DIR__ . '/../staff-repository.php';
require_once __DIR__ . '/../events-repository.php';
require_once __DIR__ . '/../notification-center.php';
require_once __DIR__ . '/../staff-messages.php';
require_once __DIR__ . '/../feature-flags.php';
require_once __DIR__ . '/../attendance-gps-phase1.php';
require_once __DIR__ . '/payroll-intelligence.php';
require_once __DIR__ . '/google-sheets-control.php';

/** @return array<string, mixed> */
function getCommandCenterSnapshot(PDO $pdo): array
{
    $stats = getDashboardStats($pdo);

    $checkinsToday = 0;
    $attendanceIssues = 0;
    try {
        $checkinsToday = (int) $pdo->query(
            "SELECT COUNT(*) FROM attendance WHERE DATE(checked_in_at) = CURDATE()"
        )->fetchColumn();
        $attendanceIssues = (int) $pdo->query(
            "SELECT COUNT(*) FROM attendance
             WHERE attendance_status IN ('no_show','late','gps_failed','manual_review')"
        )->fetchColumn();
    } catch (Throwable $e) {
        // columns may vary on older DB
    }

    $hibernated = 0;
    try {
        $hibernated = (int) $pdo->query(
            "SELECT COUNT(*) FROM attendance WHERE attendance_status = 'pre_checked_in'"
        )->fetchColumn();
    } catch (Throwable $e) {
        $hibernated = 0;
    }

    $activeEvents = 0;
    try {
        $activeEvents = (int) $pdo->query(
            "SELECT COUNT(*) FROM events WHERE is_active = 1 AND event_date >= CURDATE()"
        )->fetchColumn();
    } catch (Throwable $e) {
        $activeEvents = (int) ($stats['active_events'] ?? 0);
    }

    $staffOnline = 0;
    try {
        $staffOnline = (int) $pdo->query(
            "SELECT COUNT(DISTINCT staff_email) FROM attendance
             WHERE checked_in_at >= DATE_SUB(NOW(), INTERVAL 4 HOUR)"
        )->fetchColumn();
    } catch (Throwable $e) {
        $staffOnline = 0;
    }

    $payrollAlerts = countPayrollAlerts($pdo, true);
    $sheetsHealth  = summarizeGoogleSheetsControl($pdo);

    return [
        'pending_registrations' => (int) ($stats['pending'] ?? 0),
        'approved_today'        => (int) ($stats['approved_today'] ?? 0),
        'staff_online'          => $staffOnline,
        'active_events'         => $activeEvents,
        'checkins_today'        => $checkinsToday,
        'attendance_issues'     => $attendanceIssues,
        'gps_hibernated'        => $hibernated,
        'gps_flag_on'           => isGpsAttendanceV2Enabled($pdo),
        'notifications_unread'  => countUnreadAdminNotifications($pdo),
        'messages_unread'       => countUnreadStaffMessagesForAdmin($pdo),
        'payroll_alerts'        => $payrollAlerts,
        'sheets_failed_24h'     => (int) ($sheetsHealth['failed_24h'] ?? 0),
        'sheets_connected'      => (int) ($sheetsHealth['connected_events'] ?? 0),
        'upcoming_events'       => getUpcomingEventsSummary($pdo, 8),
        'recent_pending'        => getRecentPendingRegistrations($pdo, 10),
    ];
}
