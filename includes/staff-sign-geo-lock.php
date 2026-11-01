<?php

declare(strict_types=1);

/**
 * Staff Sign — phone Admin Geo Lock (no web-admin login).
 * Auth: short PIN in settings (staff_sign_geo_lock_pin).
 */

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/maps-core.php';
require_once __DIR__ . '/date-format.php';

const STAFF_SIGN_GEO_PIN_SETTING = 'staff_sign_geo_lock_pin';
const STAFF_SIGN_GEO_PIN_DEFAULT = '2468';
const STAFF_SIGN_GEO_SESSION_KEY = 'staff_sign_geo_admin_ok_at';
const STAFF_SIGN_GEO_SESSION_TTL = 28800; // 8 hours

function staffSignGeoLockPin(PDO $pdo): string
{
    $pin = trim(getSetting($pdo, STAFF_SIGN_GEO_PIN_SETTING, ''));
    if ($pin === '') {
        setSetting($pdo, STAFF_SIGN_GEO_PIN_SETTING, STAFF_SIGN_GEO_PIN_DEFAULT);
        return STAFF_SIGN_GEO_PIN_DEFAULT;
    }

    return $pin;
}

function staffSignGeoLockVerifyPin(PDO $pdo, string $pin): bool
{
    $expected = staffSignGeoLockPin($pdo);
    $pin = trim($pin);

    return $pin !== '' && hash_equals($expected, $pin);
}

function staffSignGeoLockGrantSession(): void
{
    if (function_exists('initSecureSession')) {
        initSecureSession();
    } elseif (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION[STAFF_SIGN_GEO_SESSION_KEY] = time();
}

function staffSignGeoLockClearSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (function_exists('initSecureSession')) {
            initSecureSession();
        } else {
            session_start();
        }
    }
    unset($_SESSION[STAFF_SIGN_GEO_SESSION_KEY]);
}

function staffSignGeoLockSessionOk(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (function_exists('initSecureSession')) {
            initSecureSession();
        } else {
            @session_start();
        }
    }
    $at = (int) ($_SESSION[STAFF_SIGN_GEO_SESSION_KEY] ?? 0);
    if ($at < 1) {
        return false;
    }
    if ((time() - $at) > STAFF_SIGN_GEO_SESSION_TTL) {
        unset($_SESSION[STAFF_SIGN_GEO_SESSION_KEY]);

        return false;
    }

    return true;
}

/**
 * @return list<array<string, mixed>>
 */
