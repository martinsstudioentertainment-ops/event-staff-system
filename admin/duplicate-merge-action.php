<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/platform/data-integrity.php';

requireAdminCapability('settings');

if (!isAdminSuperUser()) {
    setAdminFlash('error', 'Not authorized.');
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: duplicate-merge.php');
    exit;
}

$pdo    = getDB();
$action = (string) ($_POST['action'] ?? '');

if ($action === 'ignore') {
    $key  = trim((string) ($_POST['dup_key'] ?? ''));
    $type = trim((string) ($_POST['dup_type'] ?? ''));
    if ($key === '' || $type === '') {
        setAdminFlash('error', 'Missing duplicate reference.');
    } else {
        $adminId = (int) ($_SESSION['admin_user_id'] ?? 0);
        dismissDataIntegrityDuplicate($pdo, $key, $type, $adminId > 0 ? $adminId : null, 'Dismissed from merge review UI');
        setAdminFlash('success', 'Duplicate group marked as ignored (no data changed).');
    }
}

header('Location: duplicate-merge.php');
exit;
