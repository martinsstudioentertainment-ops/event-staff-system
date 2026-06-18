<?php

declare(strict_types=1);

/**
 * Sprint 3 mobile API tests — run: php scripts/test-mobile-api-sprint3.php
 */

$root = dirname(__DIR__);
require_once $root . '/includes/mobile/mappers/MobileShiftStatus.php';
require_once $root . '/includes/mobile/mappers/MobileShiftMapper.php';
require_once $root . '/includes/mobile/services/MobileShiftService.php';

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

/** Minimal PDO stub for mapper functions that accept PDO but do not query in test paths. */
class MobileTestPdoStub extends PDO
{
    public function __construct()
    {
    }
}

echo "Mobile API Sprint 3 tests\n\n";

echo "Shift status resolution\n";

$pendingRow = ['status' => 'pending'];
assertEquals('pending', mobileResolveShiftStatus($pendingRow), 'pending registration');

$approvedRow = [
    'status'     => 'approved',
    'event_date' => date('Y-m-d', strtotime('+7 days')),
];
assertEquals('approved', mobileResolveShiftStatus($approvedRow), 'future approved shift');

$declinedRow = ['status' => 'approved', 'shift_response' => 'declined'];
assertEquals('declined', mobileResolveShiftStatus($declinedRow), 'staff declined response');

$rejectedRow = ['status' => 'rejected'];
assertEquals('declined', mobileResolveShiftStatus($rejectedRow), 'rejected maps to declined');

$waitlistRow = ['record_type' => 'waitlist', 'status' => 'waitlist'];
assertEquals('waitlist', mobileResolveShiftStatus($waitlistRow), 'waitlist record');

$cancelledRow = ['status' => 'approved', 'is_active' => 0, 'event_date' => date('Y-m-d', strtotime('+1 day'))];
assertEquals('cancelled', mobileResolveShiftStatus($cancelledRow), 'inactive event approved shift');

$completedRow = [
    'status'            => 'approved',
    'event_date'        => date('Y-m-d', strtotime('-1 day')),
    'attendance_id'     => 99,
    'is_checked_in'     => 1,
    'checked_out_at'    => '2026-01-01 22:00:00',
    'hours_worked'      => 8,
    'attendance_status' => 'active',
];
assertEquals('completed', mobileResolveShiftStatus($completedRow), 'completed with checkout');

$definitions = mobileShiftStatusDefinitions();
assertTrue(count($definitions) === 6, 'six status definitions documented');
assertTrue(isset($definitions['pending'], $definitions['completed']), 'definitions include pending and completed');

echo "\nShift list filtering\n";

$company = 'Olasentra';
$rows = [
    ['status' => 'approved', 'event_date' => '2099-06-01', 'event_name' => 'Future', 'main_security_company' => 'Alpha'],
    ['status' => 'approved', 'event_date' => '2020-01-01', 'event_name' => 'Past', 'main_security_company' => 'Alpha'],
    ['status' => 'pending', 'event_date' => '2099-07-01', 'event_name' => 'Pending Future', 'main_security_company' => 'Beta'],
];
$today = date('Y-m-d');
$upcoming = mobileShiftFilterRows($rows, 'upcoming', $today, '', '', $company);
assertEquals(1, count($upcoming), 'upcoming filter keeps one approved future shift');

$byEmployer = mobileShiftFilterRows($rows, 'all', $today, 'Beta', '', $company);
assertEquals(1, count($byEmployer), 'employer filter matches company label');

$bySearch = mobileShiftFilterRows($rows, 'all', $today, '', 'pending', $company);
assertEquals(1, count($bySearch), 'search filter matches event name');

echo "\nOwnership validation\n";

$staff = ['id' => 5, 'email' => 'staff@example.com'];
$owned = ['email' => 'staff@example.com', 'staff_id' => 5];
$foreign = ['email' => 'other@example.com', 'staff_id' => 99];
assertTrue(mobileShiftStaffOwnsRegistration($owned, $staff), 'owns by email');
assertTrue(!mobileShiftStaffOwnsRegistration($foreign, $staff), 'does not own foreign registration');

echo "\nRespond validation\n";

$invalidFilter = mobileShiftServiceList(new MobileTestPdoStub(), $staff, ['filter' => 'invalid']);
assertTrue($invalidFilter['ok'] === false && ($invalidFilter['code'] ?? '') === 'VALIDATION_ERROR', 'invalid list filter rejected');

$invalidRespond = mobileShiftServiceRespond(new MobileTestPdoStub(), $staff, 0, 'accepted');
assertTrue($invalidRespond['ok'] === false && ($invalidRespond['code'] ?? '') === 'VALIDATION_ERROR', 'invalid registration id rejected');

$badResponse = mobileShiftServiceRespond(new MobileTestPdoStub(), $staff, 1, 'maybe');
assertTrue($badResponse['ok'] === false && ($badResponse['code'] ?? '') === 'VALIDATION_ERROR', 'invalid response value rejected');

echo "\nShift mapper fields (waitlist — no DB)\n";

$pdo = new MobileTestPdoStub();
$mapped = mobileMapShiftRow($pdo, [
    'record_type'           => 'waitlist',
    'waitlist_id'           => 9,
    'event_id'              => 7,
    'event_name'            => 'Test Event',
    'event_date'            => date('Y-m-d', strtotime('+3 days')),
    'status'                => 'waitlist',
    'event_start_time'      => '14:00:00',
    'event_end_time'        => '22:00:00',
    'event_location'        => 'Croke Park',
    'main_security_company' => 'SecureCo',
], $staff, 'Olasentra');

assertEquals(9, $mapped['waitlist_id'], 'maps waitlist_id');
assertEquals('Test Event', $mapped['event_name'], 'maps event_name');
assertEquals('SecureCo', $mapped['assigned_company'], 'maps assigned company');
assertTrue(isset($mapped['check_in_eligibility'], $mapped['check_out_eligibility']), 'includes eligibility blocks');
assertEquals('waitlist', $mapped['shift_status'], 'shift_status waitlist');

echo "\n";
echo "Results: {$passed} passed, {$failed} failed\n";

exit($failed > 0 ? 1 : 0);
