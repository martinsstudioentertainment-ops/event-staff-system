<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/event-signin-export.php';
require_once dirname(__DIR__) . '/includes/registration-bib.php';

header('Content-Type: application/json; charset=UTF-8');

$key = trim((string) ($_GET['key'] ?? ''));
$pdo = getDB();
if (!productionHealthAuthorize($pdo, $key)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

$eventId = (int) ($_GET['event_id'] ?? 11);

try {
    $event = getEventById($pdo, $eventId);
    $roster = getContractorSheetRosterRows($pdo, $eventId);
    $download = getContractorSheetSignInRows($pdo, $eventId);

    echo json_encode([
        'ok' => true,
        'event_id' => $eventId,
        'event_name' => is_array($event) ? ($event['name'] ?? null) : null,
        'roster_count' => count($roster),
        'download_count' => count($download),
        'functions' => [
            'getContractorSheetRosterRows' => function_exists('getContractorSheetRosterRows'),
            'getContractorSheetSignInRows' => function_exists('getContractorSheetSignInRows'),
            'sendContractorSheetDownload' => function_exists('sendContractorSheetDownload'),
        ],
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'event_id' => $eventId,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_PRETTY_PRINT);
}
