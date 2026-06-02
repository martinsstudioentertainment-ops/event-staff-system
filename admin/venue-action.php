<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/venues-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('events');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: venues.php');
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: venues.php');
    exit;
}

$id     = (int) ($_POST['id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));

if ($id < 1 || !in_array($action, ['activate', 'deactivate'], true)) {
    setAdminFlash('error', 'Invalid venue action.');
    header('Location: venues.php');
    exit;
}

$pdo = getDB();

if (!getVenueById($pdo, $id)) {
    setAdminFlash('error', 'Venue not found.');
    header('Location: venues.php');
    exit;
}

setVenueActive($pdo, $id, $action === 'activate');
logAdminAudit($pdo, 'venue_status', 'venue', $id, $action === 'activate' ? 'Activated' : 'Deactivated');
setAdminFlash('success', $action === 'activate' ? 'Venue activated.' : 'Venue deactivated.');

header('Location: venues.php');
exit;
