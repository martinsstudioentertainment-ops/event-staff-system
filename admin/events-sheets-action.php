<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/google-sheets-sync.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('events');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: events.php');
    exit;
}

$action = trim((string) ($_POST['action'] ?? ''));
$pdo    = getDB();

if ($action === 'create_one') {
    $eventId = (int) ($_POST['event_id'] ?? 0);
    if ($eventId < 1) {
        setAdminFlash('error', 'Invalid event.');
        header('Location: events.php');
        exit;
    }

    $result = createGoogleSheetForEvent($pdo, $eventId);
    if ($result['ok']) {
        logAdminAudit($pdo, 'event_sheet_create', 'event', $eventId, 'Google Sheet auto-created');
        setAdminFlash('success', $result['message'] . ' Open it from Events → Edit event.');
    } else {
        setAdminFlash('error', $result['message']);
    }

    header('Location: event-form.php?id=' . $eventId);
    exit;
}

if ($action === 'sync_registrations') {
    @set_time_limit(600);

    if (!isGoogleSheetsSyncEnabled($pdo)) {
        setAdminFlash('error', 'Enable live sync in Settings → Google Sheets first.');
        header('Location: events.php');
        exit;
    }

    $stats = syncAllRegistrationsToLinkedGoogleSheets($pdo);
    logAdminAudit(
        $pdo,
        'bulk_sheet_sync',
        'system',
        null,
        "synced {$stats['synced']}, skipped {$stats['skipped']}, failed {$stats['failed']}"
    );

    if ($stats['synced'] > 0 && $stats['failed'] === 0) {
        setAdminFlash('success', "Synced {$stats['synced']} registration row(s) to linked Google Sheets.");
    } elseif ($stats['synced'] > 0) {
        setAdminFlash(
            'warning',
            "Synced {$stats['synced']} row(s); {$stats['failed']} failed. Check storage/logs/google-sheets.log"
        );
    } elseif ($stats['skipped'] > 0 && $stats['failed'] === 0) {
        setAdminFlash('error', 'No registrations synced — link sheets to events and enable Google auth in Settings.');
    } else {
        setAdminFlash('error', 'Sheet sync failed. Check storage/logs/google-sheets.log');
    }

    header('Location: events.php');
    exit;
}

if ($action !== 'create_all') {
    setAdminFlash('error', 'Unknown action.');
    header('Location: events.php');
    exit;
}

@set_time_limit(600);

$stats = bulkCreateGoogleSheetsForEvents($pdo, true);

logAdminAudit(
    $pdo,
    'bulk_event_sheets_create',
    'system',
    null,
    "created {$stats['created']}, skipped {$stats['skipped']}, failed {$stats['failed']}"
);

if ($stats['created'] > 0 && $stats['failed'] === 0) {
    setAdminFlash(
        'success',
        "Created and linked {$stats['created']} Google Sheet(s). "
        . 'Enable live sync in Settings, then use Sync registrations to sheets on Events.'
    );
} elseif ($stats['created'] > 0) {
    $hint = implode('; ', array_slice($stats['errors'], 0, 3));
    setAdminFlash(
        'warning',
        "Created {$stats['created']} sheet(s); {$stats['failed']} failed. {$hint}"
    );
} elseif ($stats['skipped'] > 0 && $stats['failed'] === 0) {
    setAdminFlash('success', 'Every event already has a Google Sheet linked.');
} else {
    $hint = $stats['errors'] !== [] ? implode('; ', array_slice($stats['errors'], 0, 3)) : 'Check storage/logs/google-sheets.log';
    setAdminFlash('error', 'No sheets were created. ' . $hint);
}

header('Location: events.php');
exit;
