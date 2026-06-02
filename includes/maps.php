<?php

require_once __DIR__ . '/settings-repository.php';

/** Venue sign-in geofence radius in metres (fixed). */
const EVENT_SIGNIN_RADIUS_M = 100;

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

function getEventSigninRadiusMeters(array $event): int
{
    return EVENT_SIGNIN_RADIUS_M;
}
