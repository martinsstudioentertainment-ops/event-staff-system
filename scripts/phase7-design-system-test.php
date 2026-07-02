<?php

declare(strict_types=1);

/**
 * Phase 7 — Design system static checks.
 * Run: php scripts/phase7-design-system-test.php
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
check(str_contains($css, '.es-ds__btn--primary'), 'Design system: button primary', $errors, $checks);
check(str_contains($css, '.es-ds__empty'), 'Design system: empty state', $errors, $checks);
check(str_contains($css, '.es-v3__shift-banner'), 'Design system: shift banner', $errors, $checks);
check(str_contains($css, 'es-v3__pwa-banner'), 'PWA: single install banner CSS', $errors, $checks);
check(str_contains($css, 'focus-visible'), 'A11y: focus-visible rings', $errors, $checks);
check(str_contains($css, '.es-v3__shift-card--compact'), 'Shifts: compact card CSS', $errors, $checks);

$pages = file_get_contents($root . '/includes/staff-app-v3-pages.php') ?: '';
$shell = file_get_contents($root . '/includes/staff-app-v3-shell.php') ?: '';
check(str_contains($pages, 'es-ds__profile-hero'), 'Profile: design system hero', $errors, $checks);
check(str_contains($pages, 'es-ds__settings-row'), 'Settings: design system rows', $errors, $checks);
check(str_contains($pages, 'es-ds__doc-row'), 'Documents: design system rows', $errors, $checks);
check(str_contains($shell, 'es-v3-pwa-banner'), 'PWA: single install banner markup', $errors, $checks);
check(str_contains($pages, 'event details'), 'Messages: updated placeholder copy', $errors, $checks);

$shift = file_get_contents($root . '/includes/staff-portal-shift.php') ?: '';
check(str_contains($shift, 'es-v3__shift-banner'), 'Shift banner: v3 markup', $errors, $checks);
check(!str_contains($shift, 'staff-v2__alert'), 'Shift banner: no v2 class', $errors, $checks);

$offline = file_get_contents($root . '/offline.php') ?: '';
check(str_contains($offline, 'staff-app-v3.css'), 'Offline: v3 stylesheet', $errors, $checks);
check(str_contains($offline, 'es-ds__empty'), 'Offline: design system empty', $errors, $checks);

$js = file_get_contents($root . '/assets/js/staff-app-v3.js') ?: '';
check(str_contains($js, 'es-v3-pwa-banner'), 'PWA JS: single banner flow', $errors, $checks);
check(str_contains($js, 'appinstalled'), 'PWA JS: hide after install', $errors, $checks);
check(str_contains($shell, 'es-v3-pwa-banner'), 'PWA: install banner in shell', $errors, $checks);

$notifCss = file_get_contents($root . '/assets/css/notifications.css') ?: '';
check(str_contains($notifCss, 'var(--es-accent)'), 'Notifications: orange badge', $errors, $checks);

foreach ([
    'includes/staff-app-v3-pages.php',
    'includes/staff-portal-shift.php',
    'includes/components/notification-list.php',
    'offline.php',
] as $rel) {
    $path = $root . '/' . $rel;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    check($code === 0, "PHP lint: {$rel}", $errors, $checks);
}

echo "\nChecks: {$checks}, failures: " . count($errors) . "\n";
exit($errors === [] ? 0 : 1);
