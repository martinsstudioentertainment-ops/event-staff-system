<?php

declare(strict_types=1);

/**
 * Phase 11 — Auth & registration modernization static checks.
 * Run: php scripts/phase11-auth-registration-test.php
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

$easy = file_get_contents($root . '/includes/staff-app-easy.php') ?: '';
check(str_contains($easy, 'Welcome to Olasentra'), 'P11-01: welcome headline', $errors, $checks);
check(str_contains($easy, 'Manage shifts, messages, documents and work updates.'), 'P11-01: welcome subtitle', $errors, $checks);
check(str_contains($easy, 'es-v3-login--compact'), 'P11-01: compact login markup', $errors, $checks);
check(str_contains($easy, 'Sign in with Google'), 'P11-01: Google login preserved', $errors, $checks);
check(str_contains($easy, 'Sign in with Email Code (OTP)'), 'P11-01: OTP login preserved', $errors, $checks);
check(str_contains($easy, 'Create Account / Register'), 'P11-01: sign-up preserved', $errors, $checks);
check(!str_contains($easy, 'Staff sign-in'), 'P11-01: legacy headline removed', $errors, $checks);

$shell = file_get_contents($root . '/includes/staff-app-v3-shell.php') ?: '';
check(str_contains($shell, 'es-v3--login-compact'), 'P11-01: compact guest shell class', $errors, $checks);

$css = file_get_contents($root . '/assets/css/staff-app-v3.css') ?: '';
check(str_contains($css, '.es-v3-login--compact'), 'P11-01: compact login CSS', $errors, $checks);
check(str_contains($css, '.es-v3--login-compact .es-v3__main'), 'P11-01: auth anchored to top', $errors, $checks);

$index = file_get_contents($root . '/index.php') ?: '';
check(str_contains($index, 'registration-page--v3'), 'P11-02: registration v3 body class', $errors, $checks);
check(str_contains($index, 'registration-v3.css'), 'P11-02: registration v3 stylesheet', $errors, $checks);
check(str_contains($index, "data-theme=\"dark\""), 'P11-02: registration dark theme', $errors, $checks);
check(str_contains($index, "'#F58220'"), 'P11-02: registration brand theme color', $errors, $checks);
check(str_contains($index, 'action="submit.php"'), 'P11-02: registration submit preserved', $errors, $checks);
check(str_contains($index, 'renderRegistrationWizardShell'), 'P11-02: wizard shell preserved', $errors, $checks);

$regCss = file_get_contents($root . '/assets/css/registration-v3.css') ?: '';
check(str_contains($regCss, '--es-accent: #F58220'), 'P11-02: registration accent token', $errors, $checks);
check(str_contains($regCss, '--es-primary: #0B1020'), 'P11-02: registration background token', $errors, $checks);
check(str_contains($regCss, '.staff-auth-v3'), 'P11-03: token auth screen styling', $errors, $checks);

$messages = file_get_contents($root . '/staff-messages.php') ?: '';
check(str_contains($messages, 'staff-auth-v3'), 'P11-03: messages token view v3 class', $errors, $checks);
check(str_contains($messages, 'registration-v3.css'), 'P11-03: messages token view v3 CSS', $errors, $checks);
check(str_contains($messages, 'msg_lookup'), 'P11-03: message lookup logic preserved', $errors, $checks);

foreach ([
    'includes/staff-app-easy.php',
    'includes/staff-app-v3-shell.php',
    'index.php',
    'staff-messages.php',
] as $rel) {
    $path = $root . '/' . $rel;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    check($code === 0, "PHP lint: {$rel}", $errors, $checks);
}

echo "\nChecks: {$checks}, failures: " . count($errors) . "\n";
exit($errors === [] ? 0 : 1);
