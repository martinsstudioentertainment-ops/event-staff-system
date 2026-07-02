<?php

/**
 * Google Sheets sync queue worker — run every minute.
 *
 * Processes one event sheet rebuild per run (default) to stay under Google's
 * ~60 writes/minute quota. Manual re-sync and live updates enqueue here too.
 *
 * CLI:
 *   php cron/google-sheets-sync.php
 *
 * Web cron (uses reminder_cron_key from Admin → Settings → Email):
 *   https://admin.olasentra.com/cron/google-sheets-sync.php?key=YOUR_SECRET
 *
 * cPanel cron (recommended):
 *   * * * * * /usr/local/bin/php /home/USER/public_html/cron/google-sheets-sync.php
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/google-sheets-queue.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');

if (!$isCli) {
    try {
        $pdo = getDB();
        $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
        $providedKey = trim((string) ($_GET['key'] ?? ''));

        if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "Forbidden\n";
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo "Database error\n";
        exit;
    }
}

try {
    $pdo   = getDB();
    $stats = googleSheetsProcessSyncQueue($pdo);
    $queue = googleSheetsQueueSummary($pdo);

    $line = sprintf(
        "[%s] Google Sheets queue — processed: %d, success: %d, requeued: %d, failed: %d, skipped: %d, released: %d; pending: %d\n",
        date('Y-m-d H:i:s'),
        $stats['processed'],
        $stats['success'],
        $stats['requeued'],
        $stats['failed'],
        $stats['skipped'],
        $stats['released'],
        $queue['pending']
    );

    require_once dirname(__DIR__) . '/includes/google-sheets-auto-worker.php';
    systemHeartbeatArmNext($pdo, (int) ($queue['pending'] ?? 0));

    if ($isCli) {
        echo $line;
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $line;
    }
} catch (Throwable $e) {
    error_log('[EventStaff] google-sheets-sync cron: ' . $e->getMessage());
    if (!$isCli) {
        http_response_code(500);
        echo "Error: " . $e->getMessage() . "\n";
    } else {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
