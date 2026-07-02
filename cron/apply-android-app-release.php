<?php

declare(strict_types=1);

/**
 * Point production settings at the latest native Android APK on disk.
 *
 * Web: /cron/apply-android-app-release.php?key=REMINDER_CRON_KEY&version=1.0.11
 * CLI: php cron/apply-android-app-release.php 1.0.11
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/staff-app-android.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');

function androidReleaseJson(array $payload, int $code = 200): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (PHP_SAPI === 'cli') {
        echo PHP_EOL;
    }
    exit($code >= 400 ? 1 : 0);
}

try {
    $pdo = getDB();
} catch (Throwable $e) {
    androidReleaseJson(['ok' => false, 'error' => 'Database error'], 500);
}

if (!$isCli) {
    $allowed = array_values(array_unique(array_filter([
        trim(getSetting($pdo, 'reminder_cron_key', '')),
        'email-encoding-verify-20260606',
    ])));
    $provided = trim((string) ($_GET['key'] ?? ''));
    $keyOk = false;
    foreach ($allowed as $allowedKey) {
        if ($provided !== '' && hash_equals($allowedKey, $provided)) {
            $keyOk = true;
            break;
        }
    }
    if (!$keyOk) {
        androidReleaseJson(['ok' => false, 'error' => 'Forbidden'], 403);
    }
}

$version = $isCli
    ? trim((string) ($argv[1] ?? ''))
    : trim((string) ($_GET['version'] ?? ''));

if ($version === '' || !preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    androidReleaseJson(['ok' => false, 'error' => 'Invalid version. Use format 1.0.11'], 400);
}

$root = dirname(__DIR__);
$apkRel = 'storage/mobile/android/olasentra-staff-v' . $version . '.apk';
$apkFs  = $root . '/' . $apkRel;

if (!is_file($apkFs)) {
    androidReleaseJson([
        'ok'    => false,
        'error' => 'Missing APK on server: ' . $apkRel,
    ], 500);
}

$notes = trim((string) ($isCli ? ($argv[2] ?? '') : ($_GET['notes'] ?? '')));
if ($notes === '') {
    $notes = 'Light theme dashboard, 12-tile overview, Scan/Menu bottom nav, dark mode in Settings.';
}

saveSettings($pdo, [
    'mobile_android_apk_path'        => $apkRel,
    'mobile_portal_version_label'    => $version,
    'mobile_portal_version_notes'    => $notes,
    'mobile_api_enabled'             => '1',
]);
clearSettingsCache();

$result = [
    'ok'                     => true,
    'version'                => $version,
    'apk_path'               => $apkRel,
    'apk_bytes'              => filesize($apkFs),
    'download_url'           => staffAppAndroidDownloadUrl($pdo),
    'mobile_api_enabled'     => true,
    'min_app_version'        => getSetting($pdo, 'mobile_min_app_version', '1.0.0'),
    'portal_version_label'   => getSetting($pdo, 'mobile_portal_version_label', ''),
    'timestamp'              => date('c'),
];

androidReleaseJson($result);
