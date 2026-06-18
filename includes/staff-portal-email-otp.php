<?php

declare(strict_types=1);

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/staff-portal-session.php';
require_once __DIR__ . '/staff-portal-remember.php';
require_once __DIR__ . '/staff-profile-gate.php';
require_once __DIR__ . '/mobile/services/MobileOtpService.php';
require_once __DIR__ . '/mobile/mobile-auth.php';

function isStaffPortalEmailOtpEnabled(PDO $pdo): bool
{
    return getSetting($pdo, 'staff_portal_email_otp_enabled', '1') === '1';
}

/**
 * @return array{ok: true, expires_in: int, resend_in: int}|array{ok: false, message: string, code: string, status: int}
 */
function staffPortalEmailOtpSend(PDO $pdo, string $email): array
{
    if (!isStaffPortalEmailOtpEnabled($pdo)) {
        return [
            'ok'      => false,
            'message' => 'Email sign-in is not available right now.',
            'code'    => 'OTP_DISABLED',
            'status'  => 503,
        ];
    }

    $email = mobileOtpNormalizeEmail($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [
            'ok'      => false,
            'message' => 'Enter a valid email address.',
            'code'    => 'INVALID_EMAIL',
            'status'  => 422,
        ];
    }

    $staff = getStaffByEmail($pdo, $email);
    if ($staff === null) {
        return [
            'ok'      => false,
            'message' => 'No approved staff profile found for this email. Use the same email on your staff record.',
            'code'    => 'STAFF_NOT_FOUND',
            'status'  => 404,
        ];
    }

    if (mobileStaffIsBlacklisted($pdo, $email, $staff)) {
        return [
            'ok'      => false,
            'message' => 'Access denied.',
            'code'    => 'BLACKLISTED',
            'status'  => 403,
        ];
    }

    return mobileOtpSend($pdo, $email, 'staff_portal');
}

/**
 * @return array{ok: true, redirect: string}|array{ok: false, message: string, code: string, status: int}
 */
function staffPortalEmailOtpVerifyAndLogin(PDO $pdo, string $email, string $code): array
{
    if (!isStaffPortalEmailOtpEnabled($pdo)) {
        return [
            'ok'      => false,
            'message' => 'Email sign-in is not available right now.',
            'code'    => 'OTP_DISABLED',
            'status'  => 503,
        ];
    }

    $email = mobileOtpNormalizeEmail($email);
    $code  = preg_replace('/\s+/', '', trim($code)) ?? '';

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [
            'ok'      => false,
            'message' => 'Enter a valid email address.',
            'code'    => 'INVALID_EMAIL',
            'status'  => 422,
        ];
    }

    $staff = getStaffByEmail($pdo, $email);
    if ($staff === null) {
        return [
            'ok'      => false,
            'message' => 'No approved staff profile found for this email.',
            'code'    => 'STAFF_NOT_FOUND',
            'status'  => 404,
        ];
    }

    if (mobileStaffIsBlacklisted($pdo, $email, $staff)) {
        return [
            'ok'      => false,
            'message' => 'Access denied.',
            'code'    => 'BLACKLISTED',
            'status'  => 403,
        ];
    }

    $verified = mobileOtpVerify($pdo, $email, $code, 'staff_portal');
    if (empty($verified['ok'])) {
        return [
            'ok'      => false,
            'message' => (string) ($verified['message'] ?? 'Verification failed.'),
            'code'    => (string) ($verified['code'] ?? 'INVALID_OTP'),
            'status'  => (int) ($verified['status'] ?? 401),
        ];
    }

    establishStaffPortalSessionWithRemember($pdo, $staff);
    $_SESSION['staff_profile_return'] = 'staff-app.php';

    return [
        'ok'       => true,
        'redirect' => staffPortalLandingUrl($pdo, $staff),
    ];
}
