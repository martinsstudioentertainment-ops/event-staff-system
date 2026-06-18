<?php

/**
 * Operations automation cron — staff shortages, compliance, attendance, invoices, events.
 *
 * CLI:  php cron/operations-automation.php
 * Web:  /cron/operations-automation.php?key=YOUR_SECRET
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/system-settings.php';
require_once dirname(__DIR__) . '/includes/automation/ops-automation.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');

if (!$isCli) {
    try {
        $pdo         = getDB();
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
    $stats = ops_run_automation($pdo);
    $line  = sprintf("[%s] Ops automation — %s\n", date('Y-m-d H:i:s'), json_encode($stats));

    $logDir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logDir . '/ops-automation.log', $line, FILE_APPEND | LOCK_EX);

    if ($isCli) {
        echo $line;
    } else {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => true, 'stats' => $stats], JSON_THROW_ON_ERROR);
    }
} catch (Throwable $e) {
    if ($isCli) {
        fwrite(STDERR, 'Ops automation failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Ops automation failed.']);
    exit;
}
