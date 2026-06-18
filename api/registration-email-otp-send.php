<?php

require_once dirname(__DIR__) . '/config.php';
initSecureSession();

require_once dirname(__DIR__) . '/includes/mobile/services/MobileOtpService.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($body)) {
    $body = $_POST;
}

$email = mobileOtpNormalizeEmail((string) ($body['email'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Enter a valid email address.']);
    exit;
}

try {
    $pdo    = getDB();
    $result = mobileOtpSend($pdo, $email, 'registration');

    if (empty($result['ok'])) {
        http_response_code((int) ($result['status'] ?? 400));
        echo json_encode([
            'ok'    => false,
            'error' => (string) ($result['message'] ?? 'Could not send code.'),
            'code'  => (string) ($result['code'] ?? 'ERROR'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'ok'         => true,
        'expires_in' => (int) ($result['expires_in'] ?? 0),
        'resend_in'  => (int) ($result['resend_in'] ?? 0),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[EventStaff] registration-email-otp-send: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not send verification code.']);
}
