<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/pwa-push.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pdo = getDB();
$publicKey = getVapidPublicKey($pdo);

if ($publicKey === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Push not configured']);
    exit;
}

echo json_encode(['ok' => true, 'publicKey' => $publicKey]);
