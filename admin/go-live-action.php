<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/go-live.php';
require_once __DIR__ . '/../includes/database-backup.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/settings-repository.php';

requireAdminCapability('settings');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: go-live.php');
    exit;
}

$pdo    = getDB();
$action = trim((string) ($_POST['action'] ?? ''));

if ($action === 'schema') {
    $result = runSafeSchemaEnsures($pdo);
    if ($result['success']) {
        logAdminAudit($pdo, 'go_live_schema', 'system', 0, implode(', ', $result['applied']));
        setAdminFlash('success', 'Schema updated: ' . implode(', ', $result['applied']) . '.');
    } else {
        setAdminFlash('error', 'Schema update had errors: ' . implode('; ', $result['errors']));
    }
    header('Location: go-live.php');
    exit;
}

if ($action === 'backup') {
    require_once __DIR__ . '/../includes/weekly-backup.php';
    $result = runWeeklyFullBackup($pdo);
    if ($result['success']) {
        logAdminAudit($pdo, 'database_backup', 'system', 0, 'weekly full backup');
        setAdminFlash('success', $result['message']);
    } else {
        setAdminFlash('error', $result['message']);
    }
    header('Location: go-live.php');
    exit;
}

if ($action === 'purge_demo') {
    $result = purgeDemoCommissionData($pdo);
    logAdminAudit($pdo, 'purge_demo_invoices', 'system', 0, (string) $result['removed_invoices']);
    setAdminFlash('success', $result['message']);
    header('Location: go-live.php');
    exit;
}

if ($action === 'manual_save') {
    $manual = $_POST['manual'] ?? [];
    if (!is_array($manual)) {
        $manual = [];
    }

    $items = getGoLiveManualChecklistItems();
    foreach ($items as $item) {
        $key  = $item['key'];
        $done = !empty($manual[$key]);
        setGoLiveManualCheck($pdo, $key, $done);
    }

    if (isGoLiveFullyReady($pdo)) {
        setSetting($pdo, 'go_live_completed_at', date('c'));
        setAdminFlash('success', 'Checklist saved. All go-live requirements are complete.');
    } else {
        setAdminFlash('success', 'Manual checklist saved.');
    }
    header('Location: go-live.php');
    exit;
}

setAdminFlash('error', 'Unknown action.');
header('Location: go-live.php');
exit;
