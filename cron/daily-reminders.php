<?php

/**
 * Daily reminder cron — run once per day (e.g. 09:00).
 *
 * CLI:
 *   php cron/daily-reminders.php
 *
 * Web cron (optional, set reminder_cron_key in Admin → Email):
 *   https://yoursite.com/cron/daily-reminders.php?key=YOUR_SECRET
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/reminders.php';
require_once dirname(__DIR__) . '/includes/staff-blacklist.php';
require_once dirname(__DIR__) . '/includes/audit-log.php';

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
    $pdo            = getDB();
    $stats          = runDailyReminders($pdo);
    require_once dirname(__DIR__) . '/includes/attendance-repository.php';
    $noShowStats    = processAutoNoShowsForPastEvents($pdo);
    $blacklistStats = processAllNoShowBlacklists($pdo);

    $sheetsQueueStats = ['success' => 0];
    require_once dirname(__DIR__) . '/includes/google-sheets-queue.php';
    if (googleSheetsQueueUsesWorker($pdo)) {
        $sheetsQueueStats = googleSheetsProcessSyncQueue($pdo, 15);
        require_once dirname(__DIR__) . '/includes/google-sheets-auto-worker.php';
        googleSheetsTriggerWorkerAsync($pdo);
    }

    $line           = sprintf(
        "[%s] Daily reminders — event: %d sent, signup nudges: %d sent, errors: %d; auto no-show: %d marked; no-show blacklist: %d new, %d scanned; sheets queue success: %s\n",
        date('Y-m-d H:i:s'),
        $stats['daily_sent'],
        $stats['nudge_sent'],
        $stats['errors'],
        $noShowStats['marked'],
        $blacklistStats['blacklisted'],
        $blacklistStats['scanned'],
        is_array($sheetsQueueStats) ? (string) (int) ($sheetsQueueStats['success'] ?? 0) : '—'
    );

    $logDir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logDir . '/reminders.log', $line, FILE_APPEND | LOCK_EX);

    if ($isCli) {
        echo $line;
    } else {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => true, 'stats' => $stats, 'no_show' => $noShowStats, 'blacklist' => $blacklistStats], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $e) {
    if ($isCli) {
        fwrite(STDERR, 'Reminder run failed: ' . $e->getMessage() . "\n");
        exit(1);
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Reminder run failed.']);
    exit;
}
