<?php

/**
 * Daily safe cleanup — truncate application log files (does not touch DB, config, or backups).
 *
 * CLI:
 *   php cron/daily-cleanup.php
 *
 * Web cron (use the same key as daily reminders — Admin → Email → reminder cron key):
 *   https://admin.olasentra.com/cron/daily-cleanup.php?key=YOUR_SECRET
 *
 * Recommended schedule: once per day (e.g. 04:00), NOT every 5 minutes.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/system-cleanup.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');

if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');

    try {
        $pdo         = getDB();
        $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
        $providedKey = trim((string) ($_GET['key'] ?? ''));

        if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            http_response_code(403);
            echo "Forbidden\n";
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo "Database error\n";
        exit;
    }
}

$result = clearApplicationLogs();
$lines  = [];

if ($result['cleared'] !== []) {
    $lines[] = 'Cleared: ' . implode(', ', $result['cleared']);
    $lines[] = 'Freed: ' . formatBytesHuman((int) $result['freed_bytes']);
} else {
    $lines[] = 'No log files needed clearing.';
}

if ($result['errors'] !== []) {
    $lines[] = 'Errors: ' . implode('; ', $result['errors']);
}

echo implode("\n", $lines) . "\n";
