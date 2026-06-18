<?php

/**
 * GPS heartbeat for staff app session — geofence monitoring without keeping venue QR page open.
 */

require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

initSecureSession();

try {
    $pdo = getDB();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable.']);
    exit;
}

require_once dirname(__DIR__) . '/includes/staff-portal-session.php';
require_once dirname(__DIR__) . '/includes/staff-portal-remember.php';
require_once dirname(__DIR__) . '/includes/staff-portal-shift.php';
require_once dirname(__DIR__) . '/includes/attendance-gps-signout.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';

if (!isGpsAttendanceV2Enabled($pdo)) {
    echo json_encode(['ok' => false, 'error' => 'GPS attendance is not enabled.']);
    exit;
}

$staff = getStaffFromPortalSession($pdo);
if ($staff === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sign in to the staff app on this phone first.', 'auth_required' => true]);
    exit;
}

$email = strtolower(trim((string) ($staff['email'] ?? '')));
$shift = getStaffActiveShiftMonitoring($pdo, $email);
if ($shift === null) {
    echo json_encode(['ok' => false, 'error' => 'No active shift to monitor today.', 'monitoring' => false]);
    exit;
}

$gps = parseSigninCoordinates($_POST);
$attendance = getAttendanceByRegistration($pdo, (int) ($shift['registration_id'] ?? 0));
if (!$attendance) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Attendance not found.']);
    exit;
}

$event = getEventById($pdo, (int) ($shift['event_id'] ?? 0));
if (!$event) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Event not found.']);
    exit;
}

$result = processGpsAttendancePing($pdo, $attendance, $event, $gps);
$result['source'] = 'staff_app';

echo json_encode($result);
