<?php
/**
 * Public API — active events for registration form
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/events-repository.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

try {
    $events = getActiveEventsForFrontend(getDB());
    echo json_encode($events);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load events.']);
}
