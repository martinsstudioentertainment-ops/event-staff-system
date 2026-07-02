<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/reminders.php';
require_once __DIR__ . '/../includes/event-staff-alerts.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('staff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: staff.php');
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request. Please try again.');
    header('Location: staff.php');
    exit;
}

$pdo        = getDB();
$action     = trim((string) ($_POST['action'] ?? ''));
$redirect   = 'staff.php';
$registrationId = (int) ($_POST['registration_id'] ?? 0);
$eventId    = (int) ($_POST['event_id'] ?? 0);

if (!empty($_POST['redirect'])) {
    $candidate = ltrim((string) $_POST['redirect'], '/');
    if ($candidate !== ''
        && !str_contains($candidate, '://')
        && preg_match('/^(staff\.php|view-staff\.php|attendance\.php|events\.php)(\?.*)?$/', $candidate) === 1) {
        $redirect = $candidate;
    }
}

if ($action === 'registration' && $registrationId > 0) {
    if (sendManualShiftReminderForRegistration($pdo, $registrationId)) {
        logAdminAudit($pdo, 'shift_reminder_manual', 'registration', $registrationId, 'Manual shift reminder sent');
        setAdminFlash('success', 'Shift reminder email sent.');
    } else {
        setAdminFlash('error', 'Could not send reminder (registration not found or event ended).');
    }
} elseif ($action === 'event' && $eventId > 0) {
    $stats = sendManualShiftRemindersForEvent($pdo, $eventId);
    logAdminAudit($pdo, 'shift_reminder_manual', 'event', $eventId, 'Manual event reminders sent=' . $stats['sent']);
    setAdminFlash(
        'success',
        'Shift reminders sent to ' . (int) $stats['sent'] . ' staff member(s). Skipped: ' . (int) $stats['skipped'] . '.'
    );
} elseif ($action === 'open_shift_broadcast' && $eventId > 0) {
    $notified = notifyRegisteredStaffOpenShiftSlot($pdo, $eventId, 'Shift places available');
    logAdminAudit($pdo, 'open_shift_broadcast', 'event', $eventId, 'Notified=' . $notified);
    setAdminFlash('success', 'Open shift alert sent to ' . $notified . ' staff member(s).');
} else {
    setAdminFlash('error', 'Invalid reminder action.');
}

header('Location: ' . $redirect);
exit;
