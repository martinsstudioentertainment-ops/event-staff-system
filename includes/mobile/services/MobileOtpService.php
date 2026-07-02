<?php

declare(strict_types=1);

require_once __DIR__ . '/../mobile-rate-limit.php';
require_once __DIR__ . '/../../mailer.php';

function mobileOtpNormalizeEmail(string $email): string
{
    return strtolower(trim($email));
}

function mobileOtpEnsureSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS mobile_email_otp_codes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(150) NOT NULL,
            purpose VARCHAR(32) NOT NULL,
            code_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mobile_otp_email_purpose (email, purpose, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * @return array{ok: true, expires_in: int, resend_in: int}|array{ok: false, message: string, code: string, status: int}
 */
function mobileOtpSend(PDO $pdo, string $email, string $purpose): array
{
    mobileOtpEnsureSchema($pdo);

    $email = mobileOtpNormalizeEmail($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Invalid email.', 'code' => 'INVALID_EMAIL', 'status' => 422];
    }

    $purpose = strtolower(trim($purpose));
    if (!in_array($purpose, ['login', 'registration', 'password', 'staff_portal'], true)) {
        return ['ok' => false, 'message' => 'Invalid purpose.', 'code' => 'VALIDATION_ERROR', 'status' => 422];
    }

    if ($purpose === 'staff_portal') {
        require_once __DIR__ . '/../../staff-portal-email-otp.php';
        if (!isStaffPortalEmailOtpEnabled($pdo)) {
            return ['ok' => false, 'message' => 'Email sign-in is disabled.', 'code' => 'OTP_DISABLED', 'status' => 503];
        }
    } else {
        require_once __DIR__ . '/../../staff-google-oauth.php';
        $policy = getStaffAuthPolicy($pdo);
        $otpKey = $purpose === 'registration' ? 'registration_email_otp_enabled' : 'mobile_email_otp_enabled';
        if (empty($policy[$otpKey])) {
            return ['ok' => false, 'message' => 'Email sign-in is disabled.', 'code' => 'OTP_DISABLED', 'status' => 503];
        }
    }

    mobileThrottleOrFail('otp_send_' . md5($email), 5, 600);

    $stmt = $pdo->prepare(
        'SELECT created_at FROM mobile_email_otp_codes
         WHERE email = :email AND purpose = :purpose
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['email' => $email, 'purpose' => $purpose]);
    $lastCreated = $stmt->fetchColumn();
    if (is_string($lastCreated) && $lastCreated !== '') {
        $elapsed = time() - (int) strtotime($lastCreated);
        if ($elapsed < 60) {
            return [
                'ok'      => false,
                'message' => 'Please wait before requesting another code.',
                'code'    => 'RATE_LIMITED',
                'status'  => 429,
            ];
        }
    }

    $ttl = 600;
    if ($purpose === 'staff_portal') {
        $raw = trim(getSetting($pdo, 'staff_portal_otp_ttl_seconds', '600'));
        if ($raw !== '' && ctype_digit($raw)) {
            $ttl = max(120, min(3600, (int) $raw));
        }
    }

    $code    = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', time() + $ttl);

    $insert = $pdo->prepare(
        'INSERT INTO mobile_email_otp_codes (email, purpose, code_hash, expires_at)
         VALUES (:email, :purpose, :hash, :expires_at)'
    );
    $insert->execute([
        'email'      => $email,
        'purpose'    => $purpose,
        'hash'       => hash('sha256', $code),
        'expires_at' => $expires,
    ]);

    $minutes = max(1, (int) ceil($ttl / 60));
    $subject = 'Olasentra — your sign-in code';
    $text    = "Your verification code is: {$code}\n\nThis code expires in {$minutes} minutes.\n\nIf you did not request this, ignore this email.";

    require_once __DIR__ . '/../../email-layout.php';
    $intro = match ($purpose) {
        'staff_portal'  => 'Use this verification code to sign in to Olasentra:',
        'registration'  => 'Use this verification code to verify your email for event registration:',
        'password'      => 'Use this verification code to reset your password:',
        default         => 'Use this verification code to sign in to Olasentra:',
    };
    $html = buildEmailOtpContent($pdo, $code, $email, $minutes, $intro);

    if (!sendEmail($pdo, $email, $subject, $text, $html)) {
        error_log('[EventStaff] OTP send failed: purpose=' . $purpose . ' code=OTP_SEND_FAILED');

        return [
            'ok'      => false,
            'message' => 'Could not send verification email.',
            'code'    => 'OTP_SEND_FAILED',
            'status'  => 503,
        ];
    }

    return ['ok' => true, 'expires_in' => $ttl, 'resend_in' => 60];
}

/**
 * @return array{ok: true}|array{ok: false, message: string, code: string, status: int}
 */
function mobileOtpVerify(PDO $pdo, string $email, string $code, string $purpose): array
{
    mobileOtpEnsureSchema($pdo);

    $email = mobileOtpNormalizeEmail($email);
    $code  = preg_replace('/\s+/', '', trim($code)) ?? '';
    $purpose = strtolower(trim($purpose));

    if (!preg_match('/^\d{6}$/', $code)) {
        error_log('[EventStaff] OTP verify failed: purpose=' . $purpose . ' code=INVALID_OTP');

        return ['ok' => false, 'message' => 'Invalid verification code.', 'code' => 'INVALID_OTP', 'status' => 401];
    }

    mobileThrottleOrFail('otp_verify_' . md5($email), 10, 600);

    $stmt = $pdo->prepare(
        'SELECT id FROM mobile_email_otp_codes
         WHERE email = :email AND purpose = :purpose AND code_hash = :hash
           AND consumed_at IS NULL AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([
        'email'   => $email,
        'purpose' => $purpose,
        'hash'    => hash('sha256', $code),
    ]);
    $id = $stmt->fetchColumn();
    if ($id === false || (int) $id < 1) {
        error_log('[EventStaff] OTP verify failed: purpose=' . $purpose . ' code=INVALID_OTP');

        return [
            'ok'      => false,
            'message' => 'Invalid or expired verification code.',
            'code'    => 'INVALID_OTP',
            'status'  => 401,
        ];
    }

    $pdo->prepare('UPDATE mobile_email_otp_codes SET consumed_at = NOW() WHERE id = :id')
        ->execute(['id' => (int) $id]);

    return ['ok' => true];
}
