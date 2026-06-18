<?php

require_once dirname(__DIR__) . '/config.php';
initSecureSession();

require_once dirname(__DIR__) . '/includes/mobile/mobile-auth.php';
require_once dirname(__DIR__) . '/includes/staff-google-oauth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

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

$idToken = trim((string) ($body['id_token'] ?? ''));

try {
    $pdo = getDB();

    if (!isStaffGoogleSigninEnabled($pdo)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Google sign-in is not enabled.']);
        exit;
    }

    $verified = mobileVerifyGoogleIdToken($pdo, $idToken);
    if (empty($verified['ok'])) {
        http_response_code(401);
        echo json_encode([
            'ok'    => false,
            'error' => (string) ($verified['message'] ?? 'Invalid Google token.'),
        ]);
        exit;
    }

    $email = normalizeRegistrationEmail((string) ($verified['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Could not read a valid Gmail address from Google.']);
        exit;
    }

    $_SESSION['registration_google_email']       = $email;
    $_SESSION['registration_google_verified_at'] = time();

    echo json_encode([
        'ok'         => true,
        'email'      => $email,
        'csrf_token' => csrfToken(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[EventStaff] registration-google-verify: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not verify Google account.']);
}
