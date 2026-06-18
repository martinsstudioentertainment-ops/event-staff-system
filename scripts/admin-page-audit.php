<?php
/**
 * Admin page audit — syntax, includes, and bootstrap smoke tests.
 * Run: php scripts/admin-page-audit.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$adminDir = $root . '/admin';
$errors = [];
$warnings = [];
$passed = 0;

function auditFail(array &$errors, string $msg): void
{
    $errors[] = $msg;
}

function auditWarn(array &$warnings, string $msg): void
{
    $warnings[] = $msg;
}

// 1. PHP syntax on all admin/*.php
$adminFiles = glob($adminDir . '/*.php') ?: [];
sort($adminFiles);
foreach ($adminFiles as $file) {
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        auditFail($errors, 'Syntax: ' . basename($file) . ' — ' . implode(' ', $out));
    } else {
        $passed++;
    }
}

// 2. Verify require_once targets exist
foreach ($adminFiles as $file) {
    $src = (string) file_get_contents($file);
    if (!preg_match_all('/require(?:_once)?\s+(?:__DIR__\s*\.\s*[\'"]([^\'"]+)[\'"]|dirname\([^)]+\)\s*\.\s*[\'"]([^\'"]+)[\'"])/', $src, $m, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($m as $match) {
        $rel = $match[1] !== '' ? $match[1] : $match[2];
        if (str_starts_with($rel, '/')) {
            continue;
        }
        $resolved = realpath(dirname($file) . '/' . $rel);
        if ($resolved === false || !is_file($resolved)) {
            auditFail($errors, 'Missing include in ' . basename($file) . ': ' . $rel);
        }
    }
}

// 3. system-health bootstrap (catches undefined functions without DB if possible)
$shFile = $root . '/includes/admin/system-health.php';
$shSrc = (string) file_get_contents($shFile);
$requiredFns = ['isGpsAttendanceV2Enabled', 'isFeatureEnabled', 'getAllFeatureFlagValues', 'getFeatureFlagAuditMetadata', 'getMailTransport', 'getSetting', 'ensureAttendanceGpsPhase1Schema', 'ensureNotificationCenterSchema'];
foreach ($requiredFns as $fn) {
    if (!preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(/', $shSrc)) {
        // function called in system-health but not defined in the file — must be loaded via require
        if (!preg_match('/' . preg_quote($fn, '/') . '\s*\(/', $shSrc)) {
            continue;
        }
    }
}

// Load system-health deps and verify functions exist
ob_start();
try {
    require_once $root . '/includes/admin/system-health.php';
    foreach (['isGpsAttendanceV2Enabled', 'summarizeSystemHealth', 'getSystemHealthChecks'] as $fn) {
        if (!function_exists($fn)) {
            auditFail($errors, 'system-health.php: undefined function ' . $fn . ' after includes');
        }
    }
} catch (Throwable $e) {
    auditFail($errors, 'system-health bootstrap: ' . $e->getMessage());
}
ob_end_clean();

// 4. Key includes used by admin layout
$layoutFiles = [
    'includes/admin/layout-top.php',
    'includes/admin/layout-bottom.php',
    'includes/admin/sidebar.php',
    'includes/admin/header-bar.php',
    'assets/css/admin.css',
    'assets/css/admin-v3.css',
];
foreach ($layoutFiles as $rel) {
    if (!is_file($root . '/' . $rel)) {
        auditFail($errors, 'Missing layout asset: ' . $rel);
    }
}

// 5. Sidebar nav targets — every linked admin/*.php should exist
$sidebar = (string) file_get_contents($root . '/includes/admin/sidebar.php');
if (preg_match_all('/[\'"]([a-z0-9_-]+\.php)[\'"]/', $sidebar, $navMatches)) {
    foreach (array_unique($navMatches[1]) as $navPhp) {
        if (!is_file($adminDir . '/' . $navPhp)) {
            auditWarn($warnings, 'Sidebar links to missing page: ' . $navPhp);
        }
    }
}

echo "Admin page audit\n";
echo str_repeat('=', 40) . "\n";
echo 'Syntax OK: ' . $passed . ' admin PHP files' . "\n";
echo 'Errors: ' . count($errors) . "\n";
echo 'Warnings: ' . count($warnings) . "\n\n";

if ($errors !== []) {
    echo "ERRORS:\n";
    foreach ($errors as $e) {
        echo "  ✗ $e\n";
    }
    echo "\n";
}
if ($warnings !== []) {
    echo "WARNINGS:\n";
    foreach ($warnings as $w) {
        echo "  ! $w\n";
    }
    echo "\n";
}

exit($errors === [] ? 0 : 1);
