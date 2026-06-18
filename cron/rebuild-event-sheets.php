<?php

/**
 * Queue and optionally process Google Sheet rebuilds for specific events.
 *
 * Web (reminder_cron_key):
 *   /cron/rebuild-event-sheets.php?key=KEY&ids=1,14&process=3
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/google-sheets-queue.php';
require_once dirname(__DIR__) . '/includes/google-sheets-sync.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');
$opts  = $isCli ? getopt('', ['key::', 'ids:', 'process::']) : [];

try {
    $pdo = getDB();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
    exit(1);
}

$key = trim((string) ($opts['key'] ?? $_GET['key'] ?? ''));
if (!$isCli) {
    $expected = trim(getSetting($pdo, 'reminder_cron_key', ''));
    if ($expected === '' || !hash_equals($expected, $key)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
}

$idsRaw = trim((string) ($opts['ids'] ?? $_GET['ids'] ?? ''));
$eventIds = array_values(array_unique(array_filter(array_map('intval', preg_split('/\s*,\s*/', $idsRaw) ?: []), static fn (int $id): bool => $id > 0)));

if ($eventIds === []) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'ids parameter required (comma-separated event IDs)']);
    exit;
}

$process = (int) ($opts['process'] ?? $_GET['process'] ?? 0);
$process = max(0, min(5, $process));

$queued = [];
foreach ($eventIds as $eventId) {
    if (googleSheetsEnqueueEventRebuild($pdo, $eventId, 'manual', null, true, 1)) {
        $queued[] = $eventId;
    }
}

$stats = $process > 0 ? googleSheetsProcessSyncQueue($pdo, $process) : null;
$queue = googleSheetsQueueSummary($pdo);

$payload = [
    'ok'      => true,
    'event_ids' => $eventIds,
    'newly_queued' => $queued,
    'processed' => $stats,
    'queue'   => $queue,
    'generated_at' => gmdate('c'),
];

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
