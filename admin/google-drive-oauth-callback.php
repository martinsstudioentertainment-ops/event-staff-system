<?php
/**
 * Google OAuth return URL (admin subdomain → /google-drive-oauth-callback.php via .htaccess).
 * Completes token exchange before admin login check so a brief session gap does not drop the code.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/google-drive-oauth.php';

$pdo = getDB();

$redirect = static function (string $query): void {
    $path = isAdminLoggedIn()
        ? 'settings-production.php?' . $query . '#google-sheets'
        : 'login.php?' . $query;
    header('Location: ' . $path);
    exit;
};

$state = (string) ($_GET['state'] ?? '');
$saved = (string) ($_SESSION['google_drive_oauth_state'] ?? '');
unset($_SESSION['google_drive_oauth_state']);

$stateOk = $state !== ''
    && (
        ($saved !== '' && hash_equals($saved, $state))
        || googleDriveOAuthValidateState($pdo, $state)
    );

if (!$stateOk) {
    $_SESSION['google_oauth_error'] = 'Sign-in session expired. Click Connect Google account again (stay logged in to admin).';
    $redirect('google_oauth=invalid_state');
}

if (isset($_GET['error'])) {
    $_SESSION['google_oauth_error'] = 'Google sign-in was cancelled or denied.';
    $redirect('google_oauth=denied');
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    $_SESSION['google_oauth_error'] = 'Google did not return an authorization code.';
    $redirect('google_oauth=no_code');
}

$result = googleDriveOAuthExchangeCode($pdo, $code);
if ($result['ok']) {
    $_SESSION['google_oauth_success'] = $result['message'];
    $redirect('google_oauth=connected');
}

$_SESSION['google_oauth_error'] = $result['message'];
$redirect('google_oauth=error');
