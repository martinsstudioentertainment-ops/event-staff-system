<?php



require_once dirname(__DIR__) . '/config.php';

initSecureSession();



require_once dirname(__DIR__) . '/includes/mobile/services/MobileOtpService.php';

require_once dirname(__DIR__) . '/includes/helpers.php';

require_once dirname(__DIR__) . '/includes/auth.php';



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

$code  = trim((string) ($body['code'] ?? ''));



try {

    $pdo = getDB();



    require_once dirname(__DIR__) . '/includes/staff-google-oauth.php';

    $blocked = alternateStaffAuthBlockedByGoogleRequired($pdo);

    if ($blocked !== null) {

        error_log('[EventStaff] registration-email-otp-verify: blocked code=' . (string) ($blocked['code'] ?? 'GOOGLE_REQUIRED'));

        http_response_code((int) $blocked['status']);

        echo json_encode([

            'ok'    => false,

            'error' => $blocked['message'],

            'code'  => $blocked['code'],

        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;

    }



    if (!getStaffAuthPolicy($pdo)['registration_email_otp_enabled']) {

        error_log('[EventStaff] registration-email-otp-verify: OTP disabled by policy');

        http_response_code(503);

        echo json_encode(['ok' => false, 'error' => 'Email verification is not available.', 'code' => 'OTP_DISABLED']);

        exit;

    }



    $result = mobileOtpVerify($pdo, $email, $code, 'registration');



    if (empty($result['ok'])) {

        http_response_code((int) ($result['status'] ?? 400));

        echo json_encode([

            'ok'    => false,

            'error' => (string) ($result['message'] ?? 'Verification failed.'),

            'code'  => (string) ($result['code'] ?? 'ERROR'),

        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;

    }



    setRegistrationVerifiedSession($email, 'email_otp');



    echo json_encode([

        'ok'         => true,

        'email'      => $email,

        'csrf_token' => csrfToken(),

    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {

    error_log('[EventStaff] registration-email-otp-verify: ' . $e->getMessage());

    http_response_code(500);

    echo json_encode(['ok' => false, 'error' => 'Could not verify code.']);

}

