<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/maps.php';
require_once __DIR__ . '/../includes/audit-log.php';
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
$isEdit = $id > 0;
$pdo    = getDB();
$post   = $_POST;

if (trim((string) ($post['venue_lat'] ?? '')) === '' || trim((string) ($post['venue_lng'] ?? '')) === '') {
    $eircode = normalizeEircode((string) ($post['venue_eircode'] ?? ''));
    $coords  = $eircode !== '' ? geocodeVenueEircode($eircode, $pdo) : null;
    if ($coords !== null) {
        $post['venue_lat'] = (string) $coords['lat'];
        $post['venue_lng'] = (string) $coords['lng'];
    }
}

$errors = validateEventData($post, $isEdit);

if ($errors !== []) {
    $_SESSION['event_form_errors'] = array_values($errors);
    $_SESSION['event_form_old']    = $post;
    header('Location: event-form.php' . ($isEdit ? '?id=' . $id : ''));
    exit;
}

$pdo = getDB();

try {
    if ($isEdit) {
        $before = getEventById($pdo, $id);
        if (!$before) {
            setAdminFlash('error', 'Event not found.');
            header('Location: events.php');
            exit;
        }
        updateEvent($pdo, $id, $post);
        logAdminAudit($pdo, 'event_save', 'event', $id, 'Updated');
        $notified = maybeNotifyStaffAfterEventSave($pdo, $id, $before);
        $msg      = 'Event updated successfully.';
        if ($notified > 0) {
            $msg .= ' ' . $notified . ' staff (not already on this shift) notified by email and in-app alert.';
        } elseif (isNotifyStaffShiftAlertsEnabled($pdo)) {
            $after = getEventById($pdo, $id);
            $wasActive = (int) ($before['is_active'] ?? 0) === 1;
            $nowActive = $after && (int) ($after['is_active'] ?? 0) === 1;
            $oldNeeded = (int) ($before['staff_needed'] ?? 0);
            $newNeeded = $after ? (int) ($after['staff_needed'] ?? 0) : 0;
            if ($nowActive && (!$wasActive || $newNeeded > $oldNeeded) && !eventHasOpenStaffSlots($pdo, $id)) {
                $msg .= ' Shift is full — no open-shift alert sent.';
            }
        }
        setAdminFlash('success', $msg);
    } else {
        $newId = createEvent($pdo, $post);
        logAdminAudit($pdo, 'event_save', 'event', $newId, 'Created');
        $notified = !empty($post['is_active']) ? maybeNotifyStaffAfterEventSave($pdo, $newId, null) : 0;
        $msg      = 'Event created successfully.';
        if ($notified > 0) {
            $msg .= ' ' . $notified . ' staff (not already on this shift) notified by email and in-app alert.';
        }
        setAdminFlash('success', $msg);
    }
} catch (PDOException $e) {
    setAdminFlash('error', 'Unable to save event. Please try again.');
}

header('Location: events.php');
exit;
