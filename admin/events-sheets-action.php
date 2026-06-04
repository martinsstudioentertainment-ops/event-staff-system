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

if ($action === 'unlink_one') {
    $eventId  = (int) ($_POST['event_id'] ?? 0);
    $redirect = trim((string) ($_POST['redirect'] ?? 'events.php'));
    if ($redirect === '' || str_contains($redirect, '://') || !preg_match('/^(events\.php|event-form\.php\?id=\d+)$/', $redirect)) {
        $redirect = 'events.php';
    }

    if ($eventId < 1) {
        setAdminFlash('error', 'Invalid event.');
        header('Location: ' . $redirect);
        exit;
    }

    if (unlinkEventGoogleSheet($pdo, $eventId)) {
        logAdminAudit($pdo, 'event_sheet_unlink', 'event', $eventId, 'Google Sheet unlinked in admin');
        setAdminFlash(
            'success',
            'Google Sheet unlinked for this event. The spreadsheet file in Drive is not deleted — use Link sheets from Drive folder to connect again.'
        );
    } else {
        setAdminFlash('error', 'Could not unlink — event not found or already unlinked.');
    }

    header('Location: ' . $redirect);
    exit;
}

if ($action === 'unlink_all') {
    $unlinked = unlinkAllEventGoogleSheets($pdo);
    logAdminAudit($pdo, 'bulk_sheet_unlink', 'system', null, "unlinked all ({$unlinked})");
    setAdminFlash(
        'success',
        $unlinked > 0
            ? "Unlinked {$unlinked} event(s) from Google Sheets. Files in Drive are unchanged — use Link sheets or pick a sheet below to connect again."
            : 'No linked events to unlink.'
    );
    header('Location: events.php');
    exit;
}

if ($action === 'unlink_selected') {
    $eventIds = normalizeBulkEventIds($_POST['event_ids'] ?? []);
    if ($eventIds === []) {
        setAdminFlash('error', 'Select at least one event to unlink.');
        header('Location: events.php');
        exit;
    }

    $unlinked = unlinkEventGoogleSheetsByIds($pdo, $eventIds);
    logAdminAudit($pdo, 'bulk_sheet_unlink', 'system', null, "unlinked {$unlinked} selected");
    setAdminFlash(
        'success',
        "Unlinked {$unlinked} selected event(s). Spreadsheet files in Drive were not deleted."
    );
    header('Location: events.php');
    exit;
}

if ($action === 'link_selected') {
    @set_time_limit(120);

    $eventIds = normalizeBulkEventIds($_POST['event_ids'] ?? []);
    if ($eventIds === []) {
        setAdminFlash('error', 'Select at least one event to link.');
        header('Location: events.php');
        exit;
    }

    $stats = linkExistingGoogleSheetsFromDriveFolder($pdo, $eventIds);
    logAdminAudit(
        $pdo,
        'bulk_sheet_link',
        'system',
        null,
        "selected link: {$stats['linked']}, skipped {$stats['skipped']}, unmatched {$stats['unmatched']}"
    );

    if ($stats['linked'] > 0) {
        $msg = "Linked {$stats['linked']} selected event(s) by matching file names in your Drive folder.";
        if ($stats['skipped'] > 0) {
            $msg .= " {$stats['skipped']} already had a sheet URL.";
        }
        if ($stats['unmatched'] > 0) {
            $msg .= " {$stats['unmatched']} had no matching file — use the Pick Google Sheet dropdown on those rows.";
        }
        setAdminFlash('success', $msg);
    } elseif ($stats['errors'] !== []) {
        setAdminFlash('error', implode(' ', $stats['errors']));
    } else {
        setAdminFlash(
            'warning',
            'No selected events were linked. Use the Pick Google Sheet dropdown, or name files: date — event name — Staff.'
        );
    }

    header('Location: events.php');
    exit;
}

