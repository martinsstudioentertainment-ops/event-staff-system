<?php

declare(strict_types=1);

/**
 * Gate check / Attendant web app manifest (for PWA + Android package with Location).
 * https://admin.olasentra.com/attendant-manifest.php
 */

header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex, nofollow');

$manifest = [
    'id' => '/attendant.php',
    'name' => 'Olasentra Gate Check',
    'short_name' => 'Gate Check',
    'description' => 'Admin gate check, PSA lookup, and venue geo lock for Olasentra events',
    'start_url' => '/attendant.php?app=1',
    'scope' => '/',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'background_color' => '#f3f0ea',
    'theme_color' => '#0b3d2e',
    'lang' => 'en-IE',
    'dir' => 'ltr',
    'categories' => ['business', 'productivity'],
    'icons' => [
        [
            'src' => '/api/pwa-icon.php?size=192&v=attendant',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => '/api/pwa-icon.php?size=512&v=attendant',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable',
        ],
    ],
    // Hint for packaging tools that geolocation is required.
    'permissions' => ['geolocation'],
];

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
