<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/event-staff-alerts.php';

requireAdminCapability('events');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: events.php');
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request. Please try again.');
    header('Location: events.php');
    exit;
}

$id     = (int) ($_POST['id'] ?? 0);
$active = ((int) ($_POST['active'] ?? 0)) === 1;

if ($id <= 0) {
    setAdminFlash('error', 'Invalid event.');
    header('Location: events.php');
    exit;
}

$pdo = getDB();
if (setEventActive($pdo, $id, $active)) {
    if ($active) {
        $notified = maybeNotifyStaffAfterEventSave($pdo, $id, ['is_active' => 0, 'staff_needed' => 0]);
        $msg = 'Event activated.';
        if ($notified > 0) {
            $msg .= ' ' . $notified . ' staff (not already on this shift) notified by email and in-app alert.';
        } elseif (!isNotifyStaffShiftAlertsEnabled($pdo)) {
            $msg .= ' Shift alerts are off in Settings → Email.';
        } elseif (!eventHasOpenStaffSlots($pdo, $id)) {
            $msg .= ' Shift is full — no alert sent.';
        } else {
            $msg .= ' No eligible staff to notify (everyone may already be on this shift).';
        }
        setAdminFlash('success', $msg);
    } else {
        setAdminFlash('success', 'Event deactivated.');
    }
} else {
    setAdminFlash('error', 'Event not found.');
}

header('Location: events.php');
exit;
