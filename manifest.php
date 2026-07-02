<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/site-urls.php';

$pdo        = getDB();
$siteName   = getSiteName($pdo);
$shortName  = mb_strlen($siteName) > 12 ? mb_substr($siteName, 0, 12) : $siteName;
$baseUrl    = rtrim(getRegistrationSiteUrl($pdo), '/');
/** Olasentra v3 brand — aligned with staff-app-v3.css tokens (Phase 10). */
$themeColor      = '#F58220';
$backgroundColor = '#0B1020';
$icon192    = $baseUrl . '/assets/icons/pwa/icon-192.png';
$icon512    = $baseUrl . '/assets/icons/pwa/icon-512.png';
$iconMask   = $baseUrl . '/assets/icons/pwa/icon-maskable-512.png';

header('Content-Type: application/manifest+json; charset=utf-8');

echo json_encode([
    'id'                  => $baseUrl . '/staff-app.php',
    'name'                => $siteName,
    'short_name'          => $shortName,
    'description'         => 'Event staff registration, check-in, and status on your phone',
    'start_url'           => $baseUrl . '/staff-app.php',
    'scope'               => $baseUrl . '/',
    'display'             => 'standalone',
    'display_override'    => ['standalone', 'minimal-ui'],
    'background_color'    => $backgroundColor,
    'theme_color'         => $themeColor,
    'orientation'         => 'portrait-primary',
    'prefer_related_applications' => false,
    'categories'          => ['business', 'productivity'],
    'icons'               => [
        [
            'src'     => $icon192,
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => $icon512,
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => $iconMask,
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'maskable',
        ],
        [
            'src'     => $baseUrl . '/assets/icons/icon.svg',
            'sizes'   => 'any',
            'type'    => 'image/svg+xml',
            'purpose' => 'any',
        ],
    ],
    'shortcuts'           => [
        [
            'name'        => 'Register',
            'short_name'  => 'Register',
            'url'         => $baseUrl . '/index.php',
            'description' => 'Apply for event shifts',
        ],
        [
            'name'        => 'My status',
            'short_name'  => 'Status',
            'url'         => $baseUrl . '/status.php',
            'description' => 'View registration status',
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
