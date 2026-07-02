<?php

declare(strict_types=1);

/**
 * Phase 12 — Registration HTTP 500 fix static checks.
 * Run: php scripts/phase12-registration-500-fix-test.php
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

$status = file_get_contents($root . '/status.php') ?: '';
check(str_contains($status, "require_once __DIR__ . '/includes/staff-portal-dashboard.php'"), 'P12-01: status.php includes staff-portal-dashboard.php', $errors, $checks);
check(str_contains($status, 'computeStaffStatusMetricsFromRows'), 'P12-01: status metrics call preserved', $errors, $checks);
check(str_contains($status, 'filterStaffStatusRows'), 'P12-01: status filter call preserved', $errors, $checks);
check(str_contains($status, 'renderStaffV3StatusPage'), 'P12-01: v3 status renderer preserved', $errors, $checks);

$dashboard = file_get_contents($root . '/includes/staff-portal-dashboard.php') ?: '';
check(str_contains($dashboard, 'function computeStaffStatusMetricsFromRows'), 'P12-01: computeStaffStatusMetricsFromRows defined', $errors, $checks);
check(str_contains($dashboard, 'function filterStaffStatusRows'), 'P12-01: filterStaffStatusRows defined', $errors, $checks);

$submit = file_get_contents($root . '/submit.php') ?: '';
check(str_contains($submit, 'getRegistrationStatusUrlAfterSave'), 'P12-02: submit redirects to status after save', $errors, $checks);
check(str_contains($submit, 'registrationFlushResponse'), 'P12-02: registration flush preserved', $errors, $checks);

$index = file_get_contents($root . '/index.php') ?: '';
check(str_contains($index, 'action="submit.php"'), 'P12-03: registration form action preserved', $errors, $checks);
check(str_contains($index, 'registration-page--v3'), 'P12-03: Phase 11 registration UI preserved', $errors, $checks);

exec('php -l ' . escapeshellarg($root . '/status.php') . ' 2>&1', $lintOut, $lintCode);
check($lintCode === 0, 'PHP lint: status.php', $errors, $checks);

echo "\nChecks: {$checks}, failures: " . count($errors) . "\n";
exit($errors === [] ? 0 : 1);
