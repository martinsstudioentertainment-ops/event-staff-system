<?php

declare(strict_types=1);

/**
 * Sprint 4 mobile API tests — run: php scripts/test-mobile-api-sprint4.php
 */

$root = dirname(__DIR__);
require_once $root . '/includes/mobile/services/MobileAttendanceService.php';
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

class MobileTestPdoStub extends PDO
{
    public function __construct()
    {
    }
}

echo "Mobile API Sprint 4 tests\n\n";

echo "GPS parsing\n";

assertTrue(mobileAttendanceParseGps(['sign_lat' => 53.35, 'sign_lng' => -6.26]) !== null, 'parses valid coordinates');
assertTrue(mobileAttendanceParseGps(['sign_lat' => 'bad', 'sign_lng' => -6.26]) === null, 'rejects invalid latitude');
assertTrue(mobileAttendanceParseGps([]) === null, 'rejects missing coordinates');

echo "\nVenue distance (haversine)\n";

require_once $root . '/includes/maps.php';
$distance = (int) round(haversineDistanceMeters(53.3500, -6.2600, 53.3501, -6.2601));
assertTrue($distance < 100, 'nearby coordinates within 100m');
$far = (int) round(haversineDistanceMeters(53.3500, -6.2600, 53.36, -6.27));
assertTrue($far > $distance, 'farther coordinates increase distance');

echo "\nLocation payload\n";

$pdo = new MobileTestPdoStub();
$locations = mobileAttendanceLocationPayload([
    'check_in_lat'        => 53.35,
    'check_in_lng'        => -6.26,
    'check_in_accuracy_m' => 8,
    'check_in_gps_at'     => '2026-06-12 14:00:00',
    'last_gps_lat'        => 53.3505,
    'last_gps_lng'        => -6.2605,
    'last_gps_accuracy_m' => 12,
    'last_gps_at'         => '2026-06-12 15:00:00',
]);
assertTrue($locations['check_in'] !== null, 'includes check-in location');
assertTrue($locations['last_known'] !== null, 'includes last known location');

echo "\nAuthorization / validation (no DB)\n";

$staff = ['id' => 5, 'email' => 'staff@example.com'];
$invalidReg = mobileAttendanceLoadOwnedRegistration($pdo, $staff, 0);
assertTrue($invalidReg['ok'] === false && ($invalidReg['code'] ?? '') === 'VALIDATION_ERROR', 'invalid registration id rejected');

assertTrue(!mobileShiftStaffOwnsRegistration(['email' => 'other@example.com'], $staff), 'foreign registration blocked');

echo "\n";
echo "Results: {$passed} passed, {$failed} failed\n";

exit($failed > 0 ? 1 : 0);
