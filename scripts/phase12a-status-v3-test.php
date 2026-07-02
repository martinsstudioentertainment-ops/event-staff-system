<?php

declare(strict_types=1);

/**
 * Phase 12A — Application Status page v3 completion static checks.
 * Run: php scripts/phase12a-status-v3-test.php
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
check(str_contains($css, 'Phase 12A'), 'P12A-01: Phase 12A CSS block present', $errors, $checks);
check(str_contains($css, '.status-dash__metric-grid'), 'P12A-01: metric grid CSS present', $errors, $checks);
check(str_contains($css, '.es-v3__stats--status'), 'P12A-01: v3 stat card integration CSS', $errors, $checks);
check(str_contains($css, 'es-v3--status-page'), 'P12A-03: status page safe-area padding', $errors, $checks);
check(str_contains($css, '.status-dash__app-card'), 'P12A-02: application card CSS present', $errors, $checks);
check(str_contains($css, '.es-v3__status-meta-row'), 'P12A-02: status meta row CSS present', $errors, $checks);

$component = file_get_contents($root . '/includes/components/staff-status-dashboard.php') ?: '';
check(str_contains($component, 'es-v3__stat-card'), 'P12A-01: metric cards use v3 stat-card classes', $errors, $checks);
check(str_contains($component, 'es-v3__badge'), 'P12A-02: status badges use v3 badge classes', $errors, $checks);
check(str_contains($component, 'es-ds__btn'), 'P12A-02: check-in button uses v3 btn classes', $errors, $checks);
check(str_contains($component, 'es-v3__shift-card'), 'P12A-02: application cards use v3 shift-card pattern', $errors, $checks);
check(str_contains($component, 'buildStaffStatusPageUrl'), 'P12A-04: filter URL logic preserved', $errors, $checks);
check(str_contains($component, 'resolveStaffShiftOutcomeMeta'), 'P12A-04: shift outcome logic preserved', $errors, $checks);
check(str_contains($component, 'ensureCheckinToken'), 'P12A-04: check-in token logic preserved', $errors, $checks);
check(!str_contains($component, 'badge badge--'), 'P12A-04: legacy badge classes removed', $errors, $checks);
check(!str_contains($component, 'btn btn--primary'), 'P12A-04: legacy btn classes removed', $errors, $checks);

$pages = file_get_contents($root . '/includes/staff-app-v3-pages.php') ?: '';
check(str_contains($pages, 'es-v3__status-page'), 'P12A-03: status page wrapper present', $errors, $checks);
check(str_contains($pages, 'renderStaffStatusMetricsDashboard'), 'P12A-04: metrics renderer call preserved', $errors, $checks);
check(str_contains($pages, 'renderStaffStatusApplicationsList'), 'P12A-04: applications list call preserved', $errors, $checks);

$shell = file_get_contents($root . '/includes/staff-app-v3-shell.php') ?: '';
check(str_contains($shell, "body_class"), 'P12A-03: shell supports body_class', $errors, $checks);

$status = file_get_contents($root . '/status.php') ?: '';
check(str_contains($status, "es-v3--status-page"), 'P12A-03: status.php sets status page body class', $errors, $checks);
check(str_contains($status, 'computeStaffStatusMetricsFromRows'), 'P12A-04: status metrics logic unchanged', $errors, $checks);
check(str_contains($status, 'filterStaffStatusRows'), 'P12A-04: status filter logic unchanged', $errors, $checks);

$lintFiles = [
    'status.php',
    'includes/components/staff-status-dashboard.php',
    'includes/staff-app-v3-pages.php',
    'includes/staff-app-v3-shell.php',
];
foreach ($lintFiles as $rel) {
    exec('php -l ' . escapeshellarg($root . '/' . $rel) . ' 2>&1', $lintOut, $lintCode);
    check($lintCode === 0, 'PHP lint: ' . $rel, $errors, $checks);
}

echo "\nChecks: {$checks}, failures: " . count($errors) . "\n";
exit($errors === [] ? 0 : 1);
