<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/maps.php';
require_once __DIR__ . '/../includes/audit-log.php';

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
        if (!getEventById($pdo, $id)) {
            setAdminFlash('error', 'Event not found.');
            header('Location: events.php');
            exit;
        }
        updateEvent($pdo, $id, $post);
        logAdminAudit($pdo, 'event_save', 'event', $id, 'Updated');
        setAdminFlash('success', 'Event updated successfully.');
    } else {
        $newId = createEvent($pdo, $post);
        logAdminAudit($pdo, 'event_save', 'event', $newId, 'Created');
        setAdminFlash('success', 'Event created successfully.');
    }
} catch (PDOException $e) {
    setAdminFlash('error', 'Unable to save event. Please try again.');
}

header('Location: events.php');
exit;
