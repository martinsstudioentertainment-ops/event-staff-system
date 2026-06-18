<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/attendance-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('attendance');

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

$pdo       = getDB();
$scanRaw   = trim((string) ($_POST['scan_data'] ?? ''));
$eventId   = (int) ($_POST['event_id'] ?? 0);
$token     = parseCheckinTokenFromScan($scanRaw);

if ($token === null) {
    echo json_encode(['ok' => false, 'error' => 'Could not read a valid staff QR code.']);
    exit;
}

$row = getRegistrationByToken($pdo, $token);
if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'Staff pass not found.']);
    exit;
}

if ($eventId > 0 && (int) $row['event_id'] !== $eventId) {
    echo json_encode(['ok' => false, 'error' => 'This pass is for a different event.']);
    exit;
}

if ($row['status'] !== 'approved') {
    echo json_encode(['ok' => false, 'error' => 'Registration is not approved.']);
    exit;
}

if (hasCheckedIn($pdo, (int) $row['id'])) {
    echo json_encode([
        'ok'      => false,
        'error'   => 'Already checked in.',
        'already' => true,
        'name'    => trim($row['first_name'] . ' ' . $row['surname']),
    ]);
    exit;
}

$result = recordCheckin($pdo, (int) $row['id'], 'scan');
if ($result !== true) {
    echo json_encode(['ok' => false, 'error' => (string) $result]);
    exit;
}

logAdminAudit($pdo, 'scan_checkin', 'registration', (int) $row['id'], trim($row['first_name'] . ' ' . $row['surname']));

echo json_encode([
    'ok'    => true,
    'name'  => trim($row['first_name'] . ' ' . $row['surname']),
    'event' => formatEventLabel($row),
    'role'  => formatRoleLabel($row['staff_role']),
    'time'  => date('H:i'),
]);