if ($action === 'link_pick') {
    $eventId        = (int) ($_POST['event_id'] ?? 0);
    $spreadsheetId  = trim((string) ($_POST['spreadsheet_id'] ?? ''));

    if ($eventId < 1 || $spreadsheetId === '') {
        setAdminFlash('error', 'Choose an event and a Google Sheet from the list.');
        header('Location: events.php');
        exit;
    }

    if (linkEventToGoogleSpreadsheetById($pdo, $eventId, $spreadsheetId)) {
        logAdminAudit($pdo, 'event_sheet_link', 'event', $eventId, 'Linked via sheet picker');
        setAdminFlash('success', 'Google Sheet linked for this event.');
    } else {
        setAdminFlash('error', 'Could not link — check the sheet is in your shared Drive folder and Settings are configured.');
    }

    header('Location: events.php');
    exit;
}

if ($action === 'link_from_folder') {
    @set_time_limit(120);

    $stats = linkExistingGoogleSheetsFromDriveFolder($pdo);
    logAdminAudit(
        $pdo,
        'bulk_sheet_link',
        'system',
        null,
        "linked {$stats['linked']}, unmatched {$stats['unmatched']}"
    );

    if ($stats['linked'] > 0) {
        $msg = "Linked {$stats['linked']} event(s) to spreadsheets in your Drive folder.";
        if ($stats['unmatched'] > 0) {
            $msg .= " {$stats['unmatched']} event(s) had no matching file name.";
        }
        setAdminFlash('success', $msg);
    } elseif ($stats['errors'] !== []) {
        setAdminFlash('error', implode(' ', $stats['errors']));
    } else {
        setAdminFlash(
            'warning',
            'No events were linked. Name each file: date — event name — Staff (e.g. 10/06/2026 — Nick Cave — Staff). See google-sheets.log.'
        );
    }

    header('Location: events.php');
    exit;
}

if ($action === 'sync_registrations_cancel') {
    unset($_SESSION['bulk_sheet_sync']);
    setAdminFlash('warning', 'Google Sheets sync cancelled.');
    header('Location: events.php');
    exit;
}

