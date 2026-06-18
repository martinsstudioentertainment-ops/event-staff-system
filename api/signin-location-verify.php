<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/signin-location-log.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

try {
    $pdo = getDB();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable.']);
    exit;
}

$token = trim((string) ($_POST['e'] ?? $_POST['signin_token'] ?? ''));
$gps   = parseSigninCoordinates($_POST);

if ($token === '' || $gps === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing event token or GPS coordinates.']);
    exit;
}

$result = recordSigninLocationVerification(
    $pdo,
    $token,
    (float) $gps['lat'],
    (float) $gps['lng'],
    $gps['accuracy_m'] ?? null
);

if (!$result['ok']) {
    echo json_encode([
        'ok'        => false,
        'error'     => $result['message'],
        'in_zone'   => false,
    ]);
    exit;
}

echo json_encode([
    'ok'              => true,
    'in_zone'         => true,
    'verification_id' => (int) $result['verification_id'],
    'message'         => $result['message'],
]);
