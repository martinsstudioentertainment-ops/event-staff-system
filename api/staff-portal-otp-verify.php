<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
initSecureSession();

require_once dirname(__DIR__) . '/includes/staff-portal-email-otp.php';
require_once dirname(__DIR__) . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($body)) {
    $body = $_POST;
}

if (!verifyCsrf((string) ($body['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.', 'code' => 'CSRF_FAILED']);
    exit;
}

$email = mobileOtpNormalizeEmail((string) ($body['email'] ?? ''));
$code  = trim((string) ($body['code'] ?? ''));

try {
    $pdo    = getDB();
    $result = staffPortalEmailOtpVerifyAndLogin($pdo, $email, $code);

    if (empty($result['ok'])) {
        http_response_code((int) ($result['status'] ?? 401));
        echo json_encode([
            'ok'    => false,
            'error' => (string) ($result['message'] ?? 'Verification failed.'),
            'code'  => (string) ($result['code'] ?? 'INVALID_OTP'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'ok'       => true,
        'redirect' => (string) ($result['redirect'] ?? 'staff-app.php'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[EventStaff] staff-portal-otp-verify: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Verification failed.', 'code' => 'SERVER_ERROR']);
}
