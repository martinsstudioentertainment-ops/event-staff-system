<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/attendance-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';

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

if ($id < 1) {
    setAdminFlash('error', 'Registration not found.');
    header('Location: attendance.php' . ($eventId > 0 ? '?event_id=' . $eventId : ''));
    exit;
}

$reg = getStaffRegistrationById($pdo, $id);
if ($reg === null) {
    setAdminFlash('error', 'Registration not found.');
    header('Location: attendance.php' . ($eventId > 0 ? '?event_id=' . $eventId : ''));
    exit;
}

if (!resetCheckinForRegistration($pdo, $id)) {
    setAdminFlash('warning', 'No check-in record to reset for this staff member.');
    header('Location: attendance.php' . ($eventId > 0 ? '?event_id=' . $eventId : ''));
    exit;
}

$name = trim((string) ($reg['first_name'] ?? '') . ' ' . (string) ($reg['surname'] ?? ''));
logAdminAudit($pdo, 'admin_checkin_reset', 'registration', $id, 'Reset check-in for ' . $name);
setAdminFlash('success', 'Check-in reset for ' . $name . '. They can sign in again.');

header('Location: attendance.php' . ($eventId > 0 ? '?event_id=' . $eventId : ''));
exit;
