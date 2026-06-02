<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/work-hours-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('attendance');

if (!in_array(getAdminRole(), ['admin', 'manager'], true)) {
    setAdminFlash('error', 'Only administrators and managers can adjust work hours.');
    header('Location: work-hours.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: work-hours.php');
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: work-hours.php');
    exit;
}

$pdo          = getDB();
$adminUser    = getAdminUser();
$attendanceId = (int) ($_POST['attendance_id'] ?? 0);
$hoursPaid    = (float) ($_POST['hours_paid'] ?? 0);
$note         = trim((string) ($_POST['hours_note'] ?? ''));
$eventId      = (int) ($_POST['event_id'] ?? 0);
$workDate     = trim((string) ($_POST['work_date'] ?? ''));

$result = updateWorkHours($pdo, $attendanceId, $hoursPaid, $note, (int) $adminUser['id']);

if ($result === true) {
    logAdminAudit($pdo, 'work_hours_update', 'attendance', $attendanceId, 'Payable hours set to ' . $hoursPaid . ($note !== '' ? ' — ' . $note : ''));
    setAdminFlash('success', 'Work hours updated.');
} else {
    setAdminFlash('error', (string) $result);
}

$redirect = 'work-hours.php';
$query    = [];
if ($eventId > 0) {
    $query['event_id'] = $eventId;
}
if ($workDate !== '') {
    $query['work_date'] = $workDate;
}
if ($query !== []) {
    $redirect .= '?' . http_build_query($query);
}

header('Location: ' . $redirect);
exit;
