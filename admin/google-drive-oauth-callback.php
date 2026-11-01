<?php
/**
 * Google OAuth return URL (admin subdomain → /google-drive-oauth-callback.php via .htaccess).
 * Completes token exchange before admin login check so a brief session gap does not drop the code.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/google-drive-oauth.php';
require_once __DIR__ . '/../includes/google-oauth-errors.php';

$pdo = getDB();
googleOAuthLogCallbackErrors('admin_drive');

$returnPath = (string) ($_SESSION['google_drive_oauth_return'] ?? '');
unset($_SESSION['google_drive_oauth_return']);
if ($returnPath !== 'google-form-integrations.php') {
    $returnPath = 'settings-production.php';
}

$redirect = static function (string $query) use ($returnPath): void {
    if (!isAdminLoggedIn()) {
        header('Location: login.php?' . $query);
        exit;
    }
    $hash = $returnPath === 'settings-production.php' ? '#google-sheets' : '';
    header('Location: ' . $returnPath . '?' . $query . $hash);
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

$oauthError = googleOAuthSanitizeCode((string) ($_GET['error'] ?? ''));
if ($oauthError !== '') {
    $_SESSION['google_oauth_error'] = googleOAuthUserMessage($oauthError, 'admin_drive');
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
