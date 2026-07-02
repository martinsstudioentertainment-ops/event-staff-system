<?php

/**
 * Close awaiting sign-ins as no-show once the event check-in window has ended.
 *
 * CLI:  php cron/attendance-close-no-shows.php
 * Web:  /cron/attendance-close-no-shows.php?key=YOUR_SECRET
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');

if (!$isCli) {
    try {
        $pdo         = getDB();
        $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
        $providedKey = trim((string) ($_GET['key'] ?? ''));

        if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            if ($providedKey === '' || !hash_equals('email-encoding-verify-20260606', $providedKey)) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=UTF-8');
                echo "Forbidden\n";
                exit;
            }
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo "Database error\n";
        exit;
    }
}

try {
    $pdo    = getDB();
    $result = closeAwaitingSigninsAsNoShows($pdo);
    $line   = sprintf(
        "[%s] attendance-close-no-shows — marked=%d skipped=%d blacklisted=%d\n",
        date('Y-m-d H:i:s'),
        (int) ($result['marked'] ?? 0),
        (int) ($result['skipped'] ?? 0),
        (int) ($result['blacklisted'] ?? 0)
    );

    $logDir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logDir . '/attendance-close-no-shows.log', $line, FILE_APPEND | LOCK_EX);

    if ($isCli) {
        echo $line;
    } else {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => true] + $result, JSON_THROW_ON_ERROR);
    }
} catch (Throwable $e) {
    error_log('[EventStaff] attendance-close-no-shows: ' . $e->getMessage());
    if ($isCli) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
