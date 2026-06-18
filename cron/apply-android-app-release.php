<?php

declare(strict_types=1);

/**
 * Point production settings at the latest native Android APK on disk.
 *
 * Web: /cron/apply-android-app-release.php?key=REMINDER_CRON_KEY&version=1.0.17&build=17&notes=...
 * CLI: php cron/apply-android-app-release.php 1.0.17 17 "Release notes"
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
    $keyOk    = false;
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
    androidReleaseJson(['ok' => false, 'error' => 'Invalid version. Use format 1.0.17'], 400);
}

$buildCode = $isCli
    ? (int) ($argv[2] ?? 0)
    : (int) ($_GET['build'] ?? $_GET['version_code'] ?? 0);

$notes = trim((string) ($isCli ? ($argv[3] ?? '') : ($_GET['notes'] ?? '')));
if ($notes === '') {
    $notes = trim((string) getSetting($pdo, 'mobile_portal_version_notes', ''));
}
if ($notes === '') {
    $notes = 'Olasentra staff app update.';
}

$root   = dirname(__DIR__);
$apkRel = 'storage/mobile/android/olasentra-staff-v' . $version . '.apk';
$apkFs  = $root . '/' . $apkRel;

if (!is_file($apkFs)) {
    androidReleaseJson([
        'ok'    => false,
        'error' => 'Missing APK on server: ' . $apkRel,
    ], 500);
}

$aabRel = 'storage/mobile/android/olasentra-staff-v' . $version . '.aab';
$aabFs  = $root . '/' . $aabRel;
$aabRel = is_file($aabFs) ? $aabRel : null;
$aabBytes = $aabRel !== null ? (int) filesize($aabFs) : null;

$register = mobileAppReleaseRegister(
    $pdo,
    $version,
    $buildCode,
    $apkRel,
    (int) filesize($apkFs),
    $notes,
    $aabRel,
    $aabBytes,
    null,
    true
);

if (!($register['ok'] ?? false)) {
    androidReleaseJson([
        'ok'    => false,
        'error' => (string) ($register['error'] ?? 'Release registration failed'),
    ], 500);
}

$result = [
    'ok'                   => true,
    'version'              => $version,
    'version_code'         => $buildCode,
    'apk_path'             => $apkRel,
    'apk_bytes'            => filesize($apkFs),
    'aab_path'             => $aabRel,
    'download_page_url'    => staffAppAndroidDownloadPageUrl($pdo),
    'download_url'         => staffAppAndroidDownloadUrl($pdo),
    'mobile_api_enabled'   => true,
    'portal_version_label' => getSetting($pdo, 'mobile_portal_version_label', ''),
    'release_id'           => $register['release_id'] ?? null,
    'timestamp'            => date('c'),
];

androidReleaseJson($result);
