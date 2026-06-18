<?php

require_once __DIR__ . '/settings-repository.php';

/** Legacy venue sign-in geofence radius (feature_gps_attendance_v2 OFF). */
const EVENT_SIGNIN_RADIUS_LEGACY_M = 100;

/** Default sign-in radius when events.signin_radius_m is null (feature_gps_attendance_v2 ON). */
const EVENT_SIGNIN_RADIUS_DEFAULT_M = 1000;

/** Allowed per-event sign-in radius range (metres) when GPS v2 is ON. */
const EVENT_SIGNIN_RADIUS_MIN_M = 50;
const EVENT_SIGNIN_RADIUS_MAX_M = 5000;

/** @deprecated Use EVENT_SIGNIN_RADIUS_LEGACY_M */
const EVENT_SIGNIN_RADIUS_M = EVENT_SIGNIN_RADIUS_LEGACY_M;

function getGoogleMapsApiKey(?PDO $pdo = null): string
{
    if ($pdo === null && function_exists('getDB')) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            return '';
        }
    }

    return $pdo ? trim(getSetting($pdo, 'google_maps_api_key', '')) : '';
}

function googleMapsEnabled(?PDO $pdo = null): bool
{
    return getGoogleMapsApiKey($pdo) !== '';
}

function normalizeCoordinate(?string $value): ?float
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    if (!is_numeric($value)) {
        return null;
    }

    $float = (float) $value;

    return is_finite($float) ? $float : null;
}

function buildGoogleMapsLink(?float $lat, ?float $lng): string
{
    if ($lat === null || $lng === null) {
        return '';
    }

    return 'https://www.google.com/maps?q=' . rawurlencode($lat . ',' . $lng);
}

/**
 * Great-circle distance in metres (Haversine).
 */
function haversineDistanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371000.0;
    $latFrom     = deg2rad($lat1);
    $latTo       = deg2rad($lat2);
    $latDelta    = deg2rad($lat2 - $lat1);
    $lngDelta    = deg2rad($lng2 - $lng1);

    $a = sin($latDelta / 2) ** 2
        + cos($latFrom) * cos($latTo) * sin($lngDelta / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

function getEventVenueCoordinates(array $event): ?array
{
    $lat = normalizeCoordinate(isset($event['venue_lat']) ? (string) $event['venue_lat'] : null);
    $lng = normalizeCoordinate(isset($event['venue_lng']) ? (string) $event['venue_lng'] : null);

    if ($lat === null || $lng === null) {
        return null;
    }

    return ['lat' => $lat, 'lng' => $lng];
}

function eventVenueIsConfigured(array $event): bool
{
    $eircode = strtoupper(trim(preg_replace('/\s+/', ' ', (string) ($event['venue_eircode'] ?? ''))));
    $eircodeOk = (bool) preg_match('/^[A-Z0-9]{3}\s?[A-Z0-9]{4}$/', $eircode);

    return getEventVenueCoordinates($event) !== null && $eircodeOk;
}

/**
 * @return array{lat: float, lng: float}|null
 */
function geocodeVenueEircode(string $eircode, ?PDO $pdo = null): ?array
{
    $eircode = strtoupper(trim(preg_replace('/\s+/', ' ', $eircode)));
    if (!preg_match('/^[A-Z0-9]{3}\s?[A-Z0-9]{4}$/', $eircode)) {
        return null;
    }

    $apiKey = getGoogleMapsApiKey($pdo);
    if ($apiKey === '') {
        return null;
    }

    $query = $eircode . ', Ireland';
    $url   = 'https://maps.googleapis.com/maps/api/geocode/json?address='
        . rawurlencode($query)
        . '&components=country:IE&key='
        . rawurlencode($apiKey);

    $context = stream_context_create(['http' => ['timeout' => 8]]);
    $body    = @file_get_contents($url, false, $context);
    if ($body === false) {
        return null;
    }

    $data = json_decode($body, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'OK' || empty($data['results'][0]['geometry']['location'])) {
        return null;
    }

    $loc = $data['results'][0]['geometry']['location'];
    $lat = normalizeCoordinate(isset($loc['lat']) ? (string) $loc['lat'] : null);
    $lng = normalizeCoordinate(isset($loc['lng']) ? (string) $loc['lng'] : null);

    if ($lat === null || $lng === null) {
        return null;
    }

    return ['lat' => $lat, 'lng' => $lng];
}

function getEventSigninRadiusMeters(array $event, ?PDO $pdo = null): int
{
    if ($pdo === null && function_exists('getDB')) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            $pdo = null;
        }
    }

    if ($pdo !== null) {
        require_once __DIR__ . '/feature-flags.php';

        if (isFeatureEnabled($pdo, 'feature_gps_attendance_v2')) {
            $stored = $event['signin_radius_m'] ?? null;
            if ($stored === null || $stored === '' || (int) $stored <= 0) {
                return EVENT_SIGNIN_RADIUS_DEFAULT_M;
            }

            return (int) $stored;
        }
    }

    return EVENT_SIGNIN_RADIUS_LEGACY_M;
}

/**
 * Human-readable radius for admin copy (respects feature flag).
 */
function formatEventSigninRadiusLabel(array $event, ?PDO $pdo = null): string
{
    $meters = getEventSigninRadiusMeters($event, $pdo);

    return $meters >= 1000 && $meters % 1000 === 0
        ? ($meters / 1000) . ' km'
        : $meters . ' m';
}
