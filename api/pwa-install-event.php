<?php

declare(strict_types=1);

/**
 * PWA install / device usage beacon — POST only.
 */

require_once __DIR__ . '/../config.php';
initSecureSession();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pwa-install-analytics.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

$pdo = getDB();

$raw  = file_get_contents('php://input');
$data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($data)) {
    $data = $_POST;
}

$csrf = (string) ($data['csrf_token'] ?? '');
if (!verifyCsrf($csrf !== '' ? $csrf : null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid session token.']);
    exit;
}

$eventType = trim((string) ($data['event'] ?? ''));
$payload   = [
    'visitor_key'  => (string) ($data['visitor_key'] ?? ''),
    'app_context'  => (string) ($data['app_context'] ?? 'staff'),
    'staff_email'  => (string) ($data['staff_email'] ?? ''),
    'display_mode' => (string) ($data['display_mode'] ?? ''),
    'user_agent'   => (string) ($data['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')),
];

$result = recordPwaInstallAnalyticsEvent($pdo, $eventType, $payload);

echo json_encode($result);
