<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/platform/google-sheets-control.php';
require_once __DIR__ . '/../includes/google-sheets-sync.php';
require_once __DIR__ . '/../includes/google-sheets-queue.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('events');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: google-sheets-control.php');
    exit;
}

$pdo     = getDB();
$action  = (string) ($_POST['action'] ?? 'resync');
$eventId = (int) ($_POST['event_id'] ?? 0);

if ($action === 'process_queue') {
    if (!googleSheetsQueueUsesWorker($pdo)) {
        setAdminFlash('error', 'Queue worker is OFF — enable it under Sync schedule, or use direct re-sync.');
        header('Location: google-sheets-control.php');
        exit;
    }

    $maxJobs = max(1, min(10, (int) ($_POST['max_jobs'] ?? 5)));
    $stats   = googleSheetsProcessSyncQueue($pdo, $maxJobs);
    $queue   = googleSheetsQueueSummary($pdo);
    logAdminAudit(
        $pdo,
        'sheets_queue_run',
        'system',
        0,
        sprintf(
            'Manual queue run — processed %d, success %d, pending %d',
            (int) ($stats['processed'] ?? 0),
            (int) ($stats['success'] ?? 0),
            (int) ($queue['pending'] ?? 0)
        )
    );
    setAdminFlash(
        'success',
        sprintf(
            'Queue worker ran — processed %d, success %d, requeued %d, failed %d. %d still pending.',
            (int) ($stats['processed'] ?? 0),
            (int) ($stats['success'] ?? 0),
            (int) ($stats['requeued'] ?? 0),
            (int) ($stats['failed'] ?? 0),
            (int) ($queue['pending'] ?? 0)
        )
    );
    header('Location: google-sheets-control.php');
    exit;
}

if ($eventId > 0) {
    try {
        if (googleSheetsQueueUsesWorker($pdo)) {
            googleSheetsEnqueueEventRebuild($pdo, $eventId, 'manual', null, true, 1);
            logAdminAudit($pdo, 'sheets_resync', 'event', $eventId, 'Manual single-event resync queued');
            setAdminFlash(
                'success',
                'Event sheet queued for re-sync. The cron worker processes about one event per minute — refresh this page to watch the queue drain.'
            );
        } else {
            $result = googleSheetsRebuildEventTab($pdo, $eventId, null, false);
            logAdminAudit($pdo, 'sheets_resync', 'event', $eventId, 'Manual single-event resync');
            if (!empty($result['ok'])) {
                setAdminFlash('success', 'Event sheet re-synced successfully.');
            } else {
                setAdminFlash('error', 'Re-sync failed: ' . (string) ($result['message'] ?? 'Unknown error'));
            }
        }
    } catch (Throwable $e) {
        logSheetsSyncEvent($pdo, 'manual_resync', 'failed', $eventId, null, $e->getMessage());
        setAdminFlash('error', 'Re-sync failed: ' . $e->getMessage());
    }
} else {
    $stats = resyncAllEventSheets($pdo);
    logAdminAudit($pdo, 'sheets_resync', 'system', 0, 'Manual resync: ' . json_encode($stats));
    if (googleSheetsQueueUsesWorker($pdo)) {
        $queued = (int) ($stats['queued'] ?? 0);
        setAdminFlash(
            'success',
            $queued > 0
                ? "Queued {$queued} event sheet(s) for re-sync. Cron runs ~1 event per minute — about {$queued} minute(s) to finish."
                : 'No linked events to queue.'
        );
    } else {
        setAdminFlash(
            'success',
            'Re-sync complete — ' . (int) $stats['success'] . ' OK, ' . (int) $stats['failed'] . ' failed, ' . (int) $stats['events'] . ' events.'
        );
    }
}

header('Location: google-sheets-control.php');
exit;
