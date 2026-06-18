<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/workforce/preference-locations.php';

requireAdminCapability('settings');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf((string) ($_POST['csrf_token'] ?? ''))) {
    $_SESSION['pref_loc_flash_error'] = 'Session expired. Please try again.';
    header('Location: settings-preference-locations.php');
    exit;
}

$pdo  = getDB();
$task = trim((string) ($_POST['task'] ?? ''));

if ($task === 'toggle') {
    $id = (int) ($_POST['id'] ?? 0);
    preferenceLocationSetActive($pdo, $id, ((string) ($_POST['is_active'] ?? '1')) === '1');
    $_SESSION['pref_loc_flash_success'] = 'Location status updated.';
    header('Location: settings-preference-locations.php');
    exit;
}

if ($task === 'save') {
    $id = (int) ($_POST['id'] ?? 0);
    $result = preferenceLocationSave($pdo, $_POST, $id > 0 ? $id : null);
    if ($result['ok'] ?? false) {
        $_SESSION['pref_loc_flash_success'] = $id > 0 ? 'Location updated.' : 'Location added.';
    } else {
        $_SESSION['pref_loc_flash_error'] = (string) ($result['error'] ?? 'Save failed.');
    }
    header('Location: settings-preference-locations.php');
    exit;
}

$_SESSION['pref_loc_flash_error'] = 'Unknown action.';
header('Location: settings-preference-locations.php');
exit;
