<?php

require_once __DIR__ . '/config.php';
initSecureSession();

require_once __DIR__ . '/includes/staff-google-oauth.php';

$pdo = getDB();

if (!isStaffGoogleSigninEnabled($pdo)) {
    header('Location: staff-app.php');
    exit;
}

$return = staffGoogleSanitizeReturnUrl((string) ($_GET['return'] ?? 'staff-app.php'));
header('Location: ' . staffGoogleOAuthAuthorizeUrl($pdo, $return));
exit;
