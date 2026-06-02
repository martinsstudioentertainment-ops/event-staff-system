<?php
require_once __DIR__ . '/../config.php';
initSecureSession();
require_once __DIR__ . '/../includes/pwa-push.php';
require_once __DIR__ . '/../includes/status-repository.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw !== false ? $raw : '', true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$endpoint = trim((string) ($data['endpoint'] ?? ''));
$keys     = $data['keys'] ?? [];
$p256dh   = trim((string) ($keys['p256dh'] ?? ''));
$auth     = trim((string) ($keys['auth'] ?? ''));

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing subscription fields']);
    exit;
}

$statusToken     = trim((string) ($data['status_token'] ?? ''));
$registrationId  = (int) ($data['registration_id'] ?? 0);
$pdo             = getDB();

if ($registrationId < 1 && $statusToken !== '') {
    $rows = getStaffStatusRows($pdo, $statusToken);
    if ($rows !== []) {
        $registrationId = (int) $rows[0]['id'];
    }
}

if ($registrationId < 1 && $statusToken === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Status token or registration required']);
    exit;
}

$ok = savePushSubscription(
    $pdo,
    $endpoint,
    $p256dh,
    $auth,
    $registrationId > 0 ? $registrationId : null,
    $statusToken !== '' ? $statusToken : null,
    substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)
);

if ($registrationId > 0 && $statusToken !== '') {
    linkPushSubscriptionsToRegistration($pdo, $statusToken, $registrationId);
}

echo json_encode(['ok' => $ok]);
