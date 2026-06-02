<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';

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
    setAdminFlash('success', $active ? 'Event activated.' : 'Event deactivated.');
} else {
    setAdminFlash('error', 'Event not found.');
}

header('Location: events.php');
exit;