if ($action === 'sync_registrations' || $action === 'sync_registrations_continue') {
    @ini_set('memory_limit', '512M');
    @set_time_limit(120);

    if (!isGoogleSheetsSyncEnabled($pdo)) {
        unset($_SESSION['bulk_sheet_sync']);
        setAdminFlash('error', 'Enable live sync in Settings → Google Sheets first.');
        header('Location: events.php');
        exit;
    }

    if ($action === 'sync_registrations') {
        $ids = getLinkedRegistrationIdsForSheetSync($pdo);
        if ($ids === []) {
            setAdminFlash('error', 'No registrations to sync — link Google Sheets to events first.');
            header('Location: events.php');
            exit;
        }

        $_SESSION['bulk_sheet_sync'] = [
            'ids'   => $ids,
            'pos'   => 0,
            'stats' => ['synced' => 0, 'removed' => 0, 'skipped' => 0, 'failed' => 0],
        ];
    }

    $state = $_SESSION['bulk_sheet_sync'] ?? null;
    if (!is_array($state) || !isset($state['ids'], $state['pos'], $state['stats'])) {
        setAdminFlash('error', 'Sheet sync session expired. Click Sync registrations to sheets again.');
        header('Location: events.php');
        exit;
    }

    $allIds    = array_values(array_map('intval', $state['ids']));
    $pos       = max(0, (int) $state['pos']);
    $total     = count($allIds);
    $batchSize = googleSheetsBulkSyncBatchSize();
    $chunk     = array_slice($allIds, $pos, $batchSize);

    if ($chunk !== []) {
        $batchStats = syncRegistrationsToGoogleSheets($pdo, $chunk);
        $state['stats'] = mergeGoogleSheetsSyncStats($state['stats'], $batchStats);
        $state['pos']   = $pos + count($chunk);
        $_SESSION['bulk_sheet_sync'] = $state;
    }

    $done  = (int) $state['pos'];
    $stats = $state['stats'];

    if ($done < $total) {
        $pct = $total > 0 ? (int) round(($done / $total) * 100) : 0;
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Syncing Google Sheets…</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .box { background: #1e293b; padding: 2rem; border-radius: 12px; max-width: 420px; width: 90%; text-align: center; }
        .bar { height: 8px; background: #334155; border-radius: 4px; overflow: hidden; margin: 1rem 0; }
        .bar span { display: block; height: 100%; background: #2563eb; width: <?= (int) $pct ?>%; transition: width 0.3s; }
        a { color: #93c5fd; }
        .muted { color: #94a3b8; font-size: 0.9rem; margin-top: 1rem; }
    </style>
</head>
<body>
    <div class="box">
        <h1 style="font-size:1.25rem;margin:0 0 0.5rem">Syncing Google Sheets</h1>
        <p><?= (int) $done ?> / <?= (int) $total ?> registrations (<?= (int) $pct ?>%)</p>
        <div class="bar"><span></span></div>
        <p class="muted">Do not close this tab. The next batch starts automatically.</p>
        <form method="post" action="events-sheets-action.php" style="margin-top:1rem">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="sync_registrations_cancel">
            <button type="submit" style="background:none;border:none;color:#93c5fd;cursor:pointer;font-size:0.9rem">Cancel sync</button>
        </form>
        <form id="sheet-sync-continue" method="post" action="events-sheets-action.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="sync_registrations_continue">
        </form>
    </div>
    <script>setTimeout(function () { document.getElementById('sheet-sync-continue').submit(); }, 800);</script>
</body>
</html>
        <?php
        exit;
    }

    unset($_SESSION['bulk_sheet_sync']);

    $removed = (int) ($stats['removed'] ?? 0);
    logAdminAudit(
        $pdo,
        'bulk_sheet_sync',
        'system',
        null,
        "synced {$stats['synced']}, removed {$removed}, skipped {$stats['skipped']}, failed {$stats['failed']}"
    );

    if ($stats['failed'] === 0 && ($stats['synced'] > 0 || $removed > 0)) {
        $parts = [];
        if ($stats['synced'] > 0) {
            $parts[] = "synced {$stats['synced']} approved row(s)";
        }
        if ($removed > 0) {
            $parts[] = "removed {$removed} pending/rejected row(s)";
        }
        setAdminFlash('success', ucfirst(implode('; ', $parts)) . ' on linked Google Sheets.');
    } elseif ($stats['synced'] > 0 || $removed > 0) {
        setAdminFlash(
            'warning',
            "Synced {$stats['synced']} row(s); {$stats['failed']} failed. Check storage/logs/google-sheets.log"
        );
    } elseif ($stats['skipped'] > 0 && $stats['failed'] === 0) {
        setAdminFlash('success', 'Sync complete. No sheet changes were needed for the remaining registrations.');
    } else {
        setAdminFlash('error', 'Sheet sync failed. Check storage/logs/google-sheets.log');
    }

    header('Location: events.php');
    exit;
}

if ($action === 'create_selected') {
    @set_time_limit(600);

    $eventIds = normalizeBulkEventIds($_POST['event_ids'] ?? []);
    $stats    = bulkCreateGoogleSheetsForEvents($pdo, true, $eventIds);

    logAdminAudit(
        $pdo,
        'bulk_event_sheets_create',
        'system',
        null,
        "selected create: {$stats['created']}, skipped {$stats['skipped']}, failed {$stats['failed']}"
    );

    if ($stats['errors'] !== [] && str_contains($stats['errors'][0], 'Select at least')) {
        setAdminFlash('error', $stats['errors'][0]);
    } elseif ($stats['created'] > 0 && $stats['failed'] === 0) {
        $msg = "Created {$stats['created']} Google Sheet(s) for selected events.";
        if ($stats['skipped'] > 0) {
            $msg .= " {$stats['skipped']} already had a sheet — unlink those first if you want new files.";
        }
        setAdminFlash('success', $msg);
    } elseif ($stats['created'] > 0) {
        $hint = implode('; ', array_slice($stats['errors'], 0, 3));
        setAdminFlash('warning', "Created {$stats['created']} sheet(s); {$stats['failed']} failed. {$hint}");
    } elseif ($stats['skipped'] > 0 && $stats['failed'] === 0) {
        setAdminFlash('warning', 'Selected events already have sheets linked. Unlink them first, then create again.');
    } else {
        $hint = $stats['errors'] !== [] ? implode('; ', array_slice($stats['errors'], 0, 3)) : 'Check storage/logs/google-sheets.log';
        setAdminFlash('error', 'No sheets were created. ' . $hint);
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
