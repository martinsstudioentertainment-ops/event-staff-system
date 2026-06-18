<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/site-urls.php';

$pdo        = getDB();
$siteName   = getSiteName($pdo);
$shortName  = mb_strlen($siteName) > 10 ? mb_substr($siteName, 0, 10) . '…' : $siteName;
$shortName  = $shortName !== '' ? $shortName . ' Admin' : 'Admin';
$baseUrl    = rtrim(getAdminSiteUrl($pdo), '/');
$startUrl   = isAdminSubdomain() ? $baseUrl . '/admin/dashboard.php' : $baseUrl . '/dashboard.php';
$scope      = isAdminSubdomain() ? $baseUrl . '/admin/' : $baseUrl . '/';
$themeColor = getThemeColor($pdo);
$icon192    = $baseUrl . '/api/pwa-icon.php?size=192';
$icon512    = $baseUrl . '/api/pwa-icon.php?size=512';

header('Content-Type: application/manifest+json; charset=utf-8');

echo json_encode([
    'id'                  => $startUrl . '#admin-console',
    'name'                => $siteName . ' — Admin',
    'short_name'          => $shortName,
    'description'         => 'Manage staff, events, attendance, and messages on your phone',
    'start_url'           => $startUrl,
    'scope'               => $scope,
    'display'             => 'standalone',
    'display_override'    => ['standalone', 'minimal-ui'],
    'background_color'    => '#0a0f1a',
    'theme_color'         => $themeColor,
    'orientation'         => 'any',
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
            'src'     => $icon512,
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
            'name'        => 'Dashboard',
            'short_name'  => 'Dashboard',
            'url'         => $startUrl,
        ],
        [
            'name'        => 'Staff queue',
            'short_name'  => 'Staff',
            'url'         => (isAdminSubdomain() ? $baseUrl . '/admin/' : $baseUrl . '/') . 'staff.php',
        ],
        [
            'name'        => 'Messages',
            'short_name'  => 'Inbox',
            'url'         => (isAdminSubdomain() ? $baseUrl . '/admin/' : $baseUrl . '/') . 'staff-inbox.php',
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
