<?php

declare(strict_types=1);

/**
 * JSON API for standalone Staff Sign Hub app (no username).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/app-environment.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-sign-hub-actions.php';
require_once __DIR__ . '/../includes/staff-sign-hub-installs.php';
require_once __DIR__ . '/../includes/events-repository.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex, nofollow');

/**
 * @param array<string, mixed> $payload
 */
function staffSignHubJson(array $payload, int $code = 200): void
{
    http_response_code($code);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = '{"ok":false,"message":"Could not encode response."}';
    }
    echo $json;
    exit;
}

try {
if (function_exists('initSecureSession')) {
    initSecureSession();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string) ($_GET['action'] ?? '') === 'bootstrap') {
    require_once __DIR__ . '/../includes/date-format.php';
    require_once __DIR__ . '/../includes/maps-core.php';
    $pdo = getDB();
    $today = function_exists('getOperationalTodayYmd')
        ? getOperationalTodayYmd($pdo)
        : date('Y-m-d');

    $date = trim((string) ($_GET['date'] ?? $today));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = $today;
    }

    $events = staffSignHubEventsForDate($pdo, $date);
    $nextDate = '';
    // If the requested day has no events, jump to the next event day automatically.
    if ($events === [] && !isset($_GET['date'])) {
        $nextDate = staffSignHubNextEventDate($pdo, $today);
        if ($nextDate !== '' && $nextDate !== $date) {
            $date = $nextDate;
            $events = staffSignHubEventsForDate($pdo, $date);
        }
    } elseif ($events === []) {
        $nextDate = staffSignHubNextEventDate($pdo, $date);
    }

    $list = [];
    foreach ($events as $ev) {
        $full = $ev;
        if (!isset($ev['venue_lat']) || !isset($ev['end_time'])) {
            $loaded = getEventById($pdo, (int) ($ev['id'] ?? 0));
            if (is_array($loaded)) {
                $full = $loaded;
            }
        }
        $venue = getEventVenueCoordinates($full);
        $list[] = [
            'id'               => (int) ($full['id'] ?? 0),
            'name'             => (string) ($full['name'] ?? ''),
            'event_date'       => substr((string) ($full['event_date'] ?? ''), 0, 10),
            'start_time'       => (string) ($full['start_time'] ?? ''),
            'end_time'         => (string) ($full['end_time'] ?? ''),
            'venue_configured' => $venue !== null,
            'signin_radius_m'  => getEventSigninRadiusMeters($full, $pdo),
        ];
    }

    staffSignHubJson([
        'ok'              => true,
        'csrf_token'      => csrfToken(),
        'today'           => $today,
        'date'            => $date,
        'next_event_date' => $nextDate,
        'events'          => $list,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    staffSignHubJson(['ok' => false, 'message' => 'Method not allowed.'], 405);
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
    $input = $_POST;
}

$token = (string) ($input['csrf_token'] ?? '');
if (!verifyCsrf($token !== '' ? $token : null)) {
    staffSignHubJson(['ok' => false, 'message' => 'Session expired. Refresh the app.'], 403);
}

$action = trim((string) ($input['action'] ?? ''));
$pdo    = getDB();
$eventId = (int) ($input['event_id'] ?? 0);

/** Soft device tracking — never fail the main Staff Sign action. */
$trackDevice = static function (PDO $pdo, string $type, array $payload): void {
    try {
        recordStaffSignHubDeviceEvent($pdo, $type, $payload);
    } catch (Throwable $e) {
        error_log('[EventStaff] staff-sign-hub trackDevice: ' . $e->getMessage());
    }
};

if ($action === 'search') {
    $nameQ = trim((string) ($input['name'] ?? ''));
    if ($eventId < 1) {
        staffSignHubJson(['ok' => false, 'message' => 'Select an event first.']);
    }
    if (strlen($nameQ) < 2) {
        staffSignHubJson(['ok' => false, 'message' => 'Type at least 2 letters of your name.']);
    }

    $result = staffSignHubSearchExplain($pdo, $eventId, $nameQ);

    if (trim((string) ($input['visitor_key'] ?? '')) !== '') {
        $trackDevice($pdo, 'usage_ping', $input);
    }

    staffSignHubJson($result);
}

if ($action === 'status') {
    $regId = (int) ($input['registration_id'] ?? 0);
    $result = staffSignHubStatus($pdo, $eventId, $regId);
    staffSignHubJson($result, $result['ok'] ? 200 : 400);
}

if ($action === 'select') {
    $regId = (int) ($input['registration_id'] ?? 0);
    try {
        $result = staffSignHubSelectPerson($pdo, $eventId, $regId);
    } catch (Throwable $e) {
        error_log('[EventStaff] staff-sign-hub select: ' . $e->getMessage());
        // Fallback: roster card without live PSA site (profile data only).
        $result = ['ok' => false, 'message' => 'Could not load PSA details.'];
        foreach (staffSignHubRoster($pdo, $eventId) as $row) {
            if ((int) ($row['id'] ?? 0) !== $regId) {
                continue;
            }
            $card = staffSignHubAttachCachedPsaGate(staffSignHubPublicCard($row));
            $result = ['ok' => true, 'message' => '', 'data' => $card];
            break;
        }
    }
    if (!empty($result['ok']) && trim((string) ($input['visitor_key'] ?? '')) !== '') {
        $trackDevice($pdo, 'identify', array_merge($input, [
            'registration_id' => $regId,
            'event_id'        => $eventId,
            'first_name'      => (string) ($result['data']['first_name'] ?? ''),
            'surname'         => (string) ($result['data']['surname'] ?? ''),
        ]));
    }
    staffSignHubJson($result, !empty($result['ok']) ? 200 : 400);
}

if ($action === 'present') {
    $regId = (int) ($input['registration_id'] ?? 0);
    $bib   = (string) ($input['bib'] ?? '');
    $result = staffSignHubPresent($pdo, $eventId, $regId, $bib, $input);
    if (!empty($result['ok']) && trim((string) ($input['visitor_key'] ?? '')) !== '') {
        $trackDevice($pdo, 'identify', array_merge($input, [
            'registration_id' => $regId,
            'event_id'        => $eventId,
        ]));
        $trackDevice($pdo, 'open', $input);
    }
    staffSignHubJson($result, $result['ok'] ? 200 : 400);
}

if ($action === 'signout') {
    $regId = (int) ($input['registration_id'] ?? 0);
    $result = staffSignHubSignOut($pdo, $eventId, $regId);
    staffSignHubJson($result, $result['ok'] ? 200 : 400);
}

if (in_array($action, ['device_open', 'device_download', 'device_install', 'device_identify', 'device_ping'], true)) {
    $map = [
        'device_open'      => 'open',
        'device_download'  => 'download',
        'device_install'   => 'install',
        'device_identify'  => 'identify',
        'device_ping'      => 'usage_ping',
    ];
    try {
        $result = recordStaffSignHubDeviceEvent($pdo, $map[$action], $input);
    } catch (Throwable $e) {
        error_log('[EventStaff] staff-sign-hub device action: ' . $e->getMessage());
        $result = ['ok' => true, 'message' => 'Tracked (soft).'];
    }
    staffSignHubJson($result, !empty($result['ok']) ? 200 : 400);
}

staffSignHubJson(['ok' => false, 'message' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    error_log('[EventStaff] staff-sign-hub-action: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    staffSignHubJson([
        'ok'      => false,
        'message' => 'Server error. Try again.',
        'debug'   => (defined('APP_DEBUG') && APP_DEBUG) ? ($e->getMessage()) : null,
    ], 500);
}
