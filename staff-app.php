<?php

require_once __DIR__ . '/config.php';
initSecureSession();

require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/company.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/staff-profile-gate.php';
require_once __DIR__ . '/includes/staff-portal-session.php';
require_once __DIR__ . '/includes/staff-google-oauth.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/staff-app-v3-pages.php';

$pdo = getDB();
bootstrapAppLocale($pdo);

if (isset($_GET['signout'])) {
    header('Location: staff-signout.php?return=staff-app.php');
    exit;
}

$signedOutNotice = '';
if (isset($_GET['signed_out'])) {
    $signedOutNotice = ($_GET['signed_out'] === 'idle')
        ? 'You were signed out after 5 minutes of inactivity.'
        : 'You have been signed out.';
}
if (!empty($_SESSION['registration_status_message'])) {
    $signedOutNotice = (string) $_SESSION['registration_status_message'];
    unset($_SESSION['registration_status_message']);
} elseif (isset($_GET['registered']) && (string) $_GET['registered'] === 'profile') {
    $legacyStaff = getStaffFromPortalSession($pdo);
    if ($legacyStaff !== null) {
        header('Location: staff-profile.php?welcome=1');
        exit;
    }
    $signedOutNotice = 'Registration complete. Sign in below to view available shifts.';
}
if (!empty($_SESSION['staff_google_signin_error'])) {
    $signedOutNotice = (string) $_SESSION['staff_google_signin_error'];
    unset($_SESSION['staff_google_signin_error']);
}

$gateState   = handleStaffPortalVerifyPost($pdo);
$portalStaff = getStaffFromPortalSession($pdo);
$isGuest     = $portalStaff === null;

if ($isGuest) {
    $ctx = buildStaffV3Context($pdo, null);
    $ctx['signed_out_notice'] = $signedOutNotice;
    renderStaffV3PageStart($ctx, 'home', 'Sign in', false);
    renderStaffAppGuestEasyPage($pdo, is_array($gateState) ? $gateState : [], $signedOutNotice);
    renderStaffV3PageEnd($ctx, false);
    exit;
}

try {
    $ctx = buildStaffV3Context($pdo, $portalStaff);
    $ctx['signed_out_notice'] = $signedOutNotice;

    renderStaffV3PageStart($ctx, 'home', 'Home');
    renderStaffV3HomePage($ctx);
    renderStaffV3PageEnd($ctx);
} catch (Throwable $e) {
    error_log('[EventStaff] staff-app.php signed-in: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Staff app error</title></head><body style="font-family:system-ui,sans-serif;max-width:28rem;margin:2rem auto;padding:0 1rem;">';
    echo '<h1>Something went wrong</h1>';
    echo '<p>Your sign-in worked but the dashboard could not load. Try refreshing, or use venue sign-in for today\'s event.</p>';
    echo '<p><a href="staff-app.php">Refresh</a> · <a href="staff-signout.php?return=staff-app.php">Sign out</a></p></body></html>';
}
