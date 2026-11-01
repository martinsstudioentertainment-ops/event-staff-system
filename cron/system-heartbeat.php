<?php

/**
 * 24/7 background heartbeat — no admin login or cPanel cron required.
 *
 * Self-chains every ~90s idle (~55s when the sheets queue has work):
 *   - Google Form → Olasentra import (Aviva and generated Forms)
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
    ignore_user_abort(true);
    @set_time_limit(240);

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

    $locked = 1;
    try {
        $locked = (int) $pdo->query("SELECT GET_LOCK('olasentra_system_heartbeat', 0)")->fetchColumn();
    } catch (Throwable $e) {
        $locked = 1;
    }
    if ($locked !== 1) {
        $busy = "busy\n";
        if (!$isCli) {
            header('Content-Type: text/plain; charset=UTF-8');
            header('Content-Length: ' . (string) strlen($busy));
            header('Connection: close');
        }
        echo $busy;
        exit;
    }

    $body = "started\n";
    if (!$isCli) {
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Length: ' . (string) strlen($body));
        header('Connection: close');
        echo $body;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @flush();
        }
    } else {
        echo $body;
    }

    try {
        require_once dirname(__DIR__) . '/includes/google-form-intake.php';
        googleFormIntakeSyncAllSheets($pdo);
    } catch (Throwable $e) {
        error_log('[EventStaff] system-heartbeat Google Form import: ' . $e->getMessage());
    }

    try {
        require_once dirname(__DIR__) . '/includes/event-start-time-form.php';
        avivaOct1StartTimeReconcile($pdo);
    } catch (Throwable $e) {
        error_log('[EventStaff] system-heartbeat start time watch: ' . $e->getMessage());
    }

    try {
        require_once dirname(__DIR__) . '/includes/google-sheets-sync.php';
        googleSheetsEnsureMissingRosterSheets($pdo, 2);
    } catch (Throwable $e) {
        error_log('[EventStaff] system-heartbeat auto roster sheets: ' . $e->getMessage());
    }

    $sheetsStats = ['processed' => 0, 'success' => 0, 'pending' => 0];
    if (googleSheetsQueueUsesWorker($pdo)) {
        $sheetsStats = googleSheetsProcessSyncQueue($pdo);
        $queue         = googleSheetsQueueSummary($pdo);
        $sheetsStats['pending'] = (int) ($queue['pending'] ?? 0);
    }

    try {
        activateHibernatedAttendanceForStartedEvents($pdo);
    } catch (Throwable $e) {
        error_log('[EventStaff] system-heartbeat attendance: ' . $e->getMessage());
    }

    try {
        require_once dirname(__DIR__) . '/includes/aviva-shift-time-reminder.php';
        sendAvivaShiftTimeRemindersIfDue($pdo);
    } catch (Throwable $e) {
        error_log('[EventStaff] system-heartbeat aviva reminder: ' . $e->getMessage());
    }

    try {
        require_once dirname(__DIR__) . '/includes/aviva-oct1-whatsapp-reminder.php';
        sendAvivaOct1WhatsappRemindersIfDue($pdo);
    } catch (Throwable $e) {
        error_log('[EventStaff] system-heartbeat aviva whatsapp reminder: ' . $e->getMessage());
    }

    try {
        $pdo->query("SELECT RELEASE_LOCK('olasentra_system_heartbeat')");
    } catch (Throwable $e) {
        // ignore
    }

    systemHeartbeatArmNext($pdo, (int) ($sheetsStats['pending'] ?? 0));
} catch (Throwable $e) {
    error_log('[EventStaff] system-heartbeat: ' . $e->getMessage());
    try {
        $pdo = getDB();
        $pdo->query("SELECT RELEASE_LOCK('olasentra_system_heartbeat')");
    } catch (Throwable $ignored) {
        // ignore
    }
    if (!$isCli) {
        http_response_code(500);
        echo 'Error: ' . $e->getMessage() . "\n";
    } else {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
