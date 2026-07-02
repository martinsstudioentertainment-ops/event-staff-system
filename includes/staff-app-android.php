<?php

declare(strict_types=1);

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/site-urls.php';

function staffAppAndroidApkRelativePath(PDO $pdo): string
{
    return trim(getSetting($pdo, 'mobile_android_apk_path', ''));
}

function staffAppAndroidApkAbsolutePath(PDO $pdo): string
{
    $relative = staffAppAndroidApkRelativePath($pdo);
    if ($relative === '') {
        return '';
    }

    $root = dirname(__DIR__);
    $full = $root . '/' . ltrim(str_replace('\\', '/', $relative), '/');

    if (!str_starts_with(str_replace('\\', '/', $full), str_replace('\\', '/', $root . '/storage/mobile/android/'))) {
        return '';
    }

    return is_file($full) ? $full : '';
}

function staffAppAndroidDownloadUrl(PDO $pdo): string
{
    if (staffAppAndroidApkAbsolutePath($pdo) === '') {
        return '';
    }

    return rtrim(getRegistrationSiteUrl($pdo), '/') . '/staff-app-download.php';
}

function staffAppAndroidVersionLabel(PDO $pdo): string
{
    $label = trim(getSetting($pdo, 'mobile_portal_version_label', ''));

    return $label !== '' ? $label : trim(getSetting($pdo, 'mobile_min_app_version', '1.0.0'));
}

/**
 * @return array{ok: bool, error?: string}
 */
function staffAppAndroidStreamDownload(PDO $pdo): array
{
    $path = staffAppAndroidApkAbsolutePath($pdo);
    if ($path === '') {
        return ['ok' => false, 'error' => 'Android app package is not available yet.'];
    }

    $filename = basename($path);
    $size     = filesize($path);
    if ($size === false) {
        return ['ok' => false, 'error' => 'Unable to read app package.'];
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Content-Length: ' . (string) $size);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
        return ['ok' => true];
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return ['ok' => false, 'error' => 'Unable to open app package.'];
    }

    fpassthru($handle);
    fclose($handle);

    return ['ok' => true];
}
