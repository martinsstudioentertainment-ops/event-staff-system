<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/event-cancel-registrations.php';

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

$eventId  = (int) ($_POST['event_id'] ?? 0);
$reason   = trim((string) ($_POST['reason'] ?? ''));
$redirect = trim((string) ($_POST['redirect'] ?? 'events.php'));
if ($redirect === '' || str_contains($redirect, '://') || str_starts_with($redirect, '//')) {
    $redirect = 'events.php';
}
if (!preg_match('/^(events\.php|event-form\.php)(\?.*)?$/', ltrim($redirect, '/')) === 1) {
    $redirect = 'events.php';
}

if ($eventId < 1) {
    setAdminFlash('error', 'Invalid event.');
    header('Location: ' . $redirect);
    exit;
}

$pdo    = getDB();
$result = adminCancelAllEventRegistrations($pdo, $eventId, $reason);

if (!($result['ok'] ?? false)) {
    setAdminFlash('error', (string) ($result['error'] ?? 'Could not cancel event shifts.'));
    header('Location: ' . $redirect);
    exit;
}

$updated     = (int) ($result['updated'] ?? 0);
$eventName   = (string) ($result['event_name'] ?? 'Event');
$deactivated = !empty($result['deactivated']);
$notifyIds   = $result['notify_ids'] ?? [];
if (!is_array($notifyIds)) {
    $notifyIds = [];
}

$message = sprintf(
    '%s: %d registration(s) cancelled (rejected).',
    $eventName,
    $updated
);
if ($deactivated) {
    $message .= ' Event deactivated — hidden from registration.';
}
if ($updated > 0) {
    $message .= ' Staff are notified by email and in-app alert in the background.';
} else {
    $message .= ' No approved or pending registrations were on this event.';
}

setAdminFlash('success', $message);

if ($updated > 0 && $notifyIds !== []) {
    flushHttpResponse($redirect);
    runEventCancellationPostJobs($pdo, $notifyIds, $reason);
    exit;
}

header('Location: ' . $redirect);
exit;
