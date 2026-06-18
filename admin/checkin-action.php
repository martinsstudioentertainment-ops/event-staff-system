<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/attendance-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/platform/trust-scores.php';

requireAdminCapability('attendance');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: attendance.php');
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: attendance.php');
    exit;
}

$id      = (int) ($_POST['id'] ?? 0);
$eventId = (int) ($_POST['event_id'] ?? 0);
$pdo     = getDB();

$result  = recordCheckin($pdo, $id, 'admin');

if ($result === true) {
    logAdminAudit(getDB(), 'admin_checkin', 'registration', $id, 'Manual check-in');
    $regRow = getStaffRegistrationById($pdo, $id);
    $trustStaffId = (int) ($regRow['staff_id'] ?? 0);
    if ($trustStaffId < 1 && $regRow !== null) {
        $trustStaffId = (int) (ensureStaffRecordForEmail($pdo, (string) ($regRow['email'] ?? '')) ?? 0);
    }
    if ($trustStaffId > 0) {
        try {
            refreshStaffTrustScoreOnEvent($pdo, $trustStaffId);
        } catch (Throwable $trustErr) {
            error_log('[EventStaff] trust score refresh on check-in: ' . $trustErr->getMessage());
        }
    }
    setAdminFlash('success', 'Staff checked in successfully.');
} elseif ($result === 'Already checked in.') {
    setAdminFlash('warning', 'Staff member is already checked in.');
} else {
    setAdminFlash('error', (string) $result);
}

$redirect = 'attendance.php' . ($eventId > 0 ? '?event_id=' . $eventId : '');
header('Location: ' . $redirect);
exit;
