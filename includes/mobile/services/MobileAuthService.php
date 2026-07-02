<?php

declare(strict_types=1);

require_once __DIR__ . '/../mobile-auth.php';
require_once __DIR__ . '/../mobile-request.php';
require_once __DIR__ . '/../mobile-rate-limit.php';
require_once __DIR__ . '/../repositories/MobileTokenRepository.php';
require_once __DIR__ . '/../repositories/MobileFcmRepository.php';
require_once __DIR__ . '/../../staff-portal-session.php';
require_once __DIR__ . '/../../staff-google-oauth.php';
require_once __DIR__ . '/../../staff-repository.php';

/**
 * @return array{ok: true, access_token: string, refresh_token: string, expires_in: int, token_type: string, staff: array}|array{ok: false, message: string, code: string, status: int}
 */
function mobileAuthServiceIssueTokens(PDO $pdo, array $staff, string $deviceId, ?string $fcmToken = null): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    if ($staffId < 1) {
        return ['ok' => false, 'message' => 'Staff record invalid.', 'code' => 'STAFF_NOT_FOUND', 'status' => 404];
    }

    $accessToken  = mobileIssueAccessToken($pdo, $staff);
    $refreshToken = mobileCreateRefreshToken($pdo, $staffId, $deviceId, '', mobileUserAgent());

    if ($fcmToken !== null && trim($fcmToken) !== '') {
        mobileRegisterFcmToken($pdo, $staffId, $deviceId, trim($fcmToken));
    }

    return [
        'ok'            => true,
        'access_token'  => $accessToken,
        'refresh_token' => $refreshToken,
        'expires_in'    => mobileJwtAccessTtl($pdo),
        'token_type'    => 'Bearer',
        'staff'         => mobileStaffSummary($staff, $pdo),
    ];
}

/**
 * @return array{ok: true, access_token: string, refresh_token: string, expires_in: int, token_type: string, staff: array}|array{ok: false, message: string, code: string, status: int}
 */
function mobileAuthServiceLoginWithPps(PDO $pdo, array $body): array
{
    $email    = strtolower(trim((string) ($body['email'] ?? '')));
    $ppsLast4 = strtoupper(preg_replace('/\s+/', '', (string) ($body['pps_last4'] ?? '')));
    $deviceId = trim((string) ($body['device_id'] ?? ''));

    mobileAuthThrottle(mobileClientIp(), $email);

    if (!mobileValidateDeviceId($deviceId)) {
        return ['ok' => false, 'message' => 'Invalid device_id.', 'code' => 'INVALID_DEVICE', 'status' => 422];
    }

    $blocked = alternateStaffAuthBlockedByGoogleRequired($pdo);
    if ($blocked !== null) {
        return [
            'ok'      => false,
            'message' => $blocked['message'],
            'code'    => $blocked['code'],
            'status'  => $blocked['status'],
        ];
    }

    $policy = getStaffAuthPolicy($pdo);
    if (empty($policy['pps_signin_enabled'])) {
        error_log('[EventStaff] mobile PPS login blocked: pps_signin_enabled=0');

        return [
            'ok'      => false,
            'message' => 'PPS sign-in is not available. Use Google or email code.',
            'code'    => 'PPS_DISABLED',
            'status'  => 403,
        ];
    }

    $staff = authenticateStaffPortal($pdo, $email, $ppsLast4);
    if ($staff === null) {
        require_once __DIR__ . '/../../signin-display.php';

        return [
            'ok'      => false,
            'message' => getSigninMismatchMessage($pdo),
            'code'    => 'INVALID_CREDENTIALS',
            'status'  => 401,
        ];
    }

    if (mobileStaffIsBlacklisted($pdo, $email, $staff)) {
        return ['ok' => false, 'message' => 'Access denied.', 'code' => 'BLACKLISTED', 'status' => 403];
    }

    return mobileAuthServiceIssueTokens($pdo, $staff, $deviceId, $body['fcm_token'] ?? null);
}

/**
 * @return array{ok: true, access_token: string, refresh_token: string, expires_in: int, token_type: string}|array{ok: false, message: string, code: string, status: int}
 */
function mobileAuthServiceRefresh(PDO $pdo, array $body): array
{
    $refreshToken = trim((string) ($body['refresh_token'] ?? ''));
    $deviceId     = trim((string) ($body['device_id'] ?? ''));

    if ($refreshToken === '' || !mobileValidateDeviceId($deviceId)) {
        return ['ok' => false, 'message' => 'Invalid refresh request.', 'code' => 'INVALID_REFRESH_TOKEN', 'status' => 401];
    }

    mobileThrottleOrFail('refresh_' . md5($deviceId), 30, 60);

    $found = mobileFindRefreshToken($pdo, $refreshToken, $deviceId);
    if ($found === null) {
        return ['ok' => false, 'message' => 'Refresh token invalid or expired.', 'code' => 'INVALID_REFRESH_TOKEN', 'status' => 401];
    }

    $newRefresh = mobileRotateRefreshToken($pdo, $refreshToken, $deviceId);
    if ($newRefresh === null) {
        return ['ok' => false, 'message' => 'Could not rotate refresh token.', 'code' => 'TOKEN_REVOKED', 'status' => 401];
    }

    require_once __DIR__ . '/../../staff-repository.php';
    $staff = getStaffById($pdo, $found['staff_id']);
    if ($staff === null) {
        return ['ok' => false, 'message' => 'Staff not found.', 'code' => 'STAFF_NOT_FOUND', 'status' => 404];
    }

    return [
        'ok'            => true,
        'access_token'  => mobileIssueAccessToken($pdo, $staff),
        'refresh_token' => $newRefresh,
        'expires_in'    => mobileJwtAccessTtl($pdo),
        'token_type'    => 'Bearer',
    ];
}

/**
 * @return array{ok: true, message: string}|array{ok: false, message: string, code: string, status: int}
 */
function mobileAuthServiceLogout(PDO $pdo, array $body, ?array $staffFromBearer = null): array
{
    $refreshToken = trim((string) ($body['refresh_token'] ?? ''));
    $deviceId     = trim((string) ($body['device_id'] ?? ''));
    $revokeAll    = !empty($body['revoke_all_devices']);

    $staffId = (int) ($staffFromBearer['id'] ?? 0);

    if ($revokeAll && $staffId > 0) {
        mobileRevokeAllRefreshTokens($pdo, $staffId);
        mobileDeactivateAllFcmForStaff($pdo, $staffId);

        return ['ok' => true, 'message' => 'Signed out from all devices.'];
    }

    if ($refreshToken !== '' && mobileValidateDeviceId($deviceId)) {
        $found = mobileFindRefreshToken($pdo, $refreshToken, $deviceId);
        if ($found !== null) {
            mobileRevokeRefreshTokenById($pdo, $found['id']);
            $staffId = $found['staff_id'];
        }
    } elseif ($staffId > 0 && mobileValidateDeviceId($deviceId)) {
        mobileRevokeRefreshTokenForDevice($pdo, $staffId, $deviceId);
    }

    if ($staffId > 0 && mobileValidateDeviceId($deviceId)) {
        mobileUnregisterFcmToken($pdo, $staffId, $deviceId);
    }

    return ['ok' => true, 'message' => 'Signed out.'];
}
