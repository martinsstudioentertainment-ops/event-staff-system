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
$registrationId = (int) ($_POST['registration_id'] ?? 0);
$sentHome       = !empty($_POST['sent_home']);
$hoursOverride  = !empty($_POST['hours_override']);
$redirectTo     = trim((string) ($_POST['redirect'] ?? ''));

if ($hoursOverride) {
    $result = correctAdminShiftHours($pdo, $attendanceId, $hoursPaid, $note, (int) $adminUser['id']);
} elseif ($sentHome) {
    $result = recordStaffSentHome($pdo, $attendanceId, $hoursPaid, $note, (int) $adminUser['id']);
} else {
    $result = updateWorkHours($pdo, $attendanceId, $hoursPaid, $note, (int) $adminUser['id']);
}

if ($result === true) {
    $auditAction = $hoursOverride ? 'shift_hours_corrected' : ($sentHome ? 'sent_home_recorded' : 'work_hours_update');
    $auditDetail = ($hoursOverride ? 'Shift hours corrected — ' : ($sentHome ? 'Sent home — ' : 'Payable hours set to '))
        . $hoursPaid . 'h' . ($note !== '' ? ' — ' . $note : '');
    logAdminAudit($pdo, $auditAction, 'attendance', $attendanceId, $auditDetail);
    $successMsg = $hoursOverride
        ? 'Shift hours saved.'
        : ($sentHome ? 'Sent home recorded on staff profile.' : 'Work hours updated.');
    setAdminFlash('success', $successMsg);
} else {
    setAdminFlash('error', (string) $result);
}

if ($redirectTo !== '' && str_starts_with($redirectTo, 'view-staff.php')) {
    header('Location: ' . $redirectTo);
    exit;
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
