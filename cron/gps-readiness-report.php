<?php

declare(strict_types=1);

/**
 * GPS Attendance Phase 1 + 1.5 production readiness report.
 *
 * Web: /cron/gps-readiness-report.php?key=REMINDER_CRON_KEY
 * CLI: php cron/gps-readiness-report.php
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/feature-flags.php';
require_once dirname(__DIR__) . '/includes/attendance-gps-phase1.php';
require_once dirname(__DIR__) . '/includes/attendance-gps-phase15-schema.php';
require_once dirname(__DIR__) . '/includes/attendance-gps-phase15.php';
require_once dirname(__DIR__) . '/includes/site-urls.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');

if (!$isCli) {
    header('Content-Type: application/json; charset=UTF-8');

    try {
        $pdo         = getDB();
        $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
        $providedKey = trim((string) ($_GET['key'] ?? ''));

        if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Forbidden']);
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database error']);
        exit;
    }
}

$pdo     = getDB();
$checks  = [];
$blockers = [];

function gpsReadyCheck(string $name, bool $ok, string $detail = '', bool $blocking = true): void
{
    global $checks, $blockers;

    $checks[] = [
        'name'     => $name,
        'status'   => $ok ? 'pass' : ($blocking ? 'fail' : 'warn'),
        'detail'   => $detail,
        'blocking' => $blocking,
    ];

    if (!$ok && $blocking) {
        $blockers[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
}

$flagOn = isGpsAttendanceV2Enabled($pdo);
gpsReadyCheck('feature_gps_attendance_v2 flag', true, $flagOn ? 'ON — pilot only' : 'OFF (safe default)', false);

ensureAttendanceGpsPhase1Schema($pdo);
ensureAttendanceGpsPhase15Schema($pdo);

try {
    $cols = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $cols = [];
    gpsReadyCheck('attendance table readable', false, $e->getMessage());
}

$phase52 = ['attendance_status', 'activated_at', 'check_in_lat', 'check_in_lng', 'check_in_accuracy_m', 'check_in_gps_at'];
foreach ($phase52 as $col) {
    gpsReadyCheck('migrate-phase52 column: ' . $col, in_array($col, $cols, true));
}

$phase53 = ['last_gps_lat', 'last_gps_lng', 'last_gps_accuracy_m', 'last_gps_at'];
foreach ($phase53 as $col) {
    gpsReadyCheck('migrate-phase53 column: ' . $col, in_array($col, $cols, true));
}

$root = dirname(__DIR__);
gpsReadyCheck('SQL migrate-phase52 file', is_file($root . '/database/migrate-phase52-gps-attendance-phase1.sql'));
gpsReadyCheck('SQL migrate-phase53 file', is_file($root . '/database/migrate-phase53-gps-attendance-phase15.sql'));
gpsReadyCheck('SQL rollback-phase52 file', is_file($root . '/database/rollback-phase52-gps-attendance-phase1.sql'));
gpsReadyCheck('SQL rollback-phase53 file', is_file($root . '/database/rollback-phase53-gps-attendance-phase15.sql'));
gpsReadyCheck('attendance-activate cron', is_file($root . '/cron/attendance-activate.php'));
gpsReadyCheck(
    'verify-gps-phase15 script',
    is_file($root . '/cron/verify-gps-phase15.php') || is_file($root . '/scripts/verify-gps-phase15.php')
);
gpsReadyCheck('GPS ping API', is_file($root . '/api/attendance-gps-ping.php'));
gpsReadyCheck('Phase 1.5 PHP module', is_file($root . '/includes/attendance-gps-phase15.php'));

$cronKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
gpsReadyCheck('reminder_cron_key configured', $cronKey !== '', $cronKey === '' ? 'Set in Admin → Email' : 'set', false);

if ($cronKey !== '' && !$isCli) {
    $host = rtrim(getRegistrationSiteUrl($pdo), '/');
    $ping = @file_get_contents($host . '/cron/attendance-activate.php?key=' . rawurlencode($cronKey), false, stream_context_create([
        'http' => ['timeout' => 15, 'ignore_errors' => true],
    ]));
    gpsReadyCheck(
        'attendance-activate cron reachable',
        is_string($ping) && str_contains($ping, 'activated='),
        is_string($ping) ? trim(substr($ping, 0, 80)) : 'no response',
        false
    );
}

// Logic self-test (no DB)
$event = [
    'venue_lat'       => 53.3498,
    'venue_lng'       => -6.2603,
    'signin_radius_m' => 1000,
    'event_date'      => '2026-12-01',
    'start_time'      => '18:00:00',
];
$near = validateGpsForCheckin($pdo, $event, ['lat' => 53.3498, 'lng' => -6.2603, 'accuracy_m' => 15]);
gpsReadyCheck('validateGpsForCheckin logic', $near['ok']);

$verdict = $blockers === [] ? 'READY FOR PILOT' : 'NOT READY FOR PILOT';
$pass    = count(array_filter($checks, static fn(array $c): bool => $c['status'] === 'pass'));
$warn    = count(array_filter($checks, static fn(array $c): bool => $c['status'] === 'warn'));
$fail    = count(array_filter($checks, static fn(array $c): bool => $c['status'] === 'fail'));

$payload = [
    'ok'       => $blockers === [],
    'verdict'  => $verdict,
    'generated'=> (new DateTime('now'))->format('Y-m-d H:i:s'),
    'flag_gps' => $flagOn ? '1' : '0',
    'summary'  => ['pass' => $pass, 'warn' => $warn, 'fail' => $fail],
    'blockers' => $blockers,
    'pilot_steps' => [
        'Keep feature_gps_attendance_v2 OFF until pilot event selected',
        'Schedule cron/attendance-activate.php every 1–5 min on event days',
        'Run scripts/verify-gps-phase15.php on production DB',
        'Enable flag in Admin → Feature flags for pilot only',
        'Rollback L0: flag OFF instantly; L1: rollback-phase53; L2: rollback-phase52',
    ],
    'checks'   => $checks,
];

if ($isCli) {
    echo "GPS Readiness Report\n";
    echo str_repeat('=', 50) . "\n";
    echo "VERDICT: {$verdict}\n\n";
    foreach ($checks as $row) {
        echo strtoupper($row['status']) . '  ' . $row['name'];
        if ($row['detail'] !== '') {
            echo ' — ' . $row['detail'];
        }
        echo "\n";
    }
    if ($blockers !== []) {
        echo "\nBlockers:\n";
        foreach ($blockers as $b) {
            echo "  - {$b}\n";
        }
    }
    echo str_repeat('=', 50) . "\n";
    exit($blockers === [] ? 0 : 1);
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
