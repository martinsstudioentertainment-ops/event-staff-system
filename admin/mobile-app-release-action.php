<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/staff-app-android.php';
require_once __DIR__ . '/../includes/site-urls.php';

requireAdminCapability('settings');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

if (!verifyCsrf((string) ($_POST['csrf_token'] ?? ''))) {
    $_SESSION['mobile_release_flash_error'] = 'Session expired. Please try again.';
    header('Location: mobile-app-releases.php');
    exit;
}

$pdo  = getDB();
$task = trim((string) ($_POST['task'] ?? ''));

if ($task === 'rollback') {
    $releaseId = (int) ($_POST['release_id'] ?? 0);
    $result    = mobileAppReleaseSetCurrent($pdo, $releaseId);
    if ($result['ok'] ?? false) {
        $_SESSION['mobile_release_flash_success'] = 'Previous release is now the active download.';
    } else {
        $_SESSION['mobile_release_flash_error'] = (string) ($result['error'] ?? 'Rollback failed.');
    }
    header('Location: mobile-app-releases.php');
    exit;
}

if ($task !== 'upload') {
    $_SESSION['mobile_release_flash_error'] = 'Unknown action.';
    header('Location: mobile-app-releases.php');
    exit;
}

$versionName = trim((string) ($_POST['version_name'] ?? ''));
$versionCode = (int) ($_POST['version_code'] ?? 0);
$notes       = trim((string) ($_POST['release_notes'] ?? ''));
$setCurrent  = !empty($_POST['set_current']);

$apkUpload = $_FILES['apk_file'] ?? null;
$aabUpload = $_FILES['aab_file'] ?? null;

if (!is_array($apkUpload) || (int) ($apkUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    $_SESSION['mobile_release_flash_error'] = 'APK file is required.';
    header('Location: mobile-app-releases.php');
    exit;
}

$apkStored = mobileAppReleaseStoreUploadedFile($apkUpload, 'apk');
if (!($apkStored['ok'] ?? false)) {
    $_SESSION['mobile_release_flash_error'] = (string) ($apkStored['error'] ?? 'APK upload failed.');
    header('Location: mobile-app-releases.php');
    exit;
}

$aabRelative = null;
$aabBytes    = null;
if (is_array($aabUpload) && (int) ($aabUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $aabStored = mobileAppReleaseStoreUploadedFile($aabUpload, 'aab');
    if (!($aabStored['ok'] ?? false)) {
        $_SESSION['mobile_release_flash_error'] = (string) ($aabStored['error'] ?? 'AAB upload failed.');
        header('Location: mobile-app-releases.php');
        exit;
    }
    $aabRelative = (string) $aabStored['relative'];
    $aabBytes    = (int) ($aabStored['bytes'] ?? 0);
}

$adminUser = getAdminUser();
$register  = mobileAppReleaseRegister(
    $pdo,
    $versionName,
    $versionCode,
    (string) $apkStored['relative'],
    (int) ($apkStored['bytes'] ?? 0),
    $notes,
    $aabRelative,
    $aabBytes,
    isset($adminUser['id']) ? (int) $adminUser['id'] : null,
    $setCurrent
);

if ($register['ok'] ?? false) {
  $_SESSION['mobile_release_flash_success'] = $setCurrent
      ? 'Release uploaded and set as the active staff download.'
      : 'Release archived. Use Rollback to make it the active download.';
} else {
    $_SESSION['mobile_release_flash_error'] = (string) ($register['error'] ?? 'Upload failed.');
}

header('Location: mobile-app-releases.php');
exit;
