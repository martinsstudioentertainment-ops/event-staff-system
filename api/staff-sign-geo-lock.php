<?php

declare(strict_types=1);

/**
 * Phone Admin Geo Lock API (PIN auth — no web admin login).
 *
 * Actions:
 *  - unlock_pin
 *  - logout
 *  - events
 *  - reverse (lat/lng → address/eircode)
 *  - lock (confirm pin+range onto event)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/app-environment.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-sign-geo-lock.php';
require_once __DIR__ . '/../includes/maps-core.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex, nofollow');

if (function_exists('initSecureSession')) {
    initSecureSession();
}

/**
 * @param array<string, mixed> $payload
 */
function staffSignGeoJson(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents('php://input');
$input = [];
if (is_string($raw) && $raw !== '' && str_starts_with(ltrim($raw), '{')) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}
if ($input === []) {
    $input = array_merge($_GET, $_POST);
}

$action = trim((string) ($input['action'] ?? $_GET['action'] ?? ''));
$pdo = getDB();

// Bootstrap GET for page load (csrf + session state).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($action === '' || $action === 'bootstrap')) {
    staffSignGeoJson([
        'ok'           => true,
        'csrf_token'   => csrfToken(),
        'unlocked'     => staffSignGeoLockSessionOk(),
        'radius_min'   => defined('EVENT_SIGNIN_RADIUS_MIN_M') ? (int) EVENT_SIGNIN_RADIUS_MIN_M : 50,
        'radius_max'   => defined('EVENT_SIGNIN_RADIUS_MAX_M') ? (int) EVENT_SIGNIN_RADIUS_MAX_M : 5000,
        'radius_default' => defined('EVENT_SIGNIN_RADIUS_DEFAULT_M') ? (int) EVENT_SIGNIN_RADIUS_DEFAULT_M : 500,
        'maps_enabled' => function_exists('googleMapsEnabled') ? googleMapsEnabled($pdo) : false,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    staffSignGeoJson(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

$token = (string) ($input['csrf_token'] ?? '');
if (!verifyCsrf($token !== '' ? $token : null)) {
    staffSignGeoJson(['ok' => false, 'message' => 'Session expired. Refresh and try again.'], 403);
}

if ($action === 'unlock_pin') {
    $pin = (string) ($input['pin'] ?? '');
    if (!staffSignGeoLockVerifyPin($pdo, $pin)) {
        staffSignGeoJson(['ok' => false, 'message' => 'Wrong admin PIN.'], 403);
    }
    staffSignGeoLockGrantSession();
    staffSignGeoJson([
        'ok'         => true,
        'message'    => 'Admin geo unlocked on this phone.',
        'csrf_token' => csrfToken(),
        'unlocked'   => true,
    ]);
}

if ($action === 'logout') {
    staffSignGeoLockClearSession();
    staffSignGeoJson(['ok' => true, 'message' => 'Locked again.', 'unlocked' => false]);
}

if (!staffSignGeoLockSessionOk()) {
    staffSignGeoJson(['ok' => false, 'message' => 'Enter admin PIN first.', 'unlocked' => false], 403);
}

if ($action === 'events') {
    staffSignGeoJson([
        'ok'      => true,
        'events'  => staffSignGeoLockEvents($pdo, 50),
        'message' => '',
    ]);
}

if ($action === 'reverse') {
    $lat = (float) ($input['lat'] ?? 0);
    $lng = (float) ($input['lng'] ?? 0);
    if ($lat == 0.0 && $lng == 0.0) {
        staffSignGeoJson(['ok' => false, 'message' => 'Latitude and longitude required.']);
    }
    $result = reverseGeocodeVenueCoords($lat, $lng, $pdo);
    if ($result === null) {
        staffSignGeoJson([
            'ok' => true,
            'lat' => $lat,
            'lng' => $lng,
            'eircode' => '',
            'formatted_address' => '',
            'warning' => 'GPS captured. Address lookup unavailable — you can still lock this pin.',
        ]);
    }
    staffSignGeoJson([
        'ok' => true,
        'lat' => $result['lat'],
        'lng' => $result['lng'],
        'eircode' => $result['eircode'],
        'formatted_address' => $result['formatted_address'],
    ]);
}

if ($action === 'lock') {
    $eventId = (int) ($input['event_id'] ?? 0);
    $lat = (float) ($input['lat'] ?? 0);
    $lng = (float) ($input['lng'] ?? 0);
    $radius = (int) ($input['radius_m'] ?? ($input['signin_radius_m'] ?? 500));
    $eircode = (string) ($input['eircode'] ?? '');
    $address = (string) ($input['address'] ?? ($input['formatted_address'] ?? ''));
    $result = staffSignGeoLockConfirm($pdo, $eventId, $lat, $lng, $radius, $eircode, $address);
    staffSignGeoJson($result, !empty($result['ok']) ? 200 : 400);
}

staffSignGeoJson(['ok' => false, 'message' => 'Unknown action.'], 400);
