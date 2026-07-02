<?php

declare(strict_types=1);

/**
 * Phase 10 — High impact usability static checks.
 * Run: php scripts/phase10-usability-test.php
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

$manifest = file_get_contents($root . '/manifest.php') ?: '';
check(str_contains($manifest, "'#F58220'"), 'P10-01: manifest theme_color #F58220', $errors, $checks);
check(str_contains($manifest, "'#0B1020'"), 'P10-01: manifest background_color #0B1020', $errors, $checks);
check(!str_contains($manifest, 'getThemeColor($pdo)'), 'P10-01: manifest no admin theme drift', $errors, $checks);

$pwaInstall = file_get_contents($root . '/assets/js/pwa-install.js') ?: '';
check(
    str_contains($pwaInstall, "data-staff-app-v3') === '1'"),
    'P10-02: legacy install skipped on v3 pages',
    $errors,
    $checks
);

$v3js = file_get_contents($root . '/assets/js/staff-app-v3.js') ?: '';
check(str_contains($v3js, 'es-v3-pwa-banner'), 'P10-02: v3 single install banner handler', $errors, $checks);
check(!str_contains($v3js, 'staff-app-install-btn'), 'P10-02: no legacy install btn fallback', $errors, $checks);
check(!str_contains($v3js, 'es-v3__install-target'), 'P10-02: no duplicate install targets in JS', $errors, $checks);

$pages = file_get_contents($root . '/includes/staff-app-v3-pages.php') ?: '';
check(str_contains($pages, 'renderStaffV3StatusPage'), 'P10-03: status v3 renderer present', $errors, $checks);
check(str_contains($pages, 'Application status'), 'P10-03: status page title copy', $errors, $checks);
check(!str_contains($pages, 'es-v3__install-row'), 'P10-02: home install row removed', $errors, $checks);
check(!str_contains($pages, 'id="staff-app-install-btn"'), 'P10-02: profile install row removed', $errors, $checks);
check(str_contains($pages, 'renderStaffV3ProfileEditPage'), 'P10-04: profile edit v3 renderer present', $errors, $checks);

$status = file_get_contents($root . '/status.php') ?: '';
check(str_contains($status, 'renderStaffV3PageStart'), 'P10-03: status.php uses v3 shell', $errors, $checks);
check(str_contains($status, 'renderStaffV3StatusPage'), 'P10-03: status.php uses v3 renderer', $errors, $checks);
check(!str_contains($status, 'staff-public-shell'), 'P10-03: status.php legacy shell removed', $errors, $checks);
check(str_contains($status, 'status_lookup'), 'P10-03: status lookup logic preserved', $errors, $checks);
check(str_contains($status, 'status_psa_update'), 'P10-03: status PSA logic preserved', $errors, $checks);

$profile = file_get_contents($root . '/staff-profile.php') ?: '';
check(str_contains($profile, 'renderStaffV3ProfileEditPage'), 'P10-04: staff-profile.php uses v3 renderer', $errors, $checks);
check(str_contains($profile, 'validateStaffOnboardingPost'), 'P10-04: profile validation logic preserved', $errors, $checks);
check(str_contains($profile, 'updateStaffProfile'), 'P10-04: profile save logic preserved', $errors, $checks);
check(!str_contains($profile, 'staff-profile-v2.css'), 'P10-04: legacy profile CSS link removed', $errors, $checks);

$shell = file_get_contents($root . '/includes/staff-app-v3-shell.php') ?: '';
check(str_contains($shell, 'es-v3-pwa-banner'), 'P10-02: v3 install banner in shell', $errors, $checks);
check(!str_contains($shell, 'pwa-install.css'), 'P10-02: legacy pwa-install.css removed from v3 shell', $errors, $checks);

$css = file_get_contents($root . '/assets/css/staff-app-v3.css') ?: '';
check(str_contains($css, '.es-v3 .status-dash__metric'), 'P10-03: status dashboard v3 CSS', $errors, $checks);
check(str_contains($css, '.es-v3__payroll-note'), 'P10-04: profile form v3 CSS', $errors, $checks);

$otp = file_get_contents($root . '/includes/mobile/services/MobileOtpService.php') ?: '';
check(
    str_contains($otp, '<!DOCTYPE html>'),
    'P10-05: staff portal OTP uses standalone HTML (no layout footer bug)',
    $errors,
    $checks
);

foreach ([
    'manifest.php',
    'status.php',
    'staff-profile.php',
    'includes/staff-app-v3-pages.php',
    'includes/staff-app-v3-shell.php',
] as $rel) {
    $path = $root . '/' . $rel;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    check($code === 0, "PHP lint: {$rel}", $errors, $checks);
}

echo "\nChecks: {$checks}, failures: " . count($errors) . "\n";
exit($errors === [] ? 0 : 1);
