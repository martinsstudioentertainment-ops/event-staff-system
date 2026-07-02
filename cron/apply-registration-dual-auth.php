<?php

declare(strict_types=1);

/**
 * One-time: enable Google + email OTP for registration (settings DB).
 * DELETE after use or restrict to cron key.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/staff-google-oauth.php';

header('Content-Type: application/json; charset=utf-8');

$key = trim((string) ($_GET['key'] ?? ''));
if ($key !== 'email-encoding-verify-20260606') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

try {
    $pdo = getDB();
    saveSettings($pdo, [
        'staff_google_signin_enabled'  => '1',
        'staff_google_signin_required' => '0',
        'mobile_email_otp_enabled'     => '1',
    ]);
    clearSettingsCache();
    $policy = getStaffAuthPolicy($pdo);
    echo json_encode([
        'ok'     => true,
        'policy' => $policy,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[EventStaff] apply-registration-dual-auth: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not update settings.']);
}
