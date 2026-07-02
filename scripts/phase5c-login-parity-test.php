<?php

declare(strict_types=1);

/**
 * Phase 5C — Staff PWA login parity (static regression checks).
 * Run: php scripts/phase5c-login-parity-test.php
 */

$root = dirname(__DIR__);
$errors = [];
$checks = 0;

function check(bool $ok, string $label, array &$errors, int &$checks): void
{
    $checks++;
    if (!$ok) {
        $errors[] = $label;
        echo "FAIL  {$label}\n";
        return;
    }
    echo "PASS  {$label}\n";
}

$requiredFiles = [
    'includes/staff-app-easy.php',
    'includes/staff-portal-email-otp.php',
    'api/staff-portal-otp-send.php',
    'api/staff-portal-otp-verify.php',
    'assets/js/staff-portal-email-otp.js',
];

foreach ($requiredFiles as $rel) {
    check(is_file($root . '/' . $rel), "File exists: {$rel}", $errors, $checks);
}

$easy = file_get_contents($root . '/includes/staff-app-easy.php') ?: '';
check(str_contains($easy, 'Sign in with Google'), 'Login UI: Google button copy', $errors, $checks);
check(str_contains($easy, 'Sign in with Email Code (OTP)'), 'Login UI: OTP section title', $errors, $checks);
check(str_contains($easy, 'staff-portal-email-otp'), 'Login UI: OTP root element', $errors, $checks);
check(str_contains($easy, 'Welcome to Olasentra'), 'Login UI: welcome headline', $errors, $checks);
check(str_contains($easy, 'Manage shifts, messages, documents and work updates.'), 'Login UI: welcome subtitle', $errors, $checks);
check(str_contains($easy, 'es-v3-login--compact'), 'Login UI: compact layout class', $errors, $checks);
check(!str_contains($easy, 'Staff sign-in'), 'Login UI: legacy sign-in headline removed', $errors, $checks);
check(str_contains($easy, 'Create Account / Register'), 'Login UI: sign-up link', $errors, $checks);
check(str_contains($easy, 'isStaffPortalEmailOtpEnabled'), 'Login UI: OTP visibility gate', $errors, $checks);
check(!str_contains($easy, 'staff_portal_verify'), 'Login UI: no PPS POST form', $errors, $checks);
check(!str_contains($easy, 'pps_last4'), 'Login UI: no PPS field', $errors, $checks);
check(!str_contains($easy, 'es-v3-login__pps'), 'Login UI: no PPS section', $errors, $checks);
check(!str_contains($easy, 'Sign in with email + PPS'), 'Login UI: no PPS copy', $errors, $checks);

$shell = file_get_contents($root . '/includes/staff-app-v3-shell.php') ?: '';
check(str_contains($shell, 'staff-portal-email-otp.js'), 'Shell: guest OTP script load', $errors, $checks);
check(str_contains($shell, 'staffV3OtpJsVersion'), 'Shell: OTP cache bust helper', $errors, $checks);

$css = file_get_contents($root . '/assets/css/staff-app-v3.css') ?: '';
check(str_contains($css, 'es-v3-login__otp'), 'CSS: OTP login styles', $errors, $checks);
check(str_contains($css, 'es-v3-login--compact'), 'CSS: compact login layout', $errors, $checks);
check(str_contains($css, 'es-v3--login-compact'), 'CSS: guest login compact shell', $errors, $checks);
check(str_contains($css, 'display-mode: standalone'), 'CSS: hide install in standalone', $errors, $checks);

$js = file_get_contents($root . '/assets/js/staff-app-v3.js') ?: '';
check(str_contains($js, 'es-v3-pwa-banner'), 'JS: single PWA banner flow', $errors, $checks);
check(str_contains($js, 'isStandalonePwa'), 'JS: hide install banner in standalone', $errors, $checks);

$otpJs = file_get_contents($root . '/assets/js/staff-portal-email-otp.js') ?: '';
check(str_contains($otpJs, 'staff-portal-email-otp'), 'OTP JS: root selector', $errors, $checks);

$otpService = file_get_contents($root . '/includes/mobile/services/MobileOtpService.php') ?: '';
check(str_contains($otpService, 'staff_portal'), 'MobileOtpService: staff_portal purpose wired', $errors, $checks);

foreach ([
    'includes/staff-app-easy.php',
    'includes/staff-portal-email-otp.php',
    'api/staff-portal-otp-send.php',
    'api/staff-portal-otp-verify.php',
    'includes/staff-app-v3-shell.php',
    'includes/mobile/services/MobileOtpService.php',
] as $rel) {
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        continue;
    }
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    check($code === 0, "PHP lint: {$rel}", $errors, $checks);
}

echo "\nChecks: {$checks}, failures: " . count($errors) . "\n";
exit($errors === [] ? 0 : 1);
