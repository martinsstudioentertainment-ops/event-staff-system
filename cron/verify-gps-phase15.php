<?php

/**
 * GPS Attendance Phase 1.5 verification (CLI or web with ?key= matching reminder_cron_key).
 *
 * Web: /cron/verify-gps-phase15.php?key=REMINDER_CRON_KEY
 * CLI: php cron/verify-gps-phase15.php
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/attendance-gps-phase15.php';
require_once dirname(__DIR__) . '/includes/maps.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');

if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');
    try {
        $pdo         = getDB();
        $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
        $providedKey = trim((string) ($_GET['key'] ?? ''));
        if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            http_response_code(403);
            echo "Forbidden\n";
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo "Database error\n";
        exit;
    }
}

$pdo    = getDB();
$pass   = 0;
$fail   = 0;
$checks = [];

function gpsPhase15Check(string $name, bool $ok, string $detail = ''): void
{
    global $checks, $pass, $fail;
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }
}

gpsPhase15Check('getGpsRequiredMessage()', getGpsRequiredMessage() !== '');
gpsPhase15Check('getGpsMaxAccuracyMeters default', getGpsMaxAccuracyMeters($pdo) >= 0);

$event = [
    'venue_lat'       => 53.3498,
    'venue_lng'       => -6.2603,
    'venue_eircode'   => 'D02 X285',
    'signin_radius_m' => 1000,
    'event_date'      => '2026-12-01',
    'start_time'      => '18:00:00',
];

$nullGps = validateGpsForCheckin($pdo, $event, null);
gpsPhase15Check('validateGps rejects null coords', !$nullGps['ok']);

$farGps = validateGpsForCheckin($pdo, $event, ['lat' => 52.0, 'lng' => -7.0, 'accuracy_m' => 10]);
gpsPhase15Check('validateGps rejects outside zone', !$farGps['ok']);

$nearGps = validateGpsForCheckin($pdo, $event, ['lat' => 53.3498, 'lng' => -6.2603, 'accuracy_m' => 15]);
gpsPhase15Check('validateGps accepts in-zone + accuracy', $nearGps['ok'], $nearGps['message']);

$badAcc = validateGpsForCheckin($pdo, $event, ['lat' => 53.3498, 'lng' => -6.2603, 'accuracy_m' => 500]);
gpsPhase15Check('validateGps rejects poor accuracy', !$badAcc['ok']);

$staleAttendance = [
    'check_in_lat'        => 53.3498,
    'check_in_lng'        => -6.2603,
    'check_in_accuracy_m' => 10,
    'check_in_gps_at'     => '2020-01-01 10:00:00',
];
gpsPhase15Check('canActivateWithGpsProof rejects stale GPS', !canActivateWithGpsProof($pdo, $event, $staleAttendance));

$freshAttendance = [
    'check_in_lat'        => 53.3498,
    'check_in_lng'        => -6.2603,
    'check_in_accuracy_m' => 10,
    'check_in_gps_at'     => (new DateTime('now'))->format('Y-m-d H:i:s'),
];
gpsPhase15Check('canActivateWithGpsProof accepts fresh in-zone GPS', canActivateWithGpsProof($pdo, $event, $freshAttendance));

$root = dirname(__DIR__);
gpsPhase15Check('api/attendance-gps-ping.php exists', is_file($root . '/api/attendance-gps-ping.php'));
gpsPhase15Check('migrate-phase53 SQL exists', is_file($root . '/database/migrate-phase53-gps-attendance-phase15.sql'));
gpsPhase15Check('rollback-phase53 SQL exists', is_file($root . '/database/rollback-phase53-gps-attendance-phase15.sql'));

ensureAttendanceGpsPhase15Schema($pdo);
$cols = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN);
gpsPhase15Check('attendance.last_gps_at column', in_array('last_gps_at', $cols, true));

echo "GPS Phase 1.5 verification\n";
echo str_repeat('-', 40) . "\n";
foreach ($checks as $row) {
    echo ($row['ok'] ? 'PASS' : 'FAIL') . '  ' . $row['name'];
    if ($row['detail'] !== '') {
        echo ' — ' . $row['detail'];
    }
    echo "\n";
}
echo str_repeat('-', 40) . "\n";
echo "Passed: {$pass}  Failed: {$fail}\n";

exit($fail > 0 ? 1 : 0);
