<?php

require_once __DIR__ . '/config.php';
initSecureSession();

require_once __DIR__ . '/includes/staff-google-oauth.php';

$pdo = getDB();

$registrationFlow = false;
$redirectError = static function (string $message) use (&$registrationFlow): void {
    if ($registrationFlow) {
        $_SESSION['registration_google_error'] = $message;
        header('Location: index.php');
    } else {
        $_SESSION['staff_google_signin_error'] = $message;
        header('Location: staff-app.php');
    }
    exit;
};

try {
    if (!isStaffGoogleSigninEnabled($pdo)) {
        $redirectError('Google sign-in is not enabled.');
    }

    $state = (string) ($_GET['state'] ?? '');
    if (!staffGoogleOAuthValidateState($state)) {
        $redirectError('Google sign-in session expired. Please try again.');
    }

    if (isset($_GET['error'])) {
        $redirectError('Google sign-in was cancelled.');
    }

    $code = (string) ($_GET['code'] ?? '');
    if ($code === '') {
        $redirectError('Google did not return an authorization code.');
    }

    initSecureSession();
    $returnTarget = staffGoogleSanitizeReturnUrl((string) ($_SESSION['staff_google_return'] ?? 'staff-app.php'));
    $registrationFlow = isRegistrationGoogleReturnUrl($returnTarget);

    $result = $registrationFlow
        ? completeRegistrationGoogleVerify($pdo, $code)
        : completeStaffGoogleSignin($pdo, $code);

    if (!$result['ok']) {
        $redirectError((string) ($result['message'] ?? 'Google sign-in failed.'));
    }

    header('Location: ' . (string) ($result['redirect'] ?? ($registrationFlow ? 'index.php' : 'staff-app.php')));
    exit;
} catch (Throwable $e) {
    error_log('[EventStaff] staff-google-oauth-callback: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $redirectError('Google sign-in hit a server error. Please try again in a moment.');
}
