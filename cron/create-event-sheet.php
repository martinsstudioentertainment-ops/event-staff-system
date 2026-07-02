<?php

declare(strict_types=1);

/**
 * One-time: create + link Google Sheet for one event.
 * DELETE after use or restrict to cron key.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/google-sheets-sync.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';

header('Content-Type: application/json; charset=utf-8');

$key = trim((string) ($_GET['key'] ?? ''));
if ($key !== 'email-encoding-verify-20260606') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$eventId = (int) ($_GET['event_id'] ?? 38);
if ($eventId < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid event_id']);
    exit;
}

try {
    $pdo   = getDB();
    $event = getEventById($pdo, $eventId);

    if ($event === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Event not found', 'event_id' => $eventId]);
        exit;
    }

    $existingUrl = trim((string) ($event['google_sheet_url'] ?? ''));
    if ($existingUrl !== '') {
        echo json_encode([
            'ok'        => true,
            'already'   => true,
            'event_id'  => $eventId,
            'event'     => (string) ($event['name'] ?? ''),
            'sheet_url' => $existingUrl,
            'tab'       => (string) ($event['google_sheet_tab'] ?? ''),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $result = createGoogleSheetForEvent($pdo, $eventId);

    if (!$result['ok']) {
        http_response_code(500);
        echo json_encode([
            'ok'       => false,
            'event_id' => $eventId,
            'event'    => (string) ($event['name'] ?? ''),
            'error'    => $result['message'] ?? 'Could not create sheet.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $updated = getEventById($pdo, $eventId);

    echo json_encode([
        'ok'        => true,
        'created'   => true,
        'event_id'  => $eventId,
        'event'     => (string) ($event['name'] ?? ''),
        'sheet_url' => (string) ($result['url'] ?? ($updated['google_sheet_url'] ?? '')),
        'tab'       => (string) ($updated['google_sheet_tab'] ?? ''),
        'message'   => $result['message'] ?? 'Google Sheet created and linked.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[EventStaff] create-event-sheet: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
