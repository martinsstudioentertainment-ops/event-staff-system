<?php

/**
 * GPS heartbeat for venue sign-in page: pre-check activation + active geofence monitoring.
 * Auto sign-out when staff leave the event sign-in radius (per-event metres).
 */

require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    $pdo = getDB();
} catch (Throwable $e) {
    error_log('[EventStaff] attendance-gps-ping DB: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable.']);
    exit;
}

try {
    require_once dirname(__DIR__) . '/includes/attendance-repository.php';
    require_once dirname(__DIR__) . '/includes/attendance-gps-phase15.php';
    require_once dirname(__DIR__) . '/includes/attendance-gps-signout.php';
    require_once dirname(__DIR__) . '/includes/events-repository.php';

    if (!isGpsAttendanceV2Enabled($pdo)) {
        echo json_encode(['ok' => false, 'error' => 'GPS attendance is not enabled.']);
        exit;
    }

    $registrationId = (int) ($_POST['registration_id'] ?? 0);
    $eventId        = (int) ($_POST['event_id'] ?? 0);
    $checkinToken   = trim((string) ($_POST['checkin_token'] ?? ''));
    $gps            = parseSigninCoordinates($_POST);

    if ($registrationId <= 0 || $eventId <= 0 || $checkinToken === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
        exit;
    }

    $expectedToken = ensureCheckinToken($pdo, $registrationId);
    if ($expectedToken === null || !hash_equals($expectedToken, $checkinToken)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid check-in session.']);
        exit;
    }

    $attendance = getAttendanceByRegistration($pdo, $registrationId);
    if (!$attendance || (int) $attendance['event_id'] !== $eventId) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Attendance not found.']);
        exit;
    }

    $event = getEventById($pdo, $eventId);
    if (!$event) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Event not found.']);
        exit;
    }

    $result = processGpsAttendancePing($pdo, $attendance, $event, $gps);
    echo json_encode($result);
} catch (Throwable $e) {
    error_log('[EventStaff] attendance-gps-ping: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'GPS update failed.']);
}
