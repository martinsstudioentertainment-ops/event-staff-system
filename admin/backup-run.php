<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/weekly-backup.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('backups');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: backups.php');
    exit;
}

$pdo    = getDB();
$result = runWeeklyFullBackup($pdo);

if ($result['success']) {
    logAdminAudit($pdo, 'database_backup', 'system', 0, 'weekly manual');
    setAdminFlash('success', $result['message']);
} else {
    setAdminFlash('error', $result['message']);
}

header('Location: backups.php');
exit;
