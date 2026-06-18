<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
initSecureSession();
require_once __DIR__ . '/../includes/staff-portal-session.php';
require_once __DIR__ . '/../includes/platform/platform-schema.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$pdo   = getDB();
$staff = getStaffFromPortalSession($pdo);
if ($staff === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not signed in']);
    exit;
}

$raw  = (string) file_get_contents('php://input');
$data = json_decode($raw, true);
$items = is_array($data['items'] ?? null) ? $data['items'] : [];

ensurePlatformMaturitySchema($pdo);
$synced = 0;
$email  = strtolower(trim((string) ($staff['email'] ?? '')));

foreach ($items as $item) {
    $payload = $item['payload'] ?? $item;
    if (!is_array($payload)) {
        continue;
    }
    try {
        $stmt = $pdo->prepare("
            INSERT INTO platform_offline_checkins (registration_id, staff_email, payload_json, synced)
            VALUES (:reg_id, :email, :payload, 0)
        ");
        $stmt->execute([
            'reg_id'  => (int) ($payload['registration_id'] ?? 0),
            'email'   => $email,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $synced++;
    } catch (Throwable $e) {
        error_log('[EventStaff] offline sync: ' . $e->getMessage());
    }
}

echo json_encode(['ok' => true, 'synced' => $synced]);
