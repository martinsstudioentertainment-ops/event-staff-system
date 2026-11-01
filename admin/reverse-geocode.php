<?php

declare(strict_types=1);

/**
 * Admin: GPS → Eircode / address (stand at venue, use phone location).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/maps-core.php';

requireAdminCapability('events');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$lat = isset($_GET['lat']) ? (float) $_GET['lat'] : (isset($_POST['lat']) ? (float) $_POST['lat'] : 0.0);
$lng = isset($_GET['lng']) ? (float) $_GET['lng'] : (isset($_POST['lng']) ? (float) $_POST['lng'] : 0.0);

if ($lat === 0.0 && $lng === 0.0) {
    echo json_encode(['ok' => false, 'error' => 'Latitude and longitude are required.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!googleMapsEnabled(getDB())) {
    echo json_encode(['ok' => false, 'error' => 'Google Maps API key is not configured in Settings.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = reverseGeocodeVenueCoords($lat, $lng, getDB());

if ($result === null) {
    echo json_encode([
        'ok'  => true,
        'lat' => $lat,
        'lng' => $lng,
        'eircode' => '',
        'formatted_address' => '',
        'warning' => 'GPS captured. Could not resolve Eircode automatically — enter it if you know it, or adjust the pin.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'ok'                => true,
    'lat'               => $result['lat'],
    'lng'               => $result['lng'],
    'eircode'           => $result['eircode'],
    'formatted_address' => $result['formatted_address'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
