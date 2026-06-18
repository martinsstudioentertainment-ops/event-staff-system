<?php

declare(strict_types=1);

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/site-urls.php';

/** Seconds between worker pings when the sheets queue has pending work. */
function googleSheetsAutoWorkerDrainIntervalSeconds(): int
{
    return 55;
}

/** Seconds between heartbeat pings when the queue is idle (24/7 keepalive). */
function systemHeartbeatIdleIntervalSeconds(): int
{
    return 90;
}

/** Restart the loop if no heartbeat ping in this many seconds. */
function systemHeartbeatStaleSeconds(): int
{
    return 300;
}

function googleSheetsEnsureWorkerCronKey(PDO $pdo): string
{
    $key = trim(getSetting($pdo, 'reminder_cron_key', ''));
    if ($key !== '') {
        return $key;
    }

    $key = bin2hex(random_bytes(16));
    setSetting($pdo, 'reminder_cron_key', $key);

    return $key;
}

function systemHeartbeatGetUrl(PDO $pdo): string
{
    $base = rtrim(getAdminSiteUrl($pdo), '/');
    if ($base === '' && function_exists('getAppBaseUrl')) {
        $base = rtrim((string) getAppBaseUrl(), '/');
    }
    if ($base === '') {
        return '';
    }

    $key = googleSheetsEnsureWorkerCronKey($pdo);

    return $base . '/cron/system-heartbeat.php?key=' . rawurlencode($key);
}

/** @deprecated Use systemHeartbeatGetUrl */
function getGoogleSheetsWorkerCronUrl(PDO $pdo): string
{
    return systemHeartbeatGetUrl($pdo);
}

function systemHeartbeatPingUrlAsync(string $url): void
{
    if ($url === '' || !function_exists('curl_init')) {
        return;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS        => 3000,
        CURLOPT_CONNECTTIMEOUT_MS => 1500,
        CURLOPT_NOSIGNAL          => 1,
        CURLOPT_FOLLOWLOCATION    => true,
    ]);

    @curl_exec($ch);
    curl_close($ch);
}

/**
 * Schedule the next heartbeat tick (self-chaining loop).
 */
function systemHeartbeatArmNext(PDO $pdo, int $pending = 0): void
{
    require_once __DIR__ . '/google-sheets-queue.php';
    if (!googleSheetsQueueUsesWorker($pdo)) {
        return;
    }

    $interval = $pending > 0
        ? googleSheetsAutoWorkerDrainIntervalSeconds()
        : systemHeartbeatIdleIntervalSeconds();

    $last = trim(getSetting($pdo, 'system_heartbeat_last_ping_at', ''));
    if ($last !== '') {
        $ts = strtotime($last . ' UTC');
        if ($ts !== false && (time() - $ts) < $interval) {
            return;
        }
    }

    $url = systemHeartbeatGetUrl($pdo);
    if ($url === '') {
        return;
    }

    setSetting($pdo, 'system_heartbeat_last_ping_at', gmdate('Y-m-d H:i:s'));
    systemHeartbeatPingUrlAsync($url);
}

/**
 * Restart the 24/7 loop after deploy, server restart, or if the chain died.
 */
function systemHeartbeatEnsureLoopRunning(PDO $pdo): void
{
    require_once __DIR__ . '/google-sheets-queue.php';
    if (!googleSheetsQueueUsesWorker($pdo)) {
        return;
    }

    $last = trim(getSetting($pdo, 'system_heartbeat_last_ping_at', ''));
    if ($last !== '') {
        $ts = strtotime($last . ' UTC');
        if ($ts !== false && (time() - $ts) < systemHeartbeatStaleSeconds()) {
            return;
        }
    }

    $queue   = googleSheetsQueueSummary($pdo);
    $pending = (int) ($queue['pending'] ?? 0);
    systemHeartbeatArmNext($pdo, $pending);
}

