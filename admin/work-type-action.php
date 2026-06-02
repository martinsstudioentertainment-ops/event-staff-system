<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/work-types-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('events');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: work-types.php');
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: work-types.php');
    exit;
}

$id     = (int) ($_POST['id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));

if ($id < 1 || !in_array($action, ['activate', 'deactivate'], true)) {
    setAdminFlash('error', 'Invalid action.');
    header('Location: work-types.php');
    exit;
}

$pdo = getDB();

if (!getWorkTypeById($pdo, $id)) {
    setAdminFlash('error', 'Work type not found.');
    header('Location: work-types.php');
    exit;
}

setWorkTypeActive($pdo, $id, $action === 'activate');
logAdminAudit($pdo, 'work_type_status', 'work_type', $id, $action === 'activate' ? 'Activated' : 'Deactivated');
setAdminFlash('success', $action === 'activate' ? 'Work type activated.' : 'Work type deactivated.');

header('Location: work-types.php');
exit;
