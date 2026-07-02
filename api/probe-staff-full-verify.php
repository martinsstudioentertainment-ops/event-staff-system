<?php
/**
 * Full staff-app production verification (read-only). Secured by cron key.
 * GET ?key=KEY
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

const STAFF_VERIFY_KEY = 'email-encoding-verify-20260606';
const STAFF_VERIFY_EMAIL = 'olabodeoluwafemi25800@gmail.com';

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';

$key = trim((string) ($_GET['key'] ?? ''));
$pdo = getDB();
$allowed = array_values(array_unique(array_filter([
    trim(getSetting($pdo, 'reminder_cron_key', '')),
    STAFF_VERIFY_KEY,
])));
$keyOk = false;
foreach ($allowed as $allowedKey) {
    if ($key !== '' && hash_equals($allowedKey, $key)) {
        $keyOk = true;
        break;
    }
}
if (!$keyOk) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

/** @var list<array{name: string, ok: bool, detail: string}> */
$checks = [];

function staffVerifyCheck(string $name, bool $ok, string $detail = ''): void
{
    global $checks;
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
}

function staffVerifyHttp(string $url, int $expectMin = 200, int $expectMax = 399, bool $headOnly = true): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADER         => $headOnly,
        CURLOPT_NOBODY         => $headOnly,
    ]);
    if (!$headOnly) {
        curl_setopt($ch, CURLOPT_HEADER, false);
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [
        'code' => $code,
        'ok'   => $err === '' && $code >= $expectMin && $code <= $expectMax,
        'err'  => $err,
        'body' => is_string($body) ? $body : '',
    ];
}

function staffVerifyRequire(string $rel): bool
{
    try {
        require_once dirname(__DIR__) . '/' . ltrim($rel, '/');
        return true;
    } catch (Throwable $e) {
        staffVerifyCheck('include:' . basename($rel), false, $e->getMessage());
        return false;
    }
}

// --- PHP includes & functions ---
$includeOk = staffVerifyRequire('includes/staff-portal-session.php')
    && staffVerifyRequire('includes/staff-app-v3-shell.php')
    && staffVerifyRequire('includes/staff-app-v3-pages.php')
    && staffVerifyRequire('includes/staff-psa.php')
    && staffVerifyRequire('includes/staff-venue-checkin.php')
    && staffVerifyRequire('includes/mobile/schema/mobile-api-schema.php')
    && staffVerifyRequire('includes/mobile/mobile-auth.php')
    && staffVerifyRequire('includes/mobile/services/MobileDashboardService.php')
    && staffVerifyRequire('includes/mobile/services/MobileProfileService.php')
    && staffVerifyRequire('includes/mobile/services/MobileAttendanceService.php')
    && staffVerifyRequire('includes/mobile/mobile-api-qa-runner.php');

staffVerifyCheck('Critical PHP includes load', $includeOk);

$funcs = [
    'renderStaffPortalBodyAttributes',
    'renderStaffPortalSessionIdleScript',
    'isStoredPsaImagePath',
    'psaImageFilesystemPath',
    'buildStaffV3Context',
    'mobileDashboardServiceBuild',
    'mobileApiIsEnabled',
    'isStaffGoogleSigninEnabled',
    'resetCheckinForRegistration',
];
foreach ($funcs as $fn) {
    staffVerifyCheck('function:' . $fn, function_exists($fn));
}

// --- Settings gates ---
staffVerifyCheck('Google sign-in enabled', isStaffGoogleSigninEnabled($pdo));
staffVerifyCheck('Google OAuth configured', isStaffGoogleSigninConfigured($pdo));
staffVerifyCheck('Mobile API enabled', mobileApiIsEnabled($pdo));
staffVerifyCheck('Mobile JWT secret set', trim(getSetting($pdo, 'mobile_jwt_secret', '')) !== '');

require_once dirname(__DIR__) . '/includes/attendance-gps-phase1.php';
staffVerifyCheck('GPS attendance v2 enabled', isGpsAttendanceV2Enabled($pdo));

