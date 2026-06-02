<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/go-live.php';
require_once __DIR__ . '/../includes/database-backup.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/live-events-sync.php';
require_once __DIR__ . '/../includes/database-reset.php';

requireAdminCapability('settings');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: go-live.php');
    exit;
}

$pdo    = getDB();
$action = trim((string) ($_POST['action'] ?? ''));

if ($action === 'sync_roster') {
    try {
        $result = syncLiveEventsFromMasterFile($pdo, false);
        logAdminAudit(
            $pdo,
            'import_live_roster',
            'system',
            0,
            "created {$result['created']}, updated {$result['updated']}"
        );
        if ($result['success']) {
            setAdminFlash(
                'success',
                "Summer roster imported: {$result['updated']} updated, {$result['created']} created "
                . "(location, staff needed, times, {$result['main_security_company']})."
            );
        } else {
            setAdminFlash(
                'warning',
                "Roster partly imported. Errors: " . implode('; ', array_slice($result['errors'], 0, 5))
            );
        }
    } catch (Throwable $e) {
        setAdminFlash('error', 'Roster import failed: ' . $e->getMessage());
    }
    header('Location: go-live.php');
    exit;
}

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

if ($action === 'fix_failures') {
    $result = applyGoLiveAutomatedFixes($pdo, true);
    $detail = implode(', ', array_slice($result['fixed'], 0, 12));
    if (count($result['fixed']) > 12) {
        $detail .= ' …';
    }
    logAdminAudit($pdo, 'go_live_fix_failures', 'system', 0, $detail);

    if ($result['success']) {
        $msg = 'Automated fixes applied.';
        if ($detail !== '') {
            $msg .= ' ' . $detail;
        }
        if (!empty($result['backup_message'])) {
            $msg .= ' Backup: ' . $result['backup_message'];
        }
        setAdminFlash('success', $msg);
    } else {
        $msg = 'Some fixes failed: ' . implode('; ', $result['errors']);
        if ($detail !== '') {
            $msg .= ' Partial: ' . $detail;
        }
        setAdminFlash('error', $msg);
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

if ($action === 'reset_database') {
    $phrase = trim((string) ($_POST['confirm_phrase'] ?? ''));
    if ($phrase !== 'RESET') {
        setAdminFlash('error', 'Type RESET in the confirmation box to wipe the database.');
        header('Location: go-live.php');
        exit;
    }

    $keepSettings = !empty($_POST['keep_settings']);
    $result       = resetDatabaseToZero($pdo, [
        'keep_settings' => $keepSettings,
        'site_name'     => $keepSettings ? '' : 'Olasentra',
    ]);

    logAdminAudit(
        $pdo,
        'reset_database_zero',
        'system',
        0,
        $keepSettings ? 'full reset, settings kept' : 'full reset'
    );

    if ($result['success']) {
        setAdminFlash('success', 'Database reset complete. ' . implode(' ', array_slice($result['messages'], -2)));
    } else {
        setAdminFlash(
            'error',
            'Database reset finished with errors: ' . implode('; ', array_slice($result['errors'], 0, 3))
        );
    }
    header('Location: go-live.php');
    exit;
}

if ($action === 'clear_staff_data') {
    $phrase = trim((string) ($_POST['confirm_phrase'] ?? ''));
    if ($phrase !== 'CLEAR') {
        setAdminFlash('error', 'Type CLEAR in the confirmation box to remove all staff data.');
        header('Location: go-live.php');
        exit;
    }

    $result = clearStaffAndOperationalData($pdo);
    logAdminAudit($pdo, 'clear_staff_data', 'system', 0, 'staff and operational tables truncated');

    if ($result['success']) {
        setAdminFlash('success', 'Staff and operational data cleared. Events and settings kept.');
    } else {
        setAdminFlash('error', 'Clear failed: ' . implode('; ', $result['errors']));
    }
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
