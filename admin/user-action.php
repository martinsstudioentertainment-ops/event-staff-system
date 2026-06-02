<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-users-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('users');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: users.php');
    exit;
}

$pdo       = getDB();
$adminUser = getAdminUser();
$action    = trim((string) ($_POST['action'] ?? ''));
$userId    = (int) ($_POST['user_id'] ?? 0);

if ($action === 'create') {
    $result = createAdminUser($pdo, $_POST);
    if ($result === true) {
        logAdminAudit($pdo, 'user_create', 'admin_user', 0, (string) ($_POST['username'] ?? ''));
        setAdminFlash('success', 'User created successfully.');
    } else {
        setAdminFlash('error', (string) $result);
    }
    header('Location: users.php');
    exit;
}

if ($action === 'update' && $userId > 0) {
    $result = updateAdminUser($pdo, $userId, $_POST, (int) $adminUser['id']);
    if ($result === true) {
        logAdminAudit($pdo, 'user_update', 'admin_user', $userId, (string) ($_POST['username'] ?? ''));
        setAdminFlash('success', 'User updated successfully.');
    } else {
        setAdminFlash('error', (string) $result);
    }
    header('Location: users.php?edit=' . $userId);
    exit;
}

if ($action === 'deactivate' && $userId > 0) {
    $result = deactivateAdminUser($pdo, $userId, (int) $adminUser['id']);
    if ($result === true) {
        logAdminAudit($pdo, 'user_deactivate', 'admin_user', $userId, '');
        setAdminFlash('success', 'User deactivated.');
    } else {
        setAdminFlash('error', (string) $result);
    }
    header('Location: users.php');
    exit;
}

setAdminFlash('error', 'Unknown action.');
header('Location: users.php');
exit;