/** Fire-and-forget when new sheet work is queued. */
function googleSheetsTriggerWorkerAsync(PDO $pdo): void
{
    require_once __DIR__ . '/google-sheets-queue.php';
    if (!googleSheetsQueueUsesWorker($pdo)) {
        return;
    }

    $summary = googleSheetsQueueSummary($pdo);
    $pending = (int) ($summary['pending'] ?? 0);
    if ($pending < 1) {
        return;
    }

    systemHeartbeatArmNext($pdo, $pending);
}

function googleSheetsAutoWorkerMayRunInline(PDO $pdo): bool
{
    $last = trim(getSetting($pdo, 'google_sheets_auto_worker_inline_at', ''));
    if ($last === '') {
        return true;
    }

    $ts = strtotime($last . ' UTC');

    return $ts === false || (time() - $ts) >= 45;
}

function googleSheetsAutoWorkerMarkInlineRun(PDO $pdo): void
{
    setSetting($pdo, 'google_sheets_auto_worker_inline_at', gmdate('Y-m-d H:i:s'));
}

/**
 * Process queued sheet syncs inline (throttled). Safe after fastcgi_finish_request.
 *
 * @return array<string, int>|null
 */
function googleSheetsRunAutoWorkerInline(PDO $pdo, int $maxJobs = 1): ?array
{
    require_once __DIR__ . '/google-sheets-queue.php';
    if (!googleSheetsQueueUsesWorker($pdo)) {
        return null;
    }

    $summary = googleSheetsQueueSummary($pdo);
    if ((int) ($summary['pending'] ?? 0) < 1) {
        return null;
    }

    if (!googleSheetsAutoWorkerMayRunInline($pdo)) {
        return null;
    }

    googleSheetsAutoWorkerMarkInlineRun($pdo);
    $maxJobs = max(1, min(3, $maxJobs));
    $stats   = googleSheetsProcessSyncQueue($pdo, $maxJobs);

    $queue = googleSheetsQueueSummary($pdo);
    if ((int) ($queue['pending'] ?? 0) > 0) {
        googleSheetsTriggerWorkerAsync($pdo);
    }

    return $stats;
}

/** Queue new work → wake the 24/7 heartbeat loop. */
function googleSheetsScheduleAutoWorker(PDO $pdo): void
{
    require_once __DIR__ . '/google-sheets-queue.php';
    if (!googleSheetsQueueUsesWorker($pdo)) {
        return;
    }

    static $shutdownRegistered = false;
    if (!$shutdownRegistered) {
        $shutdownRegistered = true;
        register_shutdown_function(static function (): void {
            if (PHP_SAPI === 'cli') {
                return;
            }
            try {
                if (!function_exists('getDB')) {
                    return;
                }
                $pdo = getDB();
                googleSheetsRunAutoWorkerOnShutdown($pdo);
            } catch (Throwable $e) {
                error_log('[EventStaff] sheets auto worker shutdown: ' . $e->getMessage());
            }
        });
    }

    $summary = googleSheetsQueueSummary($pdo);
    systemHeartbeatArmNext($pdo, (int) ($summary['pending'] ?? 0));
    systemHeartbeatEnsureLoopRunning($pdo);
}

function googleSheetsRunAutoWorkerOnShutdown(PDO $pdo): void
{
    if (connection_aborted() && !function_exists('fastcgi_finish_request')) {
        return;
    }

    @set_time_limit(120);
    googleSheetsRunAutoWorkerInline($pdo, 1);
}

/**
 * @return array<string, mixed>
 */
function googleSheetsAutoWorkerPing(PDO $pdo): array
{
    require_once __DIR__ . '/google-sheets-queue.php';

    $queue = googleSheetsQueueSummary($pdo);
    if (!googleSheetsQueueUsesWorker($pdo)) {
        return ['ok' => true, 'worker' => 'off', 'queue' => $queue];
    }

    $stats = googleSheetsRunAutoWorkerInline($pdo, 1);
    $queue = googleSheetsQueueSummary($pdo);

    return [
        'ok'        => true,
        'worker'    => 'on',
        'processed' => $stats,
        'queue'     => $queue,
    ];
}
