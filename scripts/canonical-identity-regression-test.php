<?php

declare(strict_types=1);

/**
 * Canonical Identity regression gate — run before every production deploy.
 *
 *   php scripts/canonical-identity-regression-test.php
 */

$root = dirname(__DIR__);
$errors = [];
$checks = 0;

function ciCheck(bool $ok, string $label, array &$errors, int &$checks): void
{
    $checks++;
    if (!$ok) {
        $errors[] = $label;
        echo "FAIL  {$label}\n";
        return;
    }
    echo "PASS  {$label}\n";
}

function ciRead(string $rel): string
{
    global $root;
    $path = $root . '/' . str_replace('\\', '/', $rel);

    return is_file($path) ? (file_get_contents($path) ?: '') : '';
}

$ci = ciRead('includes/platform/canonical-identity.php');
ciCheck($ci !== '', 'CI-01: canonical-identity.php exists', $errors, $checks);
ciCheck(str_contains($ci, "const CANONICAL_IDENTITY_VERSION = '1.0.0'"), 'CI-02: version constant', $errors, $checks);
ciCheck(str_contains($ci, 'function canonicalIdentityResolveStaff('), 'CI-03: resolve staff', $errors, $checks);
ciCheck(str_contains($ci, 'function canonicalIdentityPrepareRegistrationData('), 'CI-04: prepare registration', $errors, $checks);
ciCheck(str_contains($ci, 'function registrationExistsForStaffOnEvent('), 'CI-05: duplicate event registration guard', $errors, $checks);
ciCheck(str_contains($ci, 'function canonicalIdentityLogBypass('), 'CI-06: bypass logging', $errors, $checks);
ciCheck(str_contains($ci, 'function canonicalIdentityBackupRecordsBeforeRepair('), 'CI-07: repair backup', $errors, $checks);
ciCheck(str_contains($ci, 'function canonicalIdentityGetMonitoringDashboard('), 'CI-08: monitoring dashboard data', $errors, $checks);
ciCheck(str_contains($ci, 'function canonicalIdentitySendIntegrityAlerts('), 'CI-09: integrity alerts', $errors, $checks);
ciCheck(str_contains($ci, 'function canonicalIdentityRunE2eVerification('), 'CI-10: e2e verification', $errors, $checks);
ciCheck(!str_contains($ci, "UPDATE staff_registrations SET status = 'rejected'"), 'CI-11: nightly does not auto-reject manual review', $errors, $checks);

$validation = ciRead('includes/validation.php');
ciCheck(str_contains($validation, 'canonicalIdentityPrepareRegistrationData'), 'CI-20: saveRegistration uses canonical identity', $errors, $checks);
ciCheck(str_contains($validation, 'canonicalIdentityGatewayPush'), 'CI-21: registration gateway push', $errors, $checks);
ciCheck(str_contains($validation, 'duplicate_blocked'), 'CI-22: duplicate registration blocked', $errors, $checks);

$staffRepo = ciRead('includes/staff-repository.php');
ciCheck(str_contains($staffRepo, 'canonicalIdentityResolveStaff'), 'CI-30: findOrCreateStaff resolves canonical identity', $errors, $checks);
ciCheck(str_contains($staffRepo, 'change_staff_email'), 'CI-31: changeStaffEmail gateway', $errors, $checks);

$allocation = ciRead('includes/staff-allocation.php');
ciCheck(str_contains($allocation, 'canonicalIdentityEnforceOnRegistration'), 'CI-40: admin assign enforces identity', $errors, $checks);

$mobile = ciRead('includes/mobile/services/MobileEmailOtpAuthService.php');
ciCheck(str_contains($mobile, 'canonicalIdentityResolveStaffForLoginEmail'), 'CI-50: mobile alias login resolution', $errors, $checks);
ciCheck(str_contains($mobile, 'mobile_alias_login'), 'CI-51: mobile alias login audit', $errors, $checks);

$sheets = ciRead('apply/admin/includes/google-sheets-sync.php');
ciCheck(str_contains($sheets, 'staff_id'), 'CI-60: apply sheets uses staff_id', $errors, $checks);

$nightly = ciRead('cron/canonical-identity-nightly.php');
ciCheck(str_contains($nightly, 'canonicalIdentitySendIntegrityAlerts'), 'CI-70: nightly sends alerts', $errors, $checks);

$e2e = ciRead('cron/canonical-identity-e2e-verify.php');
ciCheck($e2e !== '', 'CI-71: e2e verify cron exists', $errors, $checks);

$dashboard = ciRead('admin/staff-identity-manager.php');
ciCheck($dashboard !== '', 'CI-72: Staff Identity Manager page exists', $errors, $checks);

$ui = ciRead('includes/platform/master-staff-identity-ui.php');
ciCheck($ui !== '' && str_contains($ui, 'masterStaffIdentityGetManagerData'), 'CI-74: Master Staff Identity UI layer', $errors, $checks);

$baseline = ciRead('docs/CANONICAL-IDENTITY-BASELINE.md');
ciCheck($baseline !== '' && str_contains($baseline, 'Master Staff Identity'), 'CI-73: Master Staff Identity baseline doc', $errors, $checks);

foreach ([
    'includes/platform/canonical-identity.php',
    'includes/validation.php',
    'includes/staff-repository.php',
    'cron/canonical-identity-nightly.php',
    'cron/canonical-identity-e2e-verify.php',
    'admin/staff-identity-manager.php',
    'includes/platform/master-staff-identity-ui.php',
    'scripts/canonical-identity-regression-test.php',
] as $rel) {
    $path = $root . '/' . $rel;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    ciCheck($code === 0, "PHP lint: {$rel}", $errors, $checks);
}

echo "\nMaster Staff Identity regression: {$checks} checks, " . count($errors) . " failure(s)\n";
exit($errors === [] ? 0 : 1);
