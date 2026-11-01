<?php

/**
 * Geo / maps helpers (canonical copy).
 * Prefer this when includes/maps.php is missing or unreadable on disk.
 */

require_once __DIR__ . '/settings-repository.php';

if (!defined('EVENT_SIGNIN_RADIUS_LEGACY_M')) {
    /** Legacy venue sign-in geofence radius (feature_gps_attendance_v2 OFF). */
    define('EVENT_SIGNIN_RADIUS_LEGACY_M', 100);
}

if (!defined('EVENT_SIGNIN_RADIUS_DEFAULT_M')) {
    /** Default sign-in radius when events.signin_radius_m is null (feature_gps_attendance_v2 ON). */
    define('EVENT_SIGNIN_RADIUS_DEFAULT_M', 1000);
}

if (!defined('EVENT_SIGNIN_RADIUS_MIN_M')) {
    define('EVENT_SIGNIN_RADIUS_MIN_M', 50);
}

if (!defined('EVENT_SIGNIN_RADIUS_MAX_M')) {
    define('EVENT_SIGNIN_RADIUS_MAX_M', 5000);
}

if (!defined('EVENT_SIGNIN_RADIUS_M')) {
    /** @deprecated Use EVENT_SIGNIN_RADIUS_LEGACY_M */
    define('EVENT_SIGNIN_RADIUS_M', EVENT_SIGNIN_RADIUS_LEGACY_M);
}

if (!function_exists('getGoogleMapsApiKey')) {
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
}

if (!function_exists('googleMapsEnabled')) {
    function googleMapsEnabled(?PDO $pdo = null): bool
    {
        return getGoogleMapsApiKey($pdo) !== '';
    }
}

if (!function_exists('normalizeCoordinate')) {
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
}

if (!function_exists('buildGoogleMapsLink')) {
    function buildGoogleMapsLink(?float $lat, ?float $lng): string
    {
        if ($lat === null || $lng === null) {
            return '';
        }

        return 'https://www.google.com/maps?q=' . rawurlencode($lat . ',' . $lng);
    }
}

if (!function_exists('haversineDistanceMeters')) {
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
}

if (!function_exists('getEventVenueCoordinates')) {
    function getEventVenueCoordinates(array $event): ?array
    {
        $lat = normalizeCoordinate(isset($event['venue_lat']) ? (string) $event['venue_lat'] : null);
        $lng = normalizeCoordinate(isset($event['venue_lng']) ? (string) $event['venue_lng'] : null);

        if ($lat === null || $lng === null) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }
}

if (!function_exists('eventVenueIsConfigured')) {
    function eventVenueIsConfigured(array $event): bool
    {
        // GPS pin is enough — Eircode is optional when set via Use my location.
        return getEventVenueCoordinates($event) !== null;
    }
}

if (!function_exists('geocodeVenueEircode')) {
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
}

if (!function_exists('reverseGeocodeVenueCoords')) {
    /**
     * GPS → address / Eircode (admin standing at the venue).
     *
     * @return array{lat: float, lng: float, eircode: string, formatted_address: string}|null
     */
    function reverseGeocodeVenueCoords(float $lat, float $lng, ?PDO $pdo = null): ?array
    {
        $latN = normalizeCoordinate((string) $lat);
        $lngN = normalizeCoordinate((string) $lng);
        if ($latN === null || $lngN === null) {
            return null;
        }

        $apiKey = getGoogleMapsApiKey($pdo);
        if ($apiKey === '') {
            return null;
        }

        $context = stream_context_create(['http' => ['timeout' => 10]]);
        $urls = [
            'https://maps.googleapis.com/maps/api/geocode/json?latlng='
                . rawurlencode($latN . ',' . $lngN)
                . '&result_type=street_address|premise|postal_code|route'
                . '&key=' . rawurlencode($apiKey),
            'https://maps.googleapis.com/maps/api/geocode/json?latlng='
                . rawurlencode($latN . ',' . $lngN)
                . '&key=' . rawurlencode($apiKey),
        ];

        $eircode = '';
        $formatted = '';
        foreach ($urls as $url) {
            $body = @file_get_contents($url, false, $context);
            if ($body === false) {
                continue;
            }
            $data = json_decode($body, true);
            if (!is_array($data) || ($data['status'] ?? '') !== 'OK' || empty($data['results'])) {
                continue;
            }
            foreach ($data['results'] as $result) {
                if (!is_array($result)) {
                    continue;
                }
                if ($formatted === '' && !empty($result['formatted_address'])) {
                    $formatted = (string) $result['formatted_address'];
                }
                // Prefer full Irish Eircode (7 chars) from postal_code components.
                foreach ($result['address_components'] ?? [] as $component) {
                    if (!is_array($component)) {
                        continue;
                    }
                    $types = $component['types'] ?? [];
                    if (!is_array($types) || !in_array('postal_code', $types, true)) {
                        continue;
                    }
                    $candidate = strtoupper(trim(preg_replace('/\s+/', '', (string) ($component['long_name'] ?? $component['short_name'] ?? ''))));
                    if (preg_match('/^[A-Z0-9]{7}$/', $candidate)) {
                        $eircode = substr($candidate, 0, 3) . ' ' . substr($candidate, 3);
                        break 3;
                    }
                }
                // Also scan formatted address for Eircode pattern (e.g. D02 X285).
                if ($eircode === '' && !empty($result['formatted_address'])) {
                    if (preg_match('/\b([A-Z0-9]{3})\s?([A-Z0-9]{4})\b/i', (string) $result['formatted_address'], $m)) {
                        $eircode = strtoupper($m[1] . ' ' . $m[2]);
                        break 2;
                    }
                }
            }
            if ($eircode !== '') {
                break;
            }
        }

        if ($formatted === '' && $eircode === '') {
            return [
                'lat'               => $latN,
                'lng'               => $lngN,
                'eircode'           => '',
                'formatted_address' => '',
            ];
        }

        return [
            'lat'               => $latN,
            'lng'               => $lngN,
            'eircode'           => $eircode,
            'formatted_address' => $formatted,
        ];
    }
}

if (!function_exists('getEventSigninRadiusMeters')) {
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
}

if (!function_exists('formatEventSigninRadiusLabel')) {
    function formatEventSigninRadiusLabel(array $event, ?PDO $pdo = null): string
    {
        $meters = getEventSigninRadiusMeters($event, $pdo);

        return $meters >= 1000 && $meters % 1000 === 0
            ? ($meters / 1000) . ' km'
            : $meters . ' m';
    }
}
