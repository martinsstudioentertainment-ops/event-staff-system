<?php

declare(strict_types=1);

require_once __DIR__ . '/MobileOtpService.php';
require_once __DIR__ . '/MobileAuthService.php';
require_once __DIR__ . '/../../staff-repository.php';
require_once __DIR__ . '/../mobile-request.php';

/**
 * @return array{ok: true, expires_in: int, resend_in: int}|array{ok: false, message: string, code: string, status: int}
 */
function mobileEmailOtpAuthSend(PDO $pdo, array $body): array
{
    $email    = mobileOtpNormalizeEmail((string) ($body['email'] ?? ''));
    $purpose  = strtolower(trim((string) ($body['purpose'] ?? 'login')));
    $deviceId = trim((string) ($body['device_id'] ?? ''));

    if (!mobileValidateDeviceId($deviceId)) {
        return ['ok' => false, 'message' => 'Invalid device_id.', 'code' => 'INVALID_DEVICE', 'status' => 422];
    }

    if ($purpose === 'login') {
        $staff = getStaffByEmail($pdo, $email);
        if ($staff === null) {
            return [
                'ok'      => false,
                'message' => 'No approved staff profile found for this email. Use the same email on your staff record, or apply to join first.',
                'code'    => 'STAFF_NOT_FOUND',
                'status'  => 404,
            ];
        }
        if (mobileStaffIsBlacklisted($pdo, $email, $staff)) {
            return ['ok' => false, 'message' => 'Access denied.', 'code' => 'BLACKLISTED', 'status' => 403];
        }
    }

    return mobileOtpSend($pdo, $email, $purpose === 'registration' ? 'registration' : 'login');
}

/**
 * @return array{ok: true, access_token: string, refresh_token: string, expires_in: int, token_type: string, staff: array}|array{ok: false, message: string, code: string, status: int}
 */
function mobileEmailOtpAuthVerifyLogin(PDO $pdo, array $body): array
{
    $email    = mobileOtpNormalizeEmail((string) ($body['email'] ?? ''));
    $code     = trim((string) ($body['code'] ?? ''));
    $deviceId = trim((string) ($body['device_id'] ?? ''));

    if (!mobileValidateDeviceId($deviceId)) {
        return ['ok' => false, 'message' => 'Invalid device_id.', 'code' => 'INVALID_DEVICE', 'status' => 422];
    }

    $verified = mobileOtpVerify($pdo, $email, $code, 'login');
    if (empty($verified['ok'])) {
        return $verified;
    }

    $staff = getStaffByEmail($pdo, $email);
    if ($staff === null) {
        return [
            'ok'      => false,
            'message' => 'No staff profile found for this email.',
            'code'    => 'STAFF_NOT_FOUND',
            'status'  => 404,
        ];
    }

    if (mobileStaffIsBlacklisted($pdo, $email, $staff)) {
        return ['ok' => false, 'message' => 'Access denied.', 'code' => 'BLACKLISTED', 'status' => 403];
    }

    return mobileAuthServiceIssueTokens($pdo, $staff, $deviceId, $body['fcm_token'] ?? null);
}

/**
 * @return array{ok: true, message: string}|array{ok: false, message: string, code: string, status: int}
 */
function mobileEmailOtpAuthChangePassword(PDO $pdo, array $staff, array $body): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    $code    = trim((string) ($body['otp_code'] ?? ''));
    $current = (string) ($body['current_password'] ?? '');
    $new     = (string) ($body['new_password'] ?? '');

    if ($new === '') {
        return ['ok' => false, 'message' => 'Enter a new password.', 'code' => 'VALIDATION_ERROR', 'status' => 422];
    }

    $email = mobileOtpNormalizeEmail((string) ($staff['email'] ?? ''));

    if ($code !== '') {
        $verified = mobileOtpVerify($pdo, $email, $code, 'password');
        if (empty($verified['ok'])) {
            return $verified;
        }
    } elseif (mobileStaffHasAppPassword($pdo, $staffId)) {
        if ($current === '' || !mobileStaffVerifyAppPassword($pdo, $staffId, $current)) {
            return ['ok' => false, 'message' => 'Current password is incorrect.', 'code' => 'INVALID_PASSWORD', 'status' => 401];
        }
    } else {
        return [
            'ok'      => false,
            'message' => 'Request a verification code to set your app password.',
            'code'    => 'OTP_REQUIRED',
            'status'  => 422,
        ];
    }

    $error = mobileStaffSetAppPassword($pdo, $staffId, $new);
    if ($error !== null) {
        return ['ok' => false, 'message' => $error, 'code' => 'VALIDATION_ERROR', 'status' => 422];
    }

    return ['ok' => true, 'message' => 'Password updated successfully.'];
}

/**
 * @return array{ok: true, expires_in: int, resend_in: int}|array{ok: false, message: string, code: string, status: int}
 */
function mobileEmailOtpAuthSendPasswordCode(PDO $pdo, array $staff): array
{
    $email = mobileOtpNormalizeEmail((string) ($staff['email'] ?? ''));
    if ($email === '') {
        return ['ok' => false, 'message' => 'Staff email not found.', 'code' => 'STAFF_NOT_FOUND', 'status' => 404];
    }

    return mobileOtpSend($pdo, $email, 'password');
}
