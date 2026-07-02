<?php

declare(strict_types=1);

/**
 * Production E2E tests for Mobile API (Pre-Phase 2).
 *
 * Usage:
 *   php scripts/test-mobile-api-e2e-production.php
 *   MOBILE_ACCESS_TOKEN=eyJ... php scripts/test-mobile-api-e2e-production.php
 *   MOBILE_REFRESH_TOKEN=... MOBILE_DEVICE_ID=postman-test-device-001 php scripts/test-mobile-api-e2e-production.php
 *
 * Optional env:
 *   MOBILE_BASE_URL     default https://register.olasentra.com/api/mobile/v1
 *   MOBILE_ACCESS_TOKEN Bearer token (from Postman Auth → Google)
 *   MOBILE_REFRESH_TOKEN + MOBILE_DEVICE_ID for refresh test
 *   MOBILE_REGISTRATION_ID override for GPS/shift tests
 *   MOBILE_AVAIL_DATE   default 2099-06-15 (future, non-destructive)
 *   MOBILE_LEAVE_DATE   default 2099-06-20
 */

$baseUrl = rtrim(getenv('MOBILE_BASE_URL') ?: 'https://register.olasentra.com/api/mobile/v1', '/');
$accessToken = trim((string) (getenv('MOBILE_ACCESS_TOKEN') ?: ''));
$refreshToken = trim((string) (getenv('MOBILE_REFRESH_TOKEN') ?: ''));
$deviceId = trim((string) (getenv('MOBILE_DEVICE_ID') ?: 'e2e-production-device-001'));
$availDate = trim((string) (getenv('MOBILE_AVAIL_DATE') ?: '2099-06-15'));
$leaveDate = trim((string) (getenv('MOBILE_LEAVE_DATE') ?: '2099-06-20'));
$registrationId = (int) (getenv('MOBILE_REGISTRATION_ID') ?: 0);

$passed = 0;
$failed = 0;
$skipped = 0;
$results = [];

function e2eCaBundlePath(): ?string
{
    static $path = null;
    if ($path !== null) {
        return $path === '' ? null : $path;
    }

    $candidates = [
        dirname(__DIR__) . '/cacert.pem',
        'C:/curl-ca-bundle.crt',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $path = $candidate;

            return $path;
        }
    }

    $path = '';

    return null;
}

function e2eRecord(string $group, string $name, string $status, string $detail = ''): void
{
    global $results, $passed, $failed, $skipped;

    $results[] = [
        'group'  => $group,
        'name'   => $name,
        'status' => $status,
        'detail' => $detail,
    ];

    if ($status === 'PASS') {
        $passed++;
    } elseif ($status === 'SKIP') {
        $skipped++;
    } else {
        $failed++;
    }
}

function e2eRequest(
    string $method,
    string $url,
    ?array $body = null,
    ?string $token = null,
    int $timeout = 30
): array {
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token !== null && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    $method = strtoupper($method);
    if ($method === 'GET') {
        $opts[CURLOPT_HTTPGET] = true;
    } else {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
    }
    curl_setopt_array($ch, $opts);
    $ca = e2eCaBundlePath();
    if ($ca !== null) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if (is_string($raw) && str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }

    $json = null;
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode(trim($raw), true);
        if (is_array($decoded)) {
            $json = $decoded;
        }
    }

    return [
        'code' => $code,
        'raw'  => is_string($raw) ? $raw : '',
        'json' => $json,
        'err'  => $err,
    ];
}

echo "Mobile API Production E2E\n";
echo "Base: {$baseUrl}\n";
echo str_repeat('=', 50) . "\n\n";

// --- Public / gate ---

$config = e2eRequest('GET', $baseUrl . '/config?platform=android&app_version=1.0.0');
if ($config['err'] !== '') {
    e2eRecord('Config', 'GET /config reachable', 'FAIL', $config['err']);
} elseif ($config['code'] !== 200) {
    e2eRecord('Config', 'GET /config HTTP 200', 'FAIL', 'HTTP ' . $config['code']);
} else {
    e2eRecord('Config', 'GET /config HTTP 200', 'PASS');
}

$apiEnabled = false;
if (is_array($config['json'])) {
    $flag = $config['json']['mobile_api_enabled'] ?? false;
    $apiEnabled = $flag === true || $flag === 1 || $flag === '1';
}
if ($apiEnabled) {
    e2eRecord('Config', 'mobile_api_enabled true', 'PASS');
} else {
    e2eRecord('Config', 'mobile_api_enabled true', 'FAIL', 'API still disabled — run bootstrap first');
}

$meNoAuth = e2eRequest('GET', $baseUrl . '/me');
if ($apiEnabled) {
    e2eRecord('Auth gate', 'GET /me without token returns 401', $meNoAuth['code'] === 401 ? 'PASS' : 'FAIL', 'HTTP ' . $meNoAuth['code']);
} else {
    e2eRecord('Auth gate', 'GET /me without token when disabled', $meNoAuth['code'] === 503 ? 'PASS' : 'FAIL', 'HTTP ' . $meNoAuth['code']);
}

