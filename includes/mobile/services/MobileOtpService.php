<?php

declare(strict_types=1);

require_once __DIR__ . '/../mobile-rate-limit.php';
require_once __DIR__ . '/../../mailer.php';
require_once __DIR__ . '/../../settings-repository.php';

function mobileOtpNormalizeEmail(string $email): string
{
    return strtolower(trim($email));
}

function emailOtpTtlSeconds(PDO $pdo, string $purpose): int
{
    $purpose = strtolower(trim($purpose));
    if ($purpose === 'staff_portal') {
        $raw = trim(getSetting($pdo, 'staff_portal_otp_ttl_seconds', '600'));
    } else {
        $raw = trim(getSetting($pdo, 'mobile_email_otp_ttl_seconds', '600'));
    }

    if ($raw === '' || !ctype_digit($raw)) {
        return 600;
    }

    $ttl = (int) $raw;

    return max(120, min(3600, $ttl));
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
    } elseif (getSetting($pdo, 'mobile_email_otp_enabled', '1') !== '1') {
        return ['ok' => false, 'message' => 'Email sign-in is disabled.', 'code' => 'OTP_DISABLED', 'status' => 503];
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

    $ttl     = emailOtpTtlSeconds($pdo, $purpose);
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

    $minutes  = max(1, (int) ceil($ttl / 60));
    $subject  = 'Olasentra — your sign-in code';
    $text     = "Your verification code is: {$code}\n\nThis code expires in {$minutes} minutes.\n\nIf you did not request this, ignore this email.";
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $html     = '<!DOCTYPE html><html><body style="font-family:Segoe UI,Arial,sans-serif;line-height:1.5;color:#111827;">'
        . '<p>Your verification code is: <strong>' . $safeCode . '</strong></p>'
        . '<p>This code expires in ' . $minutes . ' minutes.</p>'
        . '<p>If you did not request this, ignore this email.</p>'
        . '</body></html>';

    if (!sendEmail($pdo, $email, $subject, $text, $html)) {
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
        return ['ok' => false, 'message' => 'Invalid verification code.', 'code' => 'INVALID_OTP', 'status' => 401];
    }

    mobileThrottleOrFail('otp_verify_' . md5($email), 10, 600);

    $hash = hash('sha256', $code);

    $stmt = $pdo->prepare(
        'SELECT id, expires_at FROM mobile_email_otp_codes
         WHERE email = :email AND purpose = :purpose AND code_hash = :hash
           AND consumed_at IS NULL
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([
        'email'   => $email,
        'purpose' => $purpose,
        'hash'    => $hash,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || (int) ($row['id'] ?? 0) < 1) {
        return [
            'ok'      => false,
            'message' => 'Invalid verification code.',
            'code'    => 'INVALID_OTP',
            'status'  => 401,
        ];
    }

    $id = (int) $row['id'];
    if (strtotime((string) ($row['expires_at'] ?? '')) <= time()) {
        return [
            'ok'      => false,
            'message' => 'This verification code has expired. Request a new code.',
            'code'    => 'OTP_EXPIRED',
            'status'  => 401,
        ];
    }

    $pdo->prepare('UPDATE mobile_email_otp_codes SET consumed_at = NOW() WHERE id = :id')
        ->execute(['id' => (int) $id]);

    return ['ok' => true];
}
