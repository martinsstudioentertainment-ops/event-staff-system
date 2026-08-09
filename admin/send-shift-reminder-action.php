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

@set_time_limit(600);

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

try {
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
        if ((int) $stats['sent'] === 0 && (int) $stats['skipped'] > 0) {
            $detail = '';
            if ((int) ($stats['skipped_ended'] ?? 0) > 0) {
                $detail = (int) $stats['skipped_ended'] . ' skipped because the event check-in window has ended.';
            } elseif ((int) ($stats['skipped_email'] ?? 0) > 0) {
                $detail = (int) $stats['skipped_email'] . ' could not be emailed — check SMTP in Settings → Email.';
                if (!empty($stats['errors'][0])) {
                    $detail .= ' (' . (string) $stats['errors'][0] . ')';
                }
            } elseif ((int) ($stats['skipped_no_email'] ?? 0) > 0) {
                $detail = (int) $stats['skipped_no_email'] . ' skipped because email address is missing.';
            } else {
                $detail = (int) $stats['skipped'] . ' staff were skipped — the event may have ended, or email delivery failed.';
            }
            setAdminFlash('error', 'No shift reminders were sent. ' . $detail);
        } else {
            setAdminFlash(
                'success',
                'Shift reminders sent to ' . (int) $stats['sent'] . ' staff member(s). Skipped: ' . (int) $stats['skipped'] . '.'
            );
        }
    } elseif ($action === 'open_shift_broadcast' && $eventId > 0) {
        $notified = notifyRegisteredStaffOpenShiftSlot($pdo, $eventId, 'Shift places available');
        logAdminAudit($pdo, 'open_shift_broadcast', 'event', $eventId, 'Notified=' . $notified);
        setAdminFlash('success', 'Open shift alert sent to ' . $notified . ' staff member(s).');
    } else {
        setAdminFlash('error', 'Invalid reminder action.');
    }
} catch (Throwable $e) {
    error_log('[EventStaff] send-shift-reminder-action: ' . $e->getMessage());
    setAdminFlash('error', 'Could not send shift reminders. Please try again in a moment.');
}

header('Location: ' . $redirect);
exit;
