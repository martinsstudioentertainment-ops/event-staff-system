<?php

declare(strict_types=1);

require_once __DIR__ . '/MobileAuthService.php';
require_once __DIR__ . '/../mobile-auth.php';
require_once __DIR__ . '/../mobile-rate-limit.php';
require_once __DIR__ . '/../../staff-google-oauth.php';

/**
 * @return array{ok: true, access_token: string, refresh_token: string, expires_in: int, token_type: string, staff: array}|array{ok: false, message: string, code: string, status: int}
 */
function mobileGoogleAuthServiceLogin(PDO $pdo, array $body): array
{
    $idToken  = trim((string) ($body['id_token'] ?? ''));
    $deviceId = trim((string) ($body['device_id'] ?? ''));

    mobileAuthThrottle(mobileClientIp());

    if (!mobileValidateDeviceId($deviceId)) {
        return ['ok' => false, 'message' => 'Invalid device_id.', 'code' => 'INVALID_DEVICE', 'status' => 422];
    }

    if (!isStaffGoogleSigninEnabled($pdo)) {
        return ['ok' => false, 'message' => 'Google sign-in is not enabled.', 'code' => 'GOOGLE_DISABLED', 'status' => 403];
    }

    $verified = mobileVerifyGoogleIdToken($pdo, $idToken);
    if (!$verified['ok']) {
        return [
            'ok'      => false,
            'message' => (string) ($verified['message'] ?? 'Invalid Google token.'),
            'code'    => 'INVALID_GOOGLE_TOKEN',
            'status'  => 401,
        ];
    }

    $email = (string) ($verified['email'] ?? '');
    mobileAuthThrottle(mobileClientIp(), $email);

    $auth = authenticateStaffPortalByGoogleEmail($pdo, $email);
    if (!$auth['ok'] || empty($auth['staff'])) {
        return [
            'ok'      => false,
            'message' => (string) ($auth['message'] ?? 'Staff not found.'),
            'code'    => 'STAFF_NOT_ELIGIBLE',
            'status'  => 403,
        ];
    }

    $staff = $auth['staff'];
    if (mobileStaffIsBlacklisted($pdo, $email, $staff)) {
        return ['ok' => false, 'message' => 'Access denied.', 'code' => 'BLACKLISTED', 'status' => 403];
    }

    return mobileAuthServiceIssueTokens($pdo, $staff, $deviceId, $body['fcm_token'] ?? null);
}
