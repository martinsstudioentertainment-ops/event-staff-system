<?php

declare(strict_types=1);

/**
 * Phase 11B — OTP click-block fix static checks.
 * Run: php scripts/phase11b-otp-click-fix-test.php
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

$css = file_get_contents($root . '/assets/css/staff-app-v3.css') ?: '';
check(str_contains($css, '--es-guest-pwa-clearance'), 'P11B-01: guest PWA clearance token', $errors, $checks);
check(str_contains($css, '.es-v3--login-compact.es-v3--guest .es-v3__main'), 'P11B-01: guest compact bottom spacing', $errors, $checks);
check(str_contains($css, 'pointer-events: none'), 'P11B-01: banner click passthrough', $errors, $checks);
check(str_contains($css, '.es-v3__pwa-banner-btn'), 'P11B-01: install button stays clickable', $errors, $checks);
check(str_contains($css, 'scroll-margin-bottom'), 'P11B-01: OTP scroll margin', $errors, $checks);
check(str_contains($css, '.es-v3--pwa-banner-open'), 'P11B-01: banner-open body class spacing', $errors, $checks);
check(!preg_match('/\.es-v3--login-compact \.es-v3__main\s*\{[^}]*padding-bottom:\s*calc\(var\(--es-safe-b\) \+ 1rem\)/s', $css), 'P11B-01: removed insufficient 1rem bottom padding', $errors, $checks);

$v3js = file_get_contents($root . '/assets/js/staff-app-v3.js') ?: '';
check(str_contains($v3js, 'es-v3--pwa-banner-open'), 'P11B-01: banner open class toggle', $errors, $checks);
check(str_contains($v3js, 'es-v3-pwa-banner'), 'P11B-01: single install banner preserved', $errors, $checks);

$otpJs = file_get_contents($root . '/assets/js/staff-portal-email-otp.js') ?: '';
check(str_contains($otpJs, 'scrollIntoView'), 'P11B-02: OTP error scroll into view', $errors, $checks);
check(str_contains($otpJs, "getElementById('staff-portal-email-send')"), 'P11B-02: send button handler preserved', $errors, $checks);
check(str_contains($otpJs, 'postJson(sendUrl'), 'P11B-02: OTP send API call preserved', $errors, $checks);
check(!str_contains($otpJs, 'mobileOtpVerify'), 'P11B-02: no OTP verify logic changes', $errors, $checks);

$easy = file_get_contents($root . '/includes/staff-app-easy.php') ?: '';
check(str_contains($easy, 'staff-portal-email-send'), 'P11B-03: login OTP markup preserved', $errors, $checks);
check(str_contains($easy, 'Sign in with Google'), 'P11B-03: Google login preserved', $errors, $checks);

foreach ([
    'assets/css/staff-app-v3.css',
    'assets/js/staff-app-v3.js',
    'assets/js/staff-portal-email-otp.js',
] as $rel) {
    check(is_file($root . '/' . $rel), "File exists: {$rel}", $errors, $checks);
    check((filesize($root . '/' . $rel) ?: 0) > 0, "File non-empty: {$rel}", $errors, $checks);
}

echo "\nChecks: {$checks}, failures: " . count($errors) . "\n";
exit($errors === [] ? 0 : 1);
