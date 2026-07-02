<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/google-sheets-schedule.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('events');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: google-sheets-control.php');
    exit;
}

$pdo = getDB();
saveGoogleSheetsSyncScheduleSettings($pdo, $_POST);
logAdminAudit($pdo, 'sheets_settings', 'system', 0, 'Updated Google Sheets sync schedule');

setAdminFlash('success', 'Sync schedule saved.');
header('Location: google-sheets-control.php');
exit;
