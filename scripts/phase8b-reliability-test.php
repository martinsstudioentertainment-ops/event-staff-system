<?php

declare(strict_types=1);

/**
 * Phase 8B — Reliability fix static checks.
 * Run: php scripts/phase8b-reliability-test.php
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

$sw = file_get_contents($root . '/sw.js') ?: '';

check(
    str_contains($sw, "const CACHE_NAME = 'event-staff-v10-v3-staff-pwa'"),
    'P8-01: cache version bumped to v10',
    $errors,
    $checks
);
check(
    str_contains($sw, './assets/css/staff-app-v3.css'),
    'P8-01: precache staff-app-v3.css',
    $errors,
    $checks
);
check(
    str_contains($sw, './assets/css/notifications.css'),
    'P8-01: precache notifications.css',
    $errors,
    $checks
);
check(
    str_contains($sw, './assets/js/staff-app-v3.js'),
    'P8-01: precache staff-app-v3.js',
    $errors,
    $checks
);
check(
    str_contains($sw, './assets/js/staff-portal-email-otp.js'),
    'P8-01: precache staff-portal-email-otp.js',
    $errors,
    $checks
);
check(
    !str_contains($sw, './assets/css/staff-app.css'),
    'P8-01: legacy staff-app.css removed from precache',
    $errors,
    $checks
);
check(
    !str_contains($sw, './assets/css/staff-app-v2.css'),
    'P8-01: legacy staff-app-v2.css removed from precache',
    $errors,
    $checks
);
check(
    str_contains($sw, './offline.php') && str_contains($sw, './staff-app.php'),
    'P8-01: core shell pages still precached',
    $errors,
    $checks
);

foreach ([
    'assets/css/staff-app-v3.css',
    'assets/css/notifications.css',
    'assets/js/staff-app-v3.js',
    'assets/js/staff-portal-email-otp.js',
    'offline.php',
] as $rel) {
    check(is_file($root . '/' . $rel), "P8-01: precache target exists: {$rel}", $errors, $checks);
}

$pages = file_get_contents($root . '/includes/staff-app-v3-pages.php') ?: '';
check(
    str_contains($pages, 'Application status'),
    'P8-04: home action label renamed',
    $errors,
    $checks
);
check(
    !str_contains($pages, 'View Roster'),
    'P8-04: old View Roster label removed',
    $errors,
    $checks
);
check(
    str_contains($pages, "\$ctx['status_url']"),
    'P8-04: status_url route unchanged',
    $errors,
    $checks
);

$messages = file_get_contents($root . '/staff-messages.php') ?: '';
check(
    str_contains($messages, "header('Location: staff-app.php?return=' . urlencode('staff-messages.php'))"),
    'P8-05: guest redirect to staff-app login',
    $errors,
    $checks
);
check(
    str_contains($messages, '$useV3Shell = $portalStaff !== null && !$showLookup'),
    'P8-05: signed-in v3 shell gate preserved',
    $errors,
    $checks
);
check(
    preg_match('/\$token\s*!==\s*\'\'/', $messages) === 1,
    'P8-05: token-based access path preserved',
    $errors,
    $checks
);
check(
    str_contains($messages, 'renderStaffV3MessagesPage'),
    'P8-05: signed-in messages page preserved',
    $errors,
    $checks
);

foreach (['sw.js', 'staff-messages.php', 'includes/staff-app-v3-pages.php'] as $rel) {
    $path = $root . '/' . $rel;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    check($code === 0, "PHP/JS lint: {$rel}", $errors, $checks);
}

echo "\nChecks: {$checks}, failures: " . count($errors) . "\n";
exit($errors === [] ? 0 : 1);
