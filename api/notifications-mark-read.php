<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
initSecureSession();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-center.php';
require_once __DIR__ . '/../includes/status-repository.php';
require_once __DIR__ . '/../includes/staff-portal-session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$id       = (int) ($payload['id'] ?? 0);
$audience = trim((string) ($payload['audience'] ?? ''));

if ($id <= 0 || !in_array($audience, ['admin', 'staff'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request']);
    exit;
}

$pdo = getDB();

if ($audience === 'admin') {
    if (!isAdminLoggedIn()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $ok = markNotificationRead($pdo, $id, 'admin');
    echo json_encode(['ok' => $ok]);
    exit;
}

$email = '';
$token = trim((string) ($payload['token'] ?? ''));
if ($token !== '') {
    $rows = getStaffStatusRows($pdo, $token);
    if ($rows !== []) {
        $email = strtolower(trim((string) ($rows[0]['email'] ?? '')));
    }
}
if ($email === '') {
    $portalStaff = getStaffFromPortalSession($pdo);
    if ($portalStaff !== null) {
        $email = strtolower(trim((string) ($portalStaff['email'] ?? '')));
    }
}

if ($email === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$ok = markNotificationRead($pdo, $id, 'staff', $email);
echo json_encode(['ok' => $ok]);
