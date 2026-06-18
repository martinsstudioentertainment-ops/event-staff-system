<?php

/**
 * 24/7 background heartbeat — no admin login or cPanel cron required.
 *
 * Runs on a self-chaining HTTP loop (~every 90s idle, ~every 55s when sheets queue has work):
 *   - Google Sheets sync queue (1 event per tick)
 *   - GPS attendance activation (hibernated → active at event start)
 *
 * Also callable from CLI or web cron key (same as daily reminders).
 *
 *   https://admin.olasentra.com/cron/system-heartbeat.php?key=YOUR_SECRET
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/google-sheets-queue.php';
require_once dirname(__DIR__) . '/includes/google-sheets-auto-worker.php';
require_once dirname(__DIR__) . '/includes/attendance-gps-phase1.php';

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
    $pdo = getDB();

    $sheetsStats = ['processed' => 0, 'success' => 0, 'pending' => 0];
    if (googleSheetsQueueUsesWorker($pdo)) {
        $sheetsStats = googleSheetsProcessSyncQueue($pdo);
        $queue         = googleSheetsQueueSummary($pdo);
        $sheetsStats['pending'] = (int) ($queue['pending'] ?? 0);
    }

    $attendanceActivated = 0;
    try {
        $attendanceActivated = activateHibernatedAttendanceForStartedEvents($pdo);
    } catch (Throwable $e) {
        error_log('[EventStaff] system-heartbeat attendance: ' . $e->getMessage());
    }

    systemHeartbeatArmNext($pdo, (int) ($sheetsStats['pending'] ?? 0));

    $line = sprintf(
        "[%s] system-heartbeat — sheets processed=%d success=%d pending=%d; attendance activated=%d\n",
        date('Y-m-d H:i:s'),
        (int) ($sheetsStats['processed'] ?? 0),
        (int) ($sheetsStats['success'] ?? 0),
        (int) ($sheetsStats['pending'] ?? 0),
        $attendanceActivated
    );

    if ($isCli) {
        echo $line;
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $line;
    }
} catch (Throwable $e) {
    error_log('[EventStaff] system-heartbeat: ' . $e->getMessage());
    if (!$isCli) {
        http_response_code(500);
        echo 'Error: ' . $e->getMessage() . "\n";
    } else {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
