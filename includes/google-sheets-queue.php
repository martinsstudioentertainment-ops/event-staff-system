<?php

declare(strict_types=1);

require_once __DIR__ . '/google-sheets-schema.php';
require_once __DIR__ . '/google-sheets-schedule.php';
require_once __DIR__ . '/platform/sheets-sync-log.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/settings-repository.php';

function ensureGoogleSheetsSyncQueueSchema(PDO $pdo): void
{
    static $ready = [];

    $key = spl_object_id($pdo);
    if (!empty($ready[$key])) {
        return;
    }

    $migration = dirname(__DIR__) . '/database/migrate-google-sheets-sync-queue.sql';
    if (is_file($migration)) {
        try {
            $pdo->exec((string) file_get_contents($migration));
        } catch (PDOException $e) {
            if (!str_contains($e->getMessage(), 'already exists')) {
                error_log('[EventStaff] google sheets queue schema: ' . $e->getMessage());
            }
        }
    } else {
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS google_sheets_sync_queue (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    event_id INT UNSIGNED NOT NULL,
                    registration_id INT UNSIGNED NULL DEFAULT NULL,
                    source VARCHAR(32) NOT NULL DEFAULT 'live',
                    priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
                    force_sync TINYINT(1) NOT NULL DEFAULT 0,
                    status ENUM('pending', 'processing', 'done', 'failed') NOT NULL DEFAULT 'pending',
                    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_error VARCHAR(500) NULL DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_queue_pick (status, available_at, priority, id),
                    KEY idx_queue_event (event_id, status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (PDOException $e) {
            if (!str_contains($e->getMessage(), 'already exists')) {
                error_log('[EventStaff] google sheets queue schema inline: ' . $e->getMessage());
            }
        }
    }

    $ready[$key] = true;
}

function googleSheetsQueueUsesWorker(PDO $pdo): bool
{
    return getSetting($pdo, 'google_sheets_queue_enabled', '1') === '1';
}

function googleSheetsQueueJobsPerRun(PDO $pdo): int
{
    $n = (int) getSetting($pdo, 'google_sheets_cron_jobs_per_run', '1');

    return max(1, min(3, $n));
}

function googleSheetsQueueMaxAttempts(): int
{
    return 5;
}

function googleSheetsQueueRateLimitDelaySeconds(): int
{
    return 90;
}

/**
 * @return array{pending: int, processing: int, failed: int, oldest_pending: ?string}
 */
function googleSheetsQueueSummary(PDO $pdo): array
{
    ensureGoogleSheetsSyncQueueSchema($pdo);

    $summary = [
        'pending'        => 0,
        'processing'     => 0,
        'failed'         => 0,
        'oldest_pending' => null,
    ];

    try {
        $summary['pending'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM google_sheets_sync_queue WHERE status = 'pending'"
        )->fetchColumn();
        $summary['processing'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM google_sheets_sync_queue WHERE status = 'processing'"
        )->fetchColumn();
        $summary['failed'] = (int) $pdo->query(
            "SELECT COUNT(*) FROM google_sheets_sync_queue
             WHERE status = 'failed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->fetchColumn();
        $oldest = $pdo->query(
            "SELECT MIN(created_at) FROM google_sheets_sync_queue WHERE status = 'pending'"
        )->fetchColumn();
        $summary['oldest_pending'] = is_string($oldest) && $oldest !== '' ? $oldest : null;
    } catch (Throwable $e) {
        // table may not exist yet
    }

    return $summary;
}

function googleSheetsQueueReleaseStaleProcessing(PDO $pdo, int $minutes = 15): int
{
    ensureGoogleSheetsSyncQueueSchema($pdo);
    $minutes = max(5, min(120, $minutes));

    try {
        $stmt = $pdo->prepare(
            "UPDATE google_sheets_sync_queue
             SET status = 'pending', updated_at = NOW()
             WHERE status = 'processing'
               AND updated_at < DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)"
        );
        $stmt->execute();

        return $stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

function googleSheetsQueuePurgeOldDone(PDO $pdo, int $days = 14): void
{
    ensureGoogleSheetsSyncQueueSchema($pdo);
    $days = max(1, min(90, $days));

    try {
        $pdo->exec(
            "DELETE FROM google_sheets_sync_queue
             WHERE status IN ('done', 'failed')
               AND updated_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)"
        );
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * Enqueue one event tab rebuild. Dedupes pending/processing rows for the same event.
 */
function googleSheetsEnqueueEventRebuild(
    PDO $pdo,
    int $eventId,
    string $source = 'live',
    ?int $registrationId = null,
    bool $forceSync = false,
    int $priority = 5
): bool {
    if ($eventId < 1) {
        return false;
    }

    ensureGoogleSheetsSyncQueueSchema($pdo);
    ensureGoogleSheetsSchema($pdo);

    $event = getEventById($pdo, $eventId);
    if ($event === null) {
        return false;
    }

    $sheetUrl = trim((string) ($event['google_sheet_url'] ?? ''));
    if ($sheetUrl === '') {
        return false;
    }

    if (!$forceSync && getSetting($pdo, 'google_sheets_sync_enabled', '0') !== '1') {
        return false;
    }

    $source   = substr(preg_replace('/[^a-z0-9_]/', '', strtolower($source)) ?: 'live', 0, 32);
    $priority = max(1, min(10, $priority));
    $force    = $forceSync ? 1 : 0;

    try {
        $stmt = $pdo->prepare(
            "SELECT id, priority, force_sync FROM google_sheets_sync_queue
             WHERE event_id = :event_id AND status IN ('pending', 'processing')
             LIMIT 1"
        );
        $stmt->execute(['event_id' => $eventId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($existing)) {
            $newPriority = min((int) $existing['priority'], $priority);
            $newForce    = max((int) $existing['force_sync'], $force);
            $upd = $pdo->prepare(
                "UPDATE google_sheets_sync_queue
                 SET priority = :priority,
                     force_sync = :force_sync,
                     source = :source,
                     registration_id = COALESCE(:registration_id, registration_id),
                     available_at = LEAST(available_at, NOW()),
                     updated_at = NOW()
                 WHERE id = :id"
            );
            $upd->execute([
                'priority'         => $newPriority,
                'force_sync'       => $newForce,
                'source'           => $source,
                'registration_id'  => $registrationId,
                'id'               => (int) $existing['id'],
            ]);

            require_once __DIR__ . '/google-sheets-auto-worker.php';
            googleSheetsScheduleAutoWorker($pdo);

            return false;
        }

        $ins = $pdo->prepare(
            "INSERT INTO google_sheets_sync_queue
             (event_id, registration_id, source, priority, force_sync, status, available_at)
             VALUES (:event_id, :registration_id, :source, :priority, :force_sync, 'pending', NOW())"
        );
        $ins->execute([
            'event_id'         => $eventId,
            'registration_id'  => $registrationId,
            'source'           => $source,
            'priority'         => $priority,
            'force_sync'       => $force,
        ]);

        logSheetsSyncEvent(
            $pdo,
            'queue_' . $source,
            'queued',
            $eventId,
            $registrationId,
            'Queued for cron worker'
        );

        require_once __DIR__ . '/google-sheets-auto-worker.php';
        googleSheetsScheduleAutoWorker($pdo);

        return true;
    } catch (Throwable $e) {
        error_log('[EventStaff] google sheets enqueue: ' . $e->getMessage());

        return false;
    }
}

/**
 * @param list<int> $eventIds
 * @return array{queued: int, deduped: int, skipped: int}
 */
function googleSheetsEnqueueEventRebuilds(
    PDO $pdo,
    array $eventIds,
    string $source = 'live',
    bool $forceSync = false,
    int $priority = 5
): array {
    $stats = ['queued' => 0, 'deduped' => 0, 'skipped' => 0];

    $seen = [];
    foreach ($eventIds as $rawId) {
        $eventId = (int) $rawId;
        if ($eventId < 1 || isset($seen[$eventId])) {
            continue;
        }
        $seen[$eventId] = true;

        if (googleSheetsEnqueueEventRebuild($pdo, $eventId, $source, null, $forceSync, $priority)) {
            $stats['queued']++;
        } else {
            $stats['deduped']++;
        }
    }

    return $stats;
}

/**
 * @return array<string, mixed>|null
 */
function googleSheetsQueueClaimNextJob(PDO $pdo): ?array
{
    ensureGoogleSheetsSyncQueueSchema($pdo);

    $startedTx = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTx = true;
    }

    try {
        $stmt = $pdo->query(
            "SELECT id, event_id, registration_id, source, force_sync, attempts
             FROM google_sheets_sync_queue
             WHERE status = 'pending'
               AND available_at <= NOW()
             ORDER BY priority ASC, id ASC
             LIMIT 1
             FOR UPDATE"
        );
        $job = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        if (!is_array($job)) {
            if ($startedTx) {
                $pdo->commit();
            }

            return null;
        }

        $claim = $pdo->prepare(
            "UPDATE google_sheets_sync_queue
             SET status = 'processing', attempts = attempts + 1, updated_at = NOW()
             WHERE id = :id AND status = 'pending'"
        );
        $claim->execute(['id' => (int) $job['id']]);

        if ($startedTx) {
            $pdo->commit();
        }

        return $job;
    } catch (Throwable $e) {
        if ($startedTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[EventStaff] google sheets queue claim: ' . $e->getMessage());

        return null;
    }
}

function googleSheetsQueueMarkDone(PDO $pdo, int $jobId): void
{
    $stmt = $pdo->prepare(
        "UPDATE google_sheets_sync_queue SET status = 'done', last_error = NULL, updated_at = NOW() WHERE id = :id"
    );
    $stmt->execute(['id' => $jobId]);
}

function googleSheetsQueueRequeue(PDO $pdo, int $jobId, int $delaySeconds, string $error): void
{
    $delaySeconds = max(30, min(600, $delaySeconds));
    $stmt = $pdo->prepare(
        "UPDATE google_sheets_sync_queue
         SET status = 'pending',
             available_at = DATE_ADD(NOW(), INTERVAL {$delaySeconds} SECOND),
             last_error = :error,
             updated_at = NOW()
         WHERE id = :id"
    );
    $stmt->execute([
        'id'    => $jobId,
        'error' => substr($error, 0, 500),
    ]);
}

function googleSheetsQueueMarkFailed(PDO $pdo, int $jobId, string $error): void
{
    $stmt = $pdo->prepare(
        "UPDATE google_sheets_sync_queue
         SET status = 'failed', last_error = :error, updated_at = NOW()
         WHERE id = :id"
    );
    $stmt->execute([
        'id'    => $jobId,
        'error' => substr($error, 0, 500),
    ]);
}

function googleSheetsQueueIsRateLimitMessage(string $message): bool
{
    $httpCode = function_exists('getLastGoogleSheetsHttpCode') ? getLastGoogleSheetsHttpCode() : 0;
    if (function_exists('googleSheetsIsRateLimitResponse')) {
        return googleSheetsIsRateLimitResponse($httpCode, $message);
    }

    return str_contains(strtolower($message), 'rate limit')
        || str_contains($message, '429')
        || str_contains($message, 'Quota exceeded');
}

/**
 * Process queued sheet rebuilds (called by cron).
 *
 * @return array{processed: int, success: int, requeued: int, failed: int, skipped: int, released: int}
 */
function googleSheetsProcessSyncQueue(PDO $pdo, ?int $maxJobs = null): array
{
    require_once __DIR__ . '/google-sheets-sync.php';

    ensureGoogleSheetsSyncQueueSchema($pdo);

    $stats = [
        'processed' => 0,
        'success'   => 0,
        'requeued'  => 0,
        'failed'    => 0,
        'skipped'   => 0,
        'released'  => googleSheetsQueueReleaseStaleProcessing($pdo),
    ];

    if (!googleSheetsQueueUsesWorker($pdo)) {
        return $stats;
    }

    googleSheetsQueuePurgeOldDone($pdo);

    $maxJobs = $maxJobs ?? googleSheetsQueueJobsPerRun($pdo);
    $maxJobs = max(1, min(3, $maxJobs));
    $maxAttempts = googleSheetsQueueMaxAttempts();

    for ($i = 0; $i < $maxJobs; $i++) {
        $job = googleSheetsQueueClaimNextJob($pdo);
        if ($job === null) {
            break;
        }

        $stats['processed']++;
        $jobId       = (int) $job['id'];
        $eventId     = (int) $job['event_id'];
        $forceSync   = ((int) ($job['force_sync'] ?? 0)) === 1;
        $attempts    = (int) ($job['attempts'] ?? 1);
        $requireLive = !$forceSync;

        if ($requireLive && !googleSheetsAllowLiveSyncNow($pdo)) {
            googleSheetsQueueRequeue($pdo, $jobId, 300, 'Live sync paused — retry later');
            $stats['requeued']++;
            continue;
        }

        try {
            $result = googleSheetsRebuildEventTab($pdo, $eventId, null, $requireLive);
        } catch (Throwable $e) {
            $result = [
                'ok'      => false,
                'skipped' => false,
                'rows'    => 0,
                'message' => $e->getMessage(),
            ];
        }

        if (!empty($result['skipped'])) {
            googleSheetsQueueRequeue($pdo, $jobId, 300, (string) ($result['message'] ?? 'Skipped'));
            $stats['skipped']++;
            continue;
        }

        if (!empty($result['ok'])) {
            googleSheetsQueueMarkDone($pdo, $jobId);
            $stats['success']++;
            continue;
        }

        $message = (string) ($result['message'] ?? 'Unknown error');
        if (googleSheetsQueueIsRateLimitMessage($message)) {
            googleSheetsQueueRequeue($pdo, $jobId, googleSheetsQueueRateLimitDelaySeconds(), $message);
            logSheetsSyncEvent($pdo, 'queue_worker', 'queued', $eventId, null, 'Rate limited — requeued');
            $stats['requeued']++;
            continue;
        }

        if ($attempts >= $maxAttempts) {
            googleSheetsQueueMarkFailed($pdo, $jobId, $message);
            logSheetsSyncEvent($pdo, 'queue_worker', 'failed', $eventId, null, $message);
            $stats['failed']++;
        } else {
            $backoff = min(600, 60 * $attempts);
            googleSheetsQueueRequeue($pdo, $jobId, $backoff, $message);
            $stats['requeued']++;
        }
    }

    if ($stats['processed'] > 0 || $stats['released'] > 0) {
        googleSheetsQueueMarkLastRun($pdo);
    }

    $queue = googleSheetsQueueSummary($pdo);
    $pending = (int) ($queue['pending'] ?? 0);
    if ($pending > 0) {
        require_once __DIR__ . '/google-sheets-auto-worker.php';
        systemHeartbeatArmNext($pdo, $pending);
    }

    return $stats;
}

function googleSheetsQueueMarkLastRun(PDO $pdo): void
{
    setSetting($pdo, 'google_sheets_queue_last_run_at', gmdate('Y-m-d H:i:s'));
}