if ($refreshToken !== '' && $deviceId !== '') {
    $refresh = e2eRequest('POST', $baseUrl . '/auth/refresh', [
        'refresh_token' => $refreshToken,
        'device_id'     => $deviceId,
    ]);
    if ($refresh['code'] === 200 && is_array($refresh['json']) && !empty($refresh['json']['access_token'])) {
        e2eRecord('Auth', 'POST /auth/refresh', 'PASS');
        if ($accessToken === '') {
            $accessToken = (string) $refresh['json']['access_token'];
        }
    } else {
        e2eRecord('Auth', 'POST /auth/refresh', 'FAIL', 'HTTP ' . $refresh['code']);
    }
} else {
    e2eRecord('Auth', 'POST /auth/refresh', 'SKIP', 'Set MOBILE_REFRESH_TOKEN + MOBILE_DEVICE_ID');
}

if ($accessToken === '') {
    e2eRecord('Auth', 'Google login (manual)', 'SKIP', 'Set MOBILE_ACCESS_TOKEN from Postman Auth → Google');
    echo "\nAuthenticated endpoint tests SKIPPED — provide MOBILE_ACCESS_TOKEN\n\n";
} else {
    $auth = fn (string $m, string $path, ?array $body = null): array => e2eRequest($m, $baseUrl . $path, $body, $accessToken);

    // Profile
    $me = $auth('GET', '/me');
    e2eRecord('Profile', 'GET /me', ($me['code'] === 200 && is_array($me['json']['staff'] ?? null)) ? 'PASS' : 'FAIL', 'HTTP ' . $me['code']);

    // Dashboard
    $dash = $auth('GET', '/dashboard');
    e2eRecord('Dashboard', 'GET /dashboard', ($dash['code'] === 200 && isset($dash['json']['profile'])) ? 'PASS' : 'FAIL', 'HTTP ' . $dash['code']);

    // Shifts
    $shifts = $auth('GET', '/shifts?filter=all&page=1&per_page=20');
    e2eRecord('Shifts', 'GET /shifts', ($shifts['code'] === 200 && is_array($shifts['json']['shifts'] ?? null)) ? 'PASS' : 'FAIL', 'HTTP ' . $shifts['code']);

    if ($registrationId < 1 && is_array($shifts['json']['shifts'] ?? null) && $shifts['json']['shifts'] !== []) {
        $registrationId = (int) ($shifts['json']['shifts'][0]['registration_id'] ?? 0);
    }

    $today = $auth('GET', '/shifts/today');
    e2eRecord('Shifts', 'GET /shifts/today', $today['code'] === 200 ? 'PASS' : 'FAIL', 'HTTP ' . $today['code']);

    if ($registrationId > 0) {
        $shiftShow = $auth('GET', '/shifts/' . $registrationId);
        e2eRecord('Shifts', 'GET /shifts/{id}', $shiftShow['code'] === 200 ? 'PASS' : 'FAIL', 'HTTP ' . $shiftShow['code'] . ' reg=' . $registrationId);
    } else {
        e2eRecord('Shifts', 'GET /shifts/{id}', 'SKIP', 'No registration_id available');
    }

    // Notifications
    $notifs = $auth('GET', '/notifications?page=1&per_page=20');
    e2eRecord('Notifications', 'GET /notifications', ($notifs['code'] === 200 && is_array($notifs['json']['notifications'] ?? null)) ? 'PASS' : 'FAIL', 'HTTP ' . $notifs['code']);

    $readAll = $auth('POST', '/notifications/read-all');
    e2eRecord('Notifications', 'POST /notifications/read-all', $readAll['code'] === 200 ? 'PASS' : 'FAIL', 'HTTP ' . $readAll['code']);

    // Messages
    $msgs = $auth('GET', '/messages?limit=50');
    e2eRecord('Messages', 'GET /messages', ($msgs['code'] === 200 && is_array($msgs['json']['thread'] ?? null)) ? 'PASS' : 'FAIL', 'HTTP ' . $msgs['code']);

    $sendMsg = $auth('POST', '/messages', ['body' => 'E2E production validation ping ' . date('c')]);
    e2eRecord('Messages', 'POST /messages', $sendMsg['code'] === 200 ? 'PASS' : 'FAIL', 'HTTP ' . $sendMsg['code']);

    // Documents
    $docs = $auth('GET', '/documents');
    e2eRecord('Documents', 'GET /documents', ($docs['code'] === 200 && is_array($docs['json']['documents'] ?? null)) ? 'PASS' : 'FAIL', 'HTTP ' . $docs['code']);

    $docFile = e2eRequest('GET', $baseUrl . '/documents/psa_front/file', null, $accessToken);
    e2eRecord('Documents', 'GET /documents/psa_front/file', in_array($docFile['code'], [200, 404], true) ? 'PASS' : 'FAIL', 'HTTP ' . $docFile['code']);

    // Availability
    $month = substr($availDate, 0, 7);
    $avail = $auth('GET', '/availability?month=' . $month);
    e2eRecord('Availability', 'GET /availability', ($avail['code'] === 200 && is_array($avail['json']['days'] ?? null)) ? 'PASS' : 'FAIL', 'HTTP ' . $avail['code']);

    $setAvail = $auth('PUT', '/availability/' . $availDate, ['status' => 'preferred', 'notes' => 'E2E test']);
    e2eRecord('Availability', 'PUT /availability/{date}', in_array($setAvail['code'], [200, 409], true) ? 'PASS' : 'FAIL', 'HTTP ' . $setAvail['code']);

    $leave = $auth('POST', '/leave', ['date' => $leaveDate, 'type' => 'leave', 'notes' => 'E2E validation']);
    e2eRecord('Leave', 'POST /leave', in_array($leave['code'], [200, 409], true) ? 'PASS' : 'FAIL', 'HTTP ' . $leave['code']);

    // Offline sync
    $clientId = 'e2e-prod-' . date('Ymd') . '-avail';
    $sync = $auth('POST', '/sync/offline', [
        'items' => [[
            'client_id' => $clientId,
            'action'    => 'availability_set',
            'payload'   => [
                'date'   => $availDate,
                'status' => 'available',
                'notes'  => 'E2E offline sync',
            ],
        ]],
    ]);
    e2eRecord('Offline sync', 'POST /sync/offline', ($sync['code'] === 200 && is_array($sync['json']['results'] ?? null)) ? 'PASS' : 'FAIL', 'HTTP ' . $sync['code']);

    $syncDup = $auth('POST', '/sync/offline', [
        'items' => [[
            'client_id' => $clientId,
            'action'    => 'availability_set',
            'payload'   => [
                'date'   => $availDate,
                'status' => 'available',
            ],
        ]],
    ]);
    $dupOk = $syncDup['code'] === 200
        && is_array($syncDup['json']['results'][0] ?? null)
        && ($syncDup['json']['results'][0]['status'] ?? '') === 'duplicate';
    e2eRecord('Offline sync', 'Duplicate client_id protection', $dupOk ? 'PASS' : 'FAIL', 'HTTP ' . $syncDup['code']);

    // GPS — read-only / validation only (no live check-in without active shift + coords)
    $gpsStatus = $registrationId > 0
        ? $auth('GET', '/gps/status?registration_id=' . $registrationId)
        : ['code' => 0];
    if ($registrationId > 0) {
        e2eRecord('GPS', 'GET /gps/status', in_array($gpsStatus['code'], [200, 404, 422], true) ? 'PASS' : 'FAIL', 'HTTP ' . $gpsStatus['code']);
    } else {
        e2eRecord('GPS', 'GET /gps/status', 'SKIP', 'No registration_id');
    }

    e2eRecord('GPS', 'POST /checkin (live)', 'SKIP', 'Requires active approved shift at venue — manual Postman with GPS');
    e2eRecord('GPS', 'POST /checkout (live)', 'SKIP', 'Requires active check-in — manual Postman');
    e2eRecord('GPS', 'POST /gps/ping (live)', 'SKIP', 'Requires active check-in — manual Postman');
    e2eRecord('Shifts', 'POST /shifts/{id}/respond (live)', 'SKIP', 'Requires pending approved shift — manual if applicable');
}

