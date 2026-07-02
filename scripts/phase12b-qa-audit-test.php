<?php

declare(strict_types=1);

/**
 * Phase 12B — Production QA audit static checks.
 * Run: php scripts/phase12b-qa-audit-test.php
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

$otp = file_get_contents($root . '/includes/mobile/services/MobileOtpService.php') ?: '';
check(str_contains($otp, 'buildEmailOtpContent'), 'P12B-01: OTP uses branded email layout helper', $errors, $checks);
check(!str_contains($otp, '<!DOCTYPE html>'), 'P12B-01: OTP no longer bypasses email wrapper', $errors, $checks);
check(str_contains($otp, 'staff_portal'), 'P12B-01: staff portal OTP purpose preserved', $errors, $checks);

$layout = file_get_contents($root . '/includes/email-layout.php') ?: '';
check(str_contains($layout, 'function buildEmailOtpContent'), 'P12B-01: OTP email content helper present', $errors, $checks);
check(str_contains($layout, 'buildEmailMasterLayout'), 'P12B-01: master email layout present', $errors, $checks);
check(str_contains($layout, 'prefers-color-scheme:dark'), 'P12B-02: email dark mode CSS present', $errors, $checks);
check(str_contains($layout, 'email-container'), 'P12B-02: email mobile responsive CSS present', $errors, $checks);

$branding = file_get_contents($root . '/includes/email-branding.php') ?: '';
check(str_contains($branding, 'olasentra-email-banner.png'), 'P12B-02: dedicated email banner path', $errors, $checks);

$status = file_get_contents($root . '/status.php') ?: '';
check(str_contains($status, 'staff-portal-dashboard.php'), 'P12B-03: status metrics include preserved', $errors, $checks);
check(str_contains($status, 'renderStaffV3StatusPage'), 'P12B-03: v3 status renderer preserved', $errors, $checks);

$css = file_get_contents($root . '/assets/css/staff-app-v3.css') ?: '';
check(str_contains($css, 'Phase 12A'), 'P12B-04: status page v3 CSS deployed locally', $errors, $checks);
check(str_contains($css, 'es-v3__main'), 'P12B-04: v3 main safe-area padding', $errors, $checks);
check(str_contains($css, 'es-v3__chat-panel'), 'P12B-04: messages v3 styling present', $errors, $checks);

$shell = file_get_contents($root . '/includes/staff-app-easy.php') ?: '';
check(str_contains($shell, 'Sign in with Google'), 'P12B-05: Google login preserved', $errors, $checks);
check(str_contains($shell, 'Sign in with Email Code'), 'P12B-05: Email OTP login preserved', $errors, $checks);
check(str_contains($shell, 'Create Account') || str_contains($shell, 'Register'), 'P12B-05: Sign up preserved', $errors, $checks);

$lintFiles = [
    'includes/mobile/services/MobileOtpService.php',
    'includes/email-layout.php',
    'includes/admin-login-otp.php',
];
foreach ($lintFiles as $rel) {
    exec('php -l ' . escapeshellarg($root . '/' . $rel) . ' 2>&1', $lintOut, $lintCode);
    check($lintCode === 0, 'PHP lint: ' . $rel, $errors, $checks);
}

echo "\nChecks: {$checks}, failures: " . count($errors) . "\n";
exit($errors === [] ? 0 : 1);
