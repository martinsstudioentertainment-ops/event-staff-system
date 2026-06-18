<?php

/**
 * One-shot: reject duplicate same-day shifts (keep earliest registration per day).
 *
 * CLI:
 *   php cron/reject-same-day-duplicates.php
 *
 * Web (reminder_cron_key):
 *   https://admin.olasentra.com/cron/reject-same-day-duplicates.php?key=YOUR_SECRET
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/validation.php';

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

$fromDate = '';
if ($isCli && isset($argv[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $argv[1])) {
    $fromDate = (string) $argv[1];
} elseif (!$isCli) {
    $fromDate = trim((string) ($_GET['from'] ?? ''));
    if ($fromDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
        $fromDate = '';
    }
}

try {
    $pdo    = getDB();
    $result = rejectSameDayDuplicateShifts($pdo, $fromDate !== '' ? $fromDate : null);
    $line   = sprintf(
        "[%s] Same-day duplicates — groups: %d, kept: %d, rejected: %d, errors: %d\n",
        date('Y-m-d H:i:s'),
        $result['groups'],
        $result['kept'],
        $result['rejected'],
        count($result['errors'])
    );

    if ($isCli) {
        echo $line;
        if ($result['errors'] !== []) {
            foreach ($result['errors'] as $err) {
                echo '  ERROR: ' . $err . "\n";
            }
        }
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $line;
        if ($result['errors'] !== []) {
            echo implode("\n", $result['errors']) . "\n";
        }
    }
} catch (Throwable $e) {
    error_log('[EventStaff] reject-same-day-duplicates: ' . $e->getMessage());
    if (!$isCli) {
        http_response_code(500);
        echo 'Error: ' . $e->getMessage() . "\n";
    } else {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
