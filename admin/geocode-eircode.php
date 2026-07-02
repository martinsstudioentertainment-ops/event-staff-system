<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/maps.php';

requireAdminCapability('events');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$eircode = trim((string) ($_GET['eircode'] ?? ''));

if ($eircode === '') {
    echo json_encode(['ok' => false, 'error' => 'Eircode is required.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!googleMapsEnabled(getDB())) {
    echo json_encode(['ok' => false, 'error' => 'Google Maps API key is not configured in Settings.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$coords = geocodeVenueEircode($eircode, getDB());

if ($coords === null) {
    echo json_encode(['ok' => false, 'error' => 'Could not find GPS for that Eircode.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok'  => true,
    'lat' => $coords['lat'],
    'lng' => $coords['lng'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
