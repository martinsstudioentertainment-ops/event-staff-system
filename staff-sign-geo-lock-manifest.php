<?php

declare(strict_types=1);

/**
 * Admin Geo Lock dashboard — installable home-screen app (admin phone only).
 */

header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex, nofollow');

$manifest = [
    'name' => 'Olasentra Geo Lock',
    'short_name' => 'Geo Lock',
    'description' => 'Admin phone dashboard — lock event sign-in geo from this device',
    'start_url' => '/staff-sign-geo-lock.php?app=1',
    'scope' => '/',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'background_color' => '#0b1f17',
    'theme_color' => '#0b1f17',
    'icons' => [
        [
            'src' => '/api/pwa-icon.php?size=192',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => '/api/pwa-icon.php?size=512',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable',
        ],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