function staffSignGeoLockEvents(PDO $pdo, int $limit = 40): array
{
    $today = function_exists('getOperationalTodayYmd') ? getOperationalTodayYmd($pdo) : date('Y-m-d');
    $limit = max(1, min(80, $limit));

    try {
        $stmt = $pdo->prepare(
            "SELECT id, name, event_date, start_time, end_time, location,
                    venue_eircode, venue_lat, venue_lng, signin_radius_m, is_active
             FROM events
             WHERE COALESCE(is_active, 1) = 1
               AND event_date >= DATE_SUB(:today, INTERVAL 1 DAY)
             ORDER BY event_date ASC, id DESC
             LIMIT {$limit}"
        );
        $stmt->execute(['today' => $today]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[EventStaff] staffSignGeoLockEvents: ' . $e->getMessage());

        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $lat = isset($row['venue_lat']) ? normalizeCoordinate((string) $row['venue_lat']) : null;
        $lng = isset($row['venue_lng']) ? normalizeCoordinate((string) $row['venue_lng']) : null;
        $radius = getEventSigninRadiusMeters($row, $pdo);
        $out[] = [
            'id'              => (int) ($row['id'] ?? 0),
            'name'            => (string) ($row['name'] ?? ''),
            'event_date'      => substr((string) ($row['event_date'] ?? ''), 0, 10),
            'start_time'      => (string) ($row['start_time'] ?? ''),
            'end_time'        => (string) ($row['end_time'] ?? ''),
            'location'        => (string) ($row['location'] ?? ''),
            'venue_eircode'   => (string) ($row['venue_eircode'] ?? ''),
            'venue_lat'       => $lat,
            'venue_lng'       => $lng,
            'signin_radius_m' => $radius,
            'geo_locked'      => $lat !== null && $lng !== null,
        ];
    }

    return $out;
}

/**
 * Persist phone-locked geo onto the event (and linked venue GPS if present).
 *
 * @return array{ok:bool,message:string,data?:array<string,mixed>}
 */
function staffSignGeoLockConfirm(
    PDO $pdo,
    int $eventId,
    float $lat,
    float $lng,
    int $radiusM,
    string $eircode = '',
    string $address = ''
): array {
    if ($eventId < 1) {
        return ['ok' => false, 'message' => 'Select an event.'];
    }
    $latN = normalizeCoordinate((string) $lat);
    $lngN = normalizeCoordinate((string) $lng);
    if ($latN === null || $lngN === null) {
        return ['ok' => false, 'message' => 'Invalid GPS coordinates.'];
    }

    $min = defined('EVENT_SIGNIN_RADIUS_MIN_M') ? (int) EVENT_SIGNIN_RADIUS_MIN_M : 50;
    $max = defined('EVENT_SIGNIN_RADIUS_MAX_M') ? (int) EVENT_SIGNIN_RADIUS_MAX_M : 5000;
    if ($radiusM < $min || $radiusM > $max) {
        return ['ok' => false, 'message' => "Range must be between {$min} and {$max} metres."];
    }

    $event = getEventById($pdo, $eventId);
    if ($event === null) {
        return ['ok' => false, 'message' => 'Event not found.'];
    }

    $eircode = normalizeEircode($eircode);
    $address = trim($address);

    try {
        $sets = [
            'venue_lat = :lat',
            'venue_lng = :lng',
            'signin_radius_m = :radius',
        ];
        $params = [
            'lat'    => $latN,
            'lng'    => $lngN,
            'radius' => $radiusM,
            'id'     => $eventId,
        ];
        if ($eircode !== '') {
            $sets[] = 'venue_eircode = :eircode';
            $params['eircode'] = $eircode;
        }
        // Only fill blank location from reverse-geocode address.
        $currentLoc = trim((string) ($event['location'] ?? ''));
        if ($currentLoc === '' && $address !== '') {
            $sets[] = 'location = :location';
            $params['location'] = substr($address, 0, 255);
        }

        $sql = 'UPDATE events SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);

        // Keep linked venue GPS in sync when helpers exist.
        if (function_exists('persistVenueGpsFromEventPayload')) {
            persistVenueGpsFromEventPayload($pdo, [
                'venue_id'      => $event['venue_id'] ?? null,
                'venue_lat'     => $latN,
                'venue_lng'     => $lngN,
                'venue_eircode' => $eircode !== '' ? $eircode : (string) ($event['venue_eircode'] ?? ''),
            ]);
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] staffSignGeoLockConfirm: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Could not save geocode to event.'];
    }

    $fresh = getEventById($pdo, $eventId) ?? $event;

    return [
        'ok'      => true,
        'message' => 'Geo locked. Staff can sign in on their phones inside this range.',
        'data'    => [
            'event_id'        => $eventId,
            'event_name'      => (string) ($fresh['name'] ?? ''),
            'venue_lat'       => normalizeCoordinate((string) ($fresh['venue_lat'] ?? $latN)),
            'venue_lng'       => normalizeCoordinate((string) ($fresh['venue_lng'] ?? $lngN)),
            'signin_radius_m' => getEventSigninRadiusMeters($fresh, $pdo),
            'venue_eircode'   => (string) ($fresh['venue_eircode'] ?? $eircode),
            'location'        => (string) ($fresh['location'] ?? $address),
            'geo_locked'      => true,
            'admin_hub'       => 'https://admin.olasentra.com/staff-sign-hub.php?event_id=' . $eventId,
        ],
    ];
}
