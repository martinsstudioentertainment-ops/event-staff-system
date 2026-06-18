<?php

declare(strict_types=1);

/**
 * Sprint 6 mobile API tests — run: php scripts/test-mobile-api-sprint6.php
 */

$root = dirname(__DIR__);
require_once $root . '/includes/mobile/mappers/MobileDocumentMapper.php';
require_once $root . '/includes/mobile/mappers/MobileAvailabilityMapper.php';
require_once $root . '/includes/mobile/services/MobileAvailabilityService.php';
require_once $root . '/includes/mobile/services/MobileOfflineSyncService.php';

$passed = 0;
$failed = 0;

function assertTrue(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS  {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}\n";
    }
}

function assertEquals(mixed $expected, mixed $actual, string $label): void
{
    assertTrue($expected === $actual, $label . ' (expected ' . json_encode($expected) . ', got ' . json_encode($actual) . ')');
}

class MobileTestPdoStub extends PDO
{
    public function __construct()
    {
    }
}

echo "Mobile API Sprint 6 tests\n\n";

echo "Document mapping\n";

$staff = [
    'id'               => 1,
    'psa_licence'      => 'PSA-12345',
    'psa_expiry_date'  => '2027-01-15',
    'psa_front_image'  => '/uploads/psa/psa_front_1_test.jpg',
    'psa_back_image'   => '',
];

$licence = mobileMapDocumentItem('psa_licence', $staff);
assertEquals('psa_licence', $licence['key'], 'licence key');
assertEquals('compliance', $licence['category'], 'licence category');
assertTrue($licence['has_file'] === false, 'licence has no file');

$front = mobileMapDocumentItem('psa_front', $staff);
assertTrue($front['has_file'] === true, 'front image has file');
assertTrue(str_contains((string) $front['view_url'], '/documents/psa_front/file'), 'front view_url');

assertTrue(!mobileDocumentIsValidKey('secret_doc'), 'invalid document key rejected');
assertTrue(mobileDocumentIsValidKey('psa_back'), 'valid document key accepted');

echo "\nAvailability mapping\n";

assertEquals('preferred', mobileAvailabilityFromDbStatus('preferred')['status'], 'preferred status');
assertEquals('leave', mobileAvailabilityFromDbStatus('leave_requested')['status'], 'leave pending');
assertEquals('pending', mobileAvailabilityFromDbStatus('leave_requested')['approval_status'], 'leave approval pending');
assertEquals('holiday', mobileAvailabilityFromDbStatus('holiday_approved')['status'], 'holiday approved');

assertEquals('preferred', mobileAvailabilityToDbStatus('preferred'), 'maps preferred to db');
assertEquals(null, mobileAvailabilityToDbStatus('leave'), 'leave not settable via PUT');
assertEquals('leave_requested', mobileLeaveTypeToDbStatus('leave'), 'leave type mapping');

$day = mobileMapAvailabilityDay([
    'avail_date'     => '2026-06-15',
    'status'         => 'preferred',
    'notes'          => 'Morning only',
    'admin_approved' => 0,
    'updated_at'     => '2026-06-12 10:00:00',
]);
assertEquals('2026-06-15', $day['date'], 'maps availability date');
assertEquals('preferred', $day['status'], 'maps preferred day');

echo "\nAvailability validation (no DB)\n";

$pdo = new MobileTestPdoStub();
$staffAuth = ['id' => 5, 'email' => 'staff@example.com'];

$past = mobileAvailabilityServiceSetDay($pdo, $staffAuth, '2020-01-01', ['status' => 'available']);
assertTrue($past['ok'] === false && ($past['code'] ?? '') === 'VALIDATION_ERROR', 'past date rejected');

$badStatus = mobileAvailabilityServiceSetDay($pdo, $staffAuth, '2099-06-15', ['status' => 'leave']);
assertTrue($badStatus['ok'] === false && ($badStatus['code'] ?? '') === 'VALIDATION_ERROR', 'leave via PUT rejected');

$badMonth = mobileAvailabilityServiceGetMonth($pdo, $staffAuth, ['month' => 'bad']);
assertTrue($badMonth['ok'] === false, 'bad month format rejected');

$leaveBad = mobileAvailabilityServiceLeave($pdo, $staffAuth, ['date' => '2099-06-20', 'type' => 'vacation']);
assertTrue($leaveBad['ok'] === false && ($leaveBad['code'] ?? '') === 'VALIDATION_ERROR', 'invalid leave type rejected');

echo "\nOffline sync validation (no DB)\n";

assertTrue(mobileOfflineValidateClientId('offline-action-001'), 'valid client id');
assertTrue(!mobileOfflineValidateClientId('short'), 'invalid client id rejected');
assertEquals(6, count(mobileOfflineSupportedActions()), 'six offline actions supported');

$emptyItems = mobileOfflineSyncServiceProcess($pdo, $staffAuth, []);
assertTrue($emptyItems['ok'] === false && ($emptyItems['code'] ?? '') === 'VALIDATION_ERROR', 'missing items rejected');

$badItem = mobileOfflineSyncServiceProcess($pdo, $staffAuth, [
    'items' => [['client_id' => 'bad', 'action' => 'checkin', 'payload' => []]],
]);
assertTrue($badItem['ok'] === true && ($badItem['failed'] ?? 0) === 1, 'invalid client_id counts as failed');

$noStaffSync = mobileOfflineSyncServiceProcess($pdo, [], ['items' => []]);
assertTrue($noStaffSync['ok'] === false && ($noStaffSync['code'] ?? '') === 'FORBIDDEN', 'sync requires staff id');

echo "\n";
echo "Results: {$passed} passed, {$failed} failed\n";

exit($failed > 0 ? 1 : 0);
