<?php

declare(strict_types=1);

require_once __DIR__ . '/../../settings-repository.php';
require_once __DIR__ . '/../../site-urls.php';

function mobilePortalAppName(PDO $pdo): string
{
    $name = trim(getSetting($pdo, 'mobile_portal_app_name', 'Olasentra'));

    return $name !== '' ? $name : 'Olasentra';
}

function mobilePortalDefaultTheme(PDO $pdo): string
{
    $theme = strtolower(trim(getSetting($pdo, 'mobile_portal_default_theme', 'dark')));

    return in_array($theme, ['dark', 'light'], true) ? $theme : 'dark';
}

function mobilePortalPublicAssetUrl(PDO $pdo, string $settingKey): ?string
{
    $relative = trim(getSetting($pdo, $settingKey, ''));
    if ($relative === '') {
        return null;
    }

    $relative = ltrim(str_replace(['\\', '..'], ['/', ''], $relative), '/');
    $root     = dirname(__DIR__, 3);
    $full     = $root . '/' . $relative;

    if (!is_file($full)) {
        return null;
    }

    return rtrim(getRegistrationSiteUrl($pdo), '/') . '/' . $relative;
}

function mobilePortalColor(PDO $pdo, string $settingKey, string $fallback): string
{
    $value = trim(getSetting($pdo, $settingKey, $fallback));
    if ($value === '') {
        return $fallback;
    }

    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $value) !== 1) {
        return $fallback;
    }

    return strtoupper($value);
}

/**
 * @return list<array{title: string, body: string|null}>
 */
function mobilePortalAnnouncements(PDO $pdo): array
{
    $rows = mobilePortalDecodeJsonList(getSetting($pdo, 'mobile_portal_announcements_json', '[]'));
    $out  = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            continue;
        }

        $body = trim((string) ($row['body'] ?? ''));
        $out[] = [
            'title' => $title,
            'body'  => $body !== '' ? $body : null,
        ];
    }

    return $out;
}

/**
 * @return list<array{label: string, url: string}>
 */
function mobilePortalHelpLinks(PDO $pdo): array
{
    $rows = mobilePortalDecodeJsonList(getSetting($pdo, 'mobile_portal_help_links_json', '[]'));
    $out  = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $label = trim((string) ($row['label'] ?? ''));
        $url   = trim((string) ($row['url'] ?? ''));
        if ($label === '' || $url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            continue;
        }

        $out[] = ['label' => $label, 'url' => $url];
    }

    return $out;
}

/**
 * @return list<mixed>
 */
function mobilePortalDecodeJsonList(string $json): array
{
    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
}

function mobilePortalGetPublicConfig(PDO $pdo): array
{
    $bannerTitle = trim(getSetting($pdo, 'mobile_portal_banner_title', ''));
    $bannerBody  = trim(getSetting($pdo, 'mobile_portal_banner_body', ''));

    return [
        'app_name' => mobilePortalAppName($pdo),
        'branding' => [
            'logo_url'           => mobilePortalPublicAssetUrl($pdo, 'mobile_portal_logo_path'),
            'splash_logo_url'    => mobilePortalPublicAssetUrl($pdo, 'mobile_portal_splash_logo_path'),
            'login_logo_url'     => mobilePortalPublicAssetUrl($pdo, 'mobile_portal_login_logo_path'),
            'dashboard_logo_url' => mobilePortalPublicAssetUrl($pdo, 'mobile_portal_dashboard_logo_path'),
            'welcome_image_url'  => mobilePortalPublicAssetUrl($pdo, 'mobile_portal_welcome_image_path'),
            'primary_color'      => mobilePortalColor($pdo, 'mobile_portal_primary_color', '#1B1B1F'),
            'accent_color'       => mobilePortalColor($pdo, 'mobile_portal_accent_color', '#E85D04'),
        ],
        'banner' => [
            'title'     => $bannerTitle !== '' ? $bannerTitle : null,
            'body'      => $bannerBody !== '' ? $bannerBody : null,
            'image_url' => mobilePortalPublicAssetUrl($pdo, 'mobile_portal_banner_image_path'),
        ],
        'announcements' => mobilePortalAnnouncements($pdo),
        'help_links'    => mobilePortalHelpLinks($pdo),
        'contact'       => [
            'email' => trim(getSetting($pdo, 'mobile_portal_contact_email', '')) ?: null,
            'phone' => trim(getSetting($pdo, 'mobile_portal_contact_phone', '')) ?: null,
        ],
        'version' => [
            'label'                => trim(getSetting($pdo, 'mobile_portal_version_label', '')) ?: null,
            'notes'                => trim(getSetting($pdo, 'mobile_portal_version_notes', '')) ?: null,
            'force_update_message' => trim(getSetting($pdo, 'mobile_portal_force_update_message', '')) ?: null,
        ],
        'maintenance' => [
            'enabled' => getSetting($pdo, 'mobile_portal_maintenance_enabled', '0') === '1',
            'message' => trim(getSetting($pdo, 'mobile_portal_maintenance_message', '')) ?: null,
        ],
        'theme' => [
            'default'           => mobilePortalDefaultTheme($pdo),
            'allow_user_toggle' => getSetting($pdo, 'mobile_portal_allow_theme_toggle', '0') === '1',
        ],
    ];
}
