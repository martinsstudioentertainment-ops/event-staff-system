<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance-repository.php';

requireAdminCapability('attendance');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$eventId = (int) ($_GET['event_id'] ?? 0);

try {
    $pdo = getDB();
    echo json_encode(getLiveAttendancePayload($pdo, $eventId), JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load attendance data.']);
}