// --- Staff context + mobile dashboard (service layer) ---
$staff = getStaffByEmail($pdo, STAFF_VERIFY_EMAIL);
if ($staff === null) {
    staffVerifyCheck('Test staff exists', false, STAFF_VERIFY_EMAIL);
} else {
    staffVerifyCheck('Test staff exists', true, 'id=' . (int) $staff['id']);
    try {
        $ctx = buildStaffV3Context($pdo, $staff);
        staffVerifyCheck('Web buildStaffV3Context', is_array($ctx) && ($ctx['display_name'] ?? '') !== '');
    } catch (Throwable $e) {
        staffVerifyCheck('Web buildStaffV3Context', false, $e->getMessage());
    }
    try {
        $dash = mobileDashboardServiceBuild($pdo, $staff);
        staffVerifyCheck('Mobile dashboard service', !empty($dash['ok']) && isset($dash['profile']));
    } catch (Throwable $e) {
        staffVerifyCheck('Mobile dashboard service', false, $e->getMessage());
    }
    try {
        $profile = mobileProfileServiceBuild($pdo, $staff);
        staffVerifyCheck('Mobile profile service', !empty($profile['ok']) && is_array($profile['staff'] ?? null));
    } catch (Throwable $e) {
        staffVerifyCheck('Mobile profile service', false, $e->getMessage());
    }
    try {
        $gps = mobileAttendanceServiceGpsStatus($pdo, $staff, []);
        staffVerifyCheck('Mobile GPS/check-in status service', !empty($gps['ok']));
    } catch (Throwable $e) {
        staffVerifyCheck('Mobile GPS/check-in status service', false, $e->getMessage());
    }
    $token = mobileQaIssueAccessToken($pdo, $staff);
    if ($token === null) {
        staffVerifyCheck('Mobile JWT issue token', false, 'Could not issue QA token');
    } else {
        staffVerifyCheck('Mobile JWT issue token', true);
        $base = rtrim(getSetting($pdo, 'mobile_api_base_url', '') ?: 'https://register.olasentra.com/api/mobile/v1', '/');
        foreach (['/dashboard', '/me', '/shifts/today', '/gps/status'] as $path) {
            $http = mobileQaHttpGet($base . $path, $token);
            $ok   = $http['code'] === 200 && is_array($http['json'] ?? null) && ($http['json']['ok'] ?? false) !== false;
            staffVerifyCheck('HTTP GET ' . $path, $ok, 'HTTP ' . $http['code'] . ($http['err'] !== '' ? ' ' . $http['err'] : ''));
        }
    }
}

// --- Public HTTP pages ---
$base = rtrim(getSetting($pdo, 'registration_site_url', 'https://register.olasentra.com'), '/');
foreach ([
    ['Staff app (guest login page)', $base . '/staff-app.php', 200, 200],
    ['Mobile API config', $base . '/api/mobile/v1/config?platform=android', 200, 200, false],
    ['Google sign-in redirect', $base . '/staff-google-signin.php?return=staff-app.php', 302, 302],
    ['Staff check-in (auth redirect)', $base . '/staff-checkin.php', 302, 302],
    ['Staff shifts (auth redirect)', $base . '/staff-shifts.php', 302, 302],
    ['PWA manifest', $base . '/manifest.php', 200, 200],
] as $row) {
    [$label, $url, $min, $max] = $row;
    $headOnly = $row[4] ?? true;
    $r = staffVerifyHttp($url, $min, $max, $headOnly);
    staffVerifyCheck($label, $r['ok'], 'HTTP ' . $r['code'] . ($r['err'] !== '' ? ' ' . $r['err'] : ''));
}

$configBody = @file_get_contents($base . '/api/mobile/v1/config?platform=ios');
if (is_string($configBody)) {
    $config = json_decode($configBody, true);
    staffVerifyCheck('Config mobile_api_enabled flag', ($config['mobile_api_enabled'] ?? false) === true);
    staffVerifyCheck('Config google_signin_enabled flag', ($config['google_signin_enabled'] ?? false) === true);
} else {
    staffVerifyCheck('Config JSON parse', false, 'Could not fetch config body');
}

$guestHtml = @file_get_contents($base . '/staff-app.php');
if (is_string($guestHtml)) {
    staffVerifyCheck('Staff app renders Google button', str_contains($guestHtml, 'Sign in with Google') || str_contains($guestHtml, 'es-v3-login'));
    staffVerifyCheck('Staff app not fatal error page', !str_contains($guestHtml, 'Call to undefined function'));
} else {
    staffVerifyCheck('Staff app HTML fetch', false);
}

$failed = array_values(array_filter($checks, static fn (array $c): bool => !$c['ok']));
$passed = count($checks) - count($failed);

echo json_encode([
    'ok'           => $failed === [],
    'passed'       => $passed,
    'failed'       => count($failed),
    'total'        => count($checks),
    'pass_rate'    => count($checks) > 0 ? round(100 * $passed / count($checks), 1) : 0,
    'failures'     => $failed,
    'checks'       => $checks,
    'generated_at' => gmdate('c'),
    'site'         => $base,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
