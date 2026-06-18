<?php

declare(strict_types=1);

/**
 * Production truth verification — flags, schema, endpoints, assets.
 *
 * Web: /cron/production-truth-verify.php?key=REMINDER_CRON_KEY
 * CLI: php cron/production-truth-verify.php
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/feature-flags.php';
require_once dirname(__DIR__) . '/includes/attendance-gps-phase1.php';
require_once dirname(__DIR__) . '/includes/attendance-gps-phase15-schema.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
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

$pdo    = getDB();
$checks = [];
$pass   = 0;
$fail   = 0;
$warn   = 0;

function truthCheck(string $name, string $status, string $detail = ''): void
{
    global $checks, $pass, $fail, $warn;

    $checks[] = ['name' => $name, 'status' => $status, 'detail' => $detail];

    if ($status === 'pass') {
        $pass++;
    } elseif ($status === 'fail') {
        $fail++;
    } else {
        $warn++;
    }
}

$flags = getAllFeatureFlagValues($pdo);
truthCheck('feature_gps_attendance_v2', ($flags['feature_gps_attendance_v2'] ?? '0') === '1' ? 'warn' : 'pass', 'value=' . ($flags['feature_gps_attendance_v2'] ?? '0'));
truthCheck('feature_registration_wizard_v2', ($flags['feature_registration_wizard_v2'] ?? '0') === '1' ? 'pass' : 'warn', 'value=' . ($flags['feature_registration_wizard_v2'] ?? '0'));
truthCheck('feature_public_premium_v2', ($flags['feature_public_premium_v2'] ?? '0') === '1' ? 'pass' : 'warn', 'value=' . ($flags['feature_public_premium_v2'] ?? '0'));

ensureAttendanceGpsPhase1Schema($pdo);
ensureAttendanceGpsPhase15Schema($pdo);

try {
    $cols = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $cols = [];
}

$phase52 = ['attendance_status', 'activated_at', 'check_in_lat', 'check_in_lng', 'check_in_accuracy_m', 'check_in_gps_at'];
foreach ($phase52 as $col) {
    truthCheck('attendance.' . $col, in_array($col, $cols, true) ? 'pass' : 'fail');
}

$phase53 = ['last_gps_lat', 'last_gps_lng', 'last_gps_accuracy_m', 'last_gps_at'];
foreach ($phase53 as $col) {
    truthCheck('attendance.' . $col, in_array($col, $cols, true) ? 'pass' : 'fail');
}

truthCheck('GPS Phase 1.5 PHP', is_file(dirname(__DIR__) . '/includes/attendance-gps-phase15.php') ? 'pass' : 'fail');
truthCheck('GPS ping API file', is_file(dirname(__DIR__) . '/api/attendance-gps-ping.php') ? 'pass' : 'fail');
truthCheck('attendance-activate cron', is_file(dirname(__DIR__) . '/cron/attendance-activate.php') ? 'pass' : 'fail');
$verifyScript = is_file(dirname(__DIR__) . '/cron/verify-gps-phase15.php')
    || is_file(dirname(__DIR__) . '/scripts/verify-gps-phase15.php');
truthCheck('verify-gps-phase15 script', $verifyScript ? 'pass' : 'fail');
truthCheck('session idle TTL', defined('APP_SESSION_IDLE_TTL') && APP_SESSION_IDLE_TTL === 300 ? 'pass' : 'fail', 'APP_SESSION_IDLE_TTL=' . (defined('APP_SESSION_IDLE_TTL') ? (string) APP_SESSION_IDLE_TTL : 'missing'));
truthCheck('admin session idle TTL', defined('ADMIN_SESSION_IDLE_TTL') && ADMIN_SESSION_IDLE_TTL === 600 ? 'pass' : 'fail', 'ADMIN_SESSION_IDLE_TTL=' . (defined('ADMIN_SESSION_IDLE_TTL') ? (string) ADMIN_SESSION_IDLE_TTL : 'missing'));
truthCheck('mailer multipart', function_exists('buildEmailMimePayload') ? 'pass' : 'fail');
truthCheck('reminder_cron_key set', trim(getSetting($pdo, 'reminder_cron_key', '')) !== '' ? 'pass' : 'warn');

$cronKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
if ($cronKey !== '' && !$isCli) {
    $host = rtrim(getRegistrationSiteUrl($pdo), '/');
    $ping = @file_get_contents($host . '/cron/attendance-activate.php?key=' . rawurlencode($cronKey), false, stream_context_create([
        'http' => ['timeout' => 15, 'ignore_errors' => true],
    ]));
    truthCheck('attendance-activate web reachable', is_string($ping) && str_contains($ping, 'activated=') ? 'pass' : 'warn', is_string($ping) ? trim(substr($ping, 0, 80)) : 'no response');
}

$payload = [
    'ok'       => $fail === 0,
    'generated'=> (new DateTime('now'))->format('Y-m-d H:i:s'),
    'summary'  => ['pass' => $pass, 'warn' => $warn, 'fail' => $fail],
    'flags'    => $flags,
    'checks'   => $checks,
];

if ($isCli) {
    echo "Production truth verification\n";
    echo str_repeat('-', 50) . "\n";
    foreach ($checks as $row) {
        echo strtoupper($row['status']) . '  ' . $row['name'];
        if ($row['detail'] !== '') {
            echo ' — ' . $row['detail'];
        }
        echo "\n";
    }
    echo str_repeat('-', 50) . "\n";
    echo "Pass: {$pass}  Warn: {$warn}  Fail: {$fail}\n";
    exit($fail > 0 ? 1 : 0);
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
