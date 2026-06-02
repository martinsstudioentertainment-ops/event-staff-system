<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/google-drive-oauth.php';

requireAdminCapability('settings');

$pdo = getDB();

$state = (string) ($_GET['state'] ?? '');
$saved = (string) ($_SESSION['google_drive_oauth_state'] ?? '');
unset($_SESSION['google_drive_oauth_state']);

if ($state === '' || $saved === '' || !hash_equals($saved, $state)) {
    header('Location: settings-production.php?google_oauth=invalid_state#google-sheets');
    exit;
}

if (isset($_GET['error'])) {
    header('Location: settings-production.php?google_oauth=denied#google-sheets');
    exit;
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    header('Location: settings-production.php?google_oauth=no_code#google-sheets');
    exit;
}

$result = googleDriveOAuthExchangeCode($pdo, $code);
$param  = $result['ok'] ? 'google_oauth=connected' : 'google_oauth=error';
if (!$result['ok']) {
    $_SESSION['google_oauth_error'] = $result['message'];
}

header('Location: settings-production.php?' . $param . '#google-sheets');
exit;