// --- Regression (web platform) ---

$regress = [
    ['Staff portal', 'https://register.olasentra.com/staff-app.php'],
    ['Staff status', 'https://register.olasentra.com/status.php'],
    ['Admin login', 'https://admin.olasentra.com/admin/login.php'],
    ['Admin dashboard', 'https://admin.olasentra.com/admin/dashboard.php'],
];

foreach ($regress as [$label, $url]) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_NOBODY         => false,
    ]);
    if (e2eCaBundlePath() === null) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    e2eRecord('Regression', $label, ($code > 0 && $code < 500) ? 'PASS' : 'FAIL', 'HTTP ' . $code);
}

echo "\nResults: {$passed} passed, {$failed} failed, {$skipped} skipped\n\n";

foreach ($results as $row) {
    $icon = match ($row['status']) {
        'PASS'  => 'PASS',
        'SKIP'  => 'SKIP',
        default => 'FAIL',
    };
    $detail = $row['detail'] !== '' ? ' — ' . $row['detail'] : '';
    echo sprintf("  [%s] %s / %s%s\n", $icon, $row['group'], $row['name'], $detail);
}

$reportPath = dirname(__DIR__) . '/docs/api/mobile/PRODUCTION-E2E-REPORT.json';
file_put_contents($reportPath, json_encode([
    'timestamp' => date('c'),
    'base_url'  => $baseUrl,
    'api_enabled' => $apiEnabled,
    'authenticated' => $accessToken !== '',
    'passed'    => $passed,
    'failed'    => $failed,
    'skipped'   => $skipped,
    'results'   => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "\nJSON report: docs/api/mobile/PRODUCTION-E2E-REPORT.json\n";

exit($failed > 0 ? 1 : 0);
