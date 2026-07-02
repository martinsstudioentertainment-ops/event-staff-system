<?php

declare(strict_types=1);

require_once __DIR__ . '/platform-schema.php';
require_once __DIR__ . '/../settings-repository.php';
require_once __DIR__ . '/../google-sheets-schedule.php';
require_once __DIR__ . '/../google-sheets-queue.php';
require_once __DIR__ . '/sheets-sync-log.php';

/** @return array<string, mixed> */
function summarizeGoogleSheetsControl(PDO $pdo): array
{
    ensurePlatformMaturitySchema($pdo);

    $connected = 0;
    try {
        $connected = (int) $pdo->query(
            "SELECT COUNT(*) FROM events WHERE google_sheet_url IS NOT NULL AND google_sheet_url != '' AND is_active = 1"
        )->fetchColumn();
    } catch (Throwable $e) {
        // optional
    }

    $failed24h = 0;
    $success24h = 0;
    try {
        $failed24h = (int) $pdo->query(
            "SELECT COUNT(*) FROM platform_sheets_sync_log
             WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        )->fetchColumn();
        $success24h = (int) $pdo->query(
            "SELECT COUNT(*) FROM platform_sheets_sync_log
             WHERE status = 'success' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        )->fetchColumn();
    } catch (Throwable $e) {
        // table may not exist yet
    }

    require_once __DIR__ . '/../google-sheets-sync.php';

    $schedule = googleSheetsSyncScheduleStatus($pdo);
    $queue    = googleSheetsQueueSummary($pdo);

    return [
        'sync_enabled'       => isGoogleSheetsSyncEnabled($pdo),
        'live_sync_allowed'  => (bool) ($schedule['live_sync_allowed'] ?? false),
        'quiet_hours_now'    => (bool) ($schedule['quiet_hours_now'] ?? false),
        'schedule_status'    => (string) ($schedule['status_label'] ?? ''),
        'last_live_sync_at'  => (string) ($schedule['last_live_sync_at'] ?? ''),
        'schedule_settings'  => $schedule['settings'] ?? [],
        'connected_events'   => $connected,
        'failed_24h'         => $failed24h,
        'success_24h'        => $success24h,
        'api_configured'     => isGoogleServiceAccountConfigured(),
        'last_error'         => getLastGoogleSheetsApiError(),
        'queue_pending'        => (int) ($queue['pending'] ?? 0),
        'queue_processing'     => (int) ($queue['processing'] ?? 0),
        'queue_oldest_pending' => (string) ($queue['oldest_pending'] ?? ''),
        'queue_last_run_at'    => getSetting($pdo, 'google_sheets_queue_last_run_at', ''),
        'queue_worker_on'      => googleSheetsQueueUsesWorker($pdo),
        'queue_stuck'          => googleSheetsQueueLooksStuck($pdo, $queue, $success24h),
    ];
}

/**
 * Pending items with no worker activity in 24h usually means cron is not scheduled.
 */
function googleSheetsQueueLooksStuck(PDO $pdo, array $queue, int $success24h): bool
{
    if (!googleSheetsQueueUsesWorker($pdo)) {
        return false;
    }

    $pending = (int) ($queue['pending'] ?? 0);
    if ($pending < 1) {
        return false;
    }

    if ($success24h > 0) {
        return false;
    }

    $lastRun = trim(getSetting($pdo, 'google_sheets_queue_last_run_at', ''));
    if ($lastRun === '') {
        return true;
    }

    $ts = strtotime($lastRun . ' UTC');

    return $ts === false || (time() - $ts) > 86400;
}

/** @return array<int, array<string, mixed>> */
function listConnectedEventSheets(PDO $pdo, int $limit = 50): array
{
    $limit = max(1, min($limit, 100));
    ensurePlatformMaturitySchema($pdo);

    try {
        $stmt = $pdo->query("
            SELECT e.id, e.name, e.event_date, e.google_sheet_url,
                   (SELECT MAX(l.created_at) FROM platform_sheets_sync_log l WHERE l.event_id = e.id AND l.status = 'success') AS last_sync,
                   (SELECT COUNT(*) FROM platform_sheets_sync_log l WHERE l.event_id = e.id AND l.status = 'failed' AND l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS failed_7d
            FROM events e
            WHERE e.google_sheet_url IS NOT NULL AND e.google_sheet_url != ''
            ORDER BY e.event_date DESC
            LIMIT {$limit}
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try {
            return $pdo->query(
                "SELECT id, name, event_date, google_sheet_url FROM events
                 WHERE google_sheet_url IS NOT NULL AND google_sheet_url != ''
                 ORDER BY event_date DESC LIMIT {$limit}"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e2) {
            return [];
        }
    }
}

/** @return array<int, array<string, mixed>> */
function getRecentSheetsSyncFailures(PDO $pdo, int $limit = 20): array
{
    ensurePlatformMaturitySchema($pdo);
    $limit = max(1, min($limit, 100));

    try {
        $stmt = $pdo->query("
            SELECT l.*, e.name AS event_name
            FROM platform_sheets_sync_log l
            LEFT JOIN events e ON e.id = l.event_id
            WHERE l.status = 'failed'
            ORDER BY l.created_at DESC
            LIMIT {$limit}
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return array<int, array<string, mixed>> */
function getSheetsSyncLogForEvent(PDO $pdo, int $eventId, int $limit = 10): array
{
    ensurePlatformMaturitySchema($pdo);
    $limit = max(1, min($limit, 50));

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM platform_sheets_sync_log
            WHERE event_id = :eid
            ORDER BY created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute(['eid' => $eventId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return array{events: int, success: int, failed: int, queued?: int} */
function resyncAllEventSheets(PDO $pdo): array
{
    require_once __DIR__ . '/../google-sheets-sync.php';
    require_once __DIR__ . '/../google-sheets-queue.php';
    ensurePlatformMaturitySchema($pdo);

    $stats = ['events' => 0, 'success' => 0, 'failed' => 0, 'queued' => 0];

    try {
        $eventIds = $pdo->query(
            "SELECT id FROM events WHERE google_sheet_url IS NOT NULL AND google_sheet_url != '' AND is_active = 1"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return $stats;
    }

    $eventIds = array_map('intval', $eventIds);

    if (googleSheetsQueueUsesWorker($pdo)) {
        $enqueue       = googleSheetsEnqueueEventRebuilds($pdo, $eventIds, 'manual', true, 1);
        $stats['events'] = count($eventIds);
        $stats['queued'] = (int) ($enqueue['queued'] + $enqueue['deduped']);

        return $stats;
    }

    foreach ($eventIds as $eventId) {
        $stats['events']++;
        try {
            $result = googleSheetsRebuildEventTab($pdo, $eventId, null, false);
            if (!empty($result['ok'])) {
                $stats['success']++;
            } else {
                $stats['failed']++;
            }
        } catch (Throwable $e) {
            logSheetsSyncEvent($pdo, 'manual_resync', 'failed', $eventId, null, $e->getMessage());
            $stats['failed']++;
        }
    }

    return $stats;
}
