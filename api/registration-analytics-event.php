<?php

declare(strict_types=1);

/**
 * Registration funnel analytics beacon — POST only, feature-flag gated.
 */

require_once __DIR__ . '/../config.php';
initSecureSession();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/registration-analytics.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

$pdo = null;
try {
    $pdo = getDB();
} catch (Throwable $e) {
    $pdo = null;
}

if (!isFeatureEnabled($pdo, 'feature_registration_wizard_v2')) {
    echo json_encode(['ok' => false, 'message' => 'Analytics disabled.']);
    exit;
}

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
    'session_id' => (string) ($data['session_id'] ?? ''),
    'step'       => (int) ($data['step'] ?? 0),
    'last_step'  => (int) ($data['last_step'] ?? 0),
    'event_id'   => (int) ($data['event_id'] ?? 0),
    'event_name' => trim((string) ($data['event_name'] ?? '')),
    'form_slug'  => trim((string) ($data['form_slug'] ?? '')),
];

$result = recordRegistrationAnalyticsEvent($pdo, $eventType, $payload);

echo json_encode($result);
