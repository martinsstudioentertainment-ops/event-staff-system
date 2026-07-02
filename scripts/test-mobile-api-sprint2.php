<?php

declare(strict_types=1);

/**
 * Sprint 2 mobile API tests — run: php scripts/test-mobile-api-sprint2.php
 */

$root = dirname(__DIR__);
require_once $root . '/includes/mobile/services/MobileProfileService.php';
require_once $root . '/includes/mobile/services/MobileShiftService.php';
require_once $root . '/includes/mobile/mappers/MobileShiftMapper.php';

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

echo "Mobile API Sprint 2 tests\n\n";

echo "Profile PATCH validation\n";

$completeStaff = [
    'profile_completed'           => 1,
    'profile_reverify_required'   => 0,
    'first_name'                  => 'Jane',
    'surname'                     => 'Doe',
    'email'                       => 'jane.doe@example.com',
    'mobile'                      => '+353871234567',
    'full_address'                => '1 Example Street, Dublin',
    'eircode'                     => 'D01 AB12',
    'date_of_birth'               => '1990-01-15',
    'bank_iban'                   => 'IE29AIBK93115212345678',
    'psa_licence'                 => 'EM123456/00',
    'psa_expiry_date'             => '2026-12-01',
    'psa_front_image'             => 'uploads/psa/front.jpg',
    'psa_back_image'              => 'uploads/psa/back.jpg',
];

$r = mobileProfileValidatePatchBody(['mobile' => '+353871111111'], $completeStaff);
assertTrue($r['ok'] === true, 'allows mobile update for complete profile');
assertEquals('+353871111111', $r['data']['mobile'] ?? null, 'mobile in patch data');

$r = mobileProfileValidatePatchBody(['pps_number' => '1234567A'], $completeStaff);
assertTrue($r['ok'] === false && ($r['code'] ?? '') === 'FIELD_NOT_ALLOWED', 'blocks pps_number');

$r = mobileProfileValidatePatchBody(['bank_iban' => 'IE29AIBK93115212345678'], $completeStaff);
assertTrue($r['ok'] === false && ($r['code'] ?? '') === 'FIELD_NOT_ALLOWED', 'blocks bank_iban');

$r = mobileProfileValidatePatchBody(['psa_licence' => 'EM999999/99'], $completeStaff);
assertTrue($r['ok'] === false && ($r['code'] ?? '') === 'FIELD_NOT_ALLOWED', 'blocks psa_licence');

$r = mobileProfileValidatePatchBody(['first_name' => 'Hack'], $completeStaff);
assertTrue($r['ok'] === false && ($r['code'] ?? '') === 'FIELD_NOT_ALLOWED', 'blocks first_name');

$r = mobileProfileValidatePatchBody(['mobile' => 'invalid'], $completeStaff);
assertTrue($r['ok'] === false && ($r['code'] ?? '') === 'INVALID_MOBILE', 'invalid mobile rejected');

$reverifyStaff = array_merge($completeStaff, ['profile_reverify_required' => 1]);
assertTrue(!mobileProfileCanEditLimitedFields($reverifyStaff), 'cannot edit when reverify required');

$incompleteStaff = ['profile_completed' => 0, 'profile_reverify_required' => 0];
$r = mobileProfileValidatePatchBody(['mobile' => '+353871111111'], $incompleteStaff);
assertTrue($r['ok'] === false && ($r['code'] ?? '') === 'PROFILE_INCOMPLETE', 'blocks patch when onboarding incomplete');

echo "\nDocument summary\n";

$docs = mobileProfileSummarizeDocuments([
    ['label' => 'PSA Licence', 'expiry' => '2026-12-01', 'status' => 'valid'],
    ['label' => 'PSA Front', 'expiry' => '', 'status' => 'expiring'],
]);
assertEquals(2, $docs['total'], 'document total count');
assertEquals(1, $docs['valid'], 'valid document count');
assertEquals(1, $docs['expiring'], 'expiring document count');

echo "\nUpcoming shifts filter\n";

$rows = [
    ['id' => 1, 'status' => 'approved', 'event_date' => '2099-06-01', 'event_name' => 'Future Event'],
    ['id' => 2, 'status' => 'approved', 'event_date' => '2020-01-01', 'event_name' => 'Past Event'],
    ['id' => 3, 'status' => 'pending', 'event_date' => '2099-07-01', 'event_name' => 'Pending Event'],
];
$today = date('Y-m-d');
$upcomingRows = mobileShiftFilterRows($rows, 'upcoming', $today, '', '', 'Olasentra');
assertEquals(1, count($upcomingRows), 'only one upcoming approved shift');
assertEquals(1, (int) ($upcomingRows[0]['id'] ?? 0), 'upcoming shift registration id');

echo "\nShift mapper (waitlist — no DB)\n";

$pdo = new class extends PDO {
    public function __construct() {}
};
$staff = ['id' => 1, 'email' => 'test@example.com'];
$mapped = mobileMapShiftRow($pdo, [
    'record_type'           => 'waitlist',
    'waitlist_id'           => 9,
    'event_id'              => 7,
    'event_name'            => 'Test Event',
    'event_date'            => '2026-06-15',
    'status'                => 'waitlist',
    'event_start_time'      => '14:00:00',
    'event_end_time'        => '22:00:00',
    'event_location'        => 'Croke Park',
    'main_security_company' => 'SecureCo',
], $staff, 'Olasentra');
assertEquals(9, $mapped['waitlist_id'], 'maps waitlist_id');
assertEquals('Test Event', $mapped['event_name'], 'maps event_name');
assertEquals('waitlist', $mapped['shift_status'], 'waitlist shift_status');

echo "\n";
echo "Results: {$passed} passed, {$failed} failed\n";

exit($failed > 0 ? 1 : 0);
