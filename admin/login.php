<?php

require_once __DIR__ . '/../config.php';

initSecureSession();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/admin-ui-settings.php';
require_once __DIR__ . '/../includes/brand-logo.php';
require_once __DIR__ . '/../includes/admin-login-otp.php';

if (isAdminLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$pdo      = getDB();
$siteName = getSiteName($pdo);
$error    = '';
$otpStep  = getAdminLoginOtpPending() !== null;
$otpEmail = getAdminLoginOtpEmail($pdo);

if (isset($_GET['timeout'])) {
    $error = 'Your session expired after 5 minutes of inactivity. Please sign in again.';
}

if (isset($_GET['cancel_otp'])) {
    clearAdminLoginOtpChallenge();
    header('Location: login.php');
    exit;
}

$lock = getAdminLoginLockStatus();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($lock['locked']) {
        $error = $lock['message'];
    } elseif (($_POST['step'] ?? '') === 'otp') {
        $pending = getAdminLoginOtpPending();
        if ($pending === null) {
            $error = 'Verification expired. Please sign in again.';
            $otpStep = false;
        } elseif (!verifyAdminLoginOtpCode((string) ($_POST['otp_code'] ?? ''))) {
            $error = 'Invalid or expired verification code.';
            $otpStep = true;
        } else {
            clearAdminLoginFailures();
            finalizeAdminLoginSession($pdo, [
                'id'        => (int) $pending['admin_id'],
                'username'  => (string) $pending['username'],
                'full_name' => (string) $pending['full_name'],
                'email'     => (string) $pending['email'],
                'role'      => (string) $pending['role'],
            ], !empty($_POST['remember_device']));
            require_once __DIR__ . '/../includes/audit-log.php';
            logAdminAudit($pdo, 'login', 'admin', (int) $pending['admin_id'], (string) $pending['username']);
            header('Location: dashboard.php');
            exit;
        }
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'Please enter username and password.';
        } else {
            $user = verifyAdminCredentials($pdo, $username, $password);
            if ($user === null) {
                recordAdminLoginFailure();
                $lock  = getAdminLoginLockStatus();
                $error = $lock['locked']
                    ? $lock['message']
                    : 'Invalid username and password.';
            } elseif (isAdminLoginOtpEnabled($pdo) && !hasValidAdminTrustedDeviceCookie((int) $user['id'])) {
                beginAdminLoginOtpChallenge($user);
                if (!sendAdminLoginOtpEmail($pdo, (int) $user['id'], (string) $user['username'])) {
                    clearAdminLoginOtpChallenge();
                    $error = 'Could not send verification code. Check email settings.';
                } else {
                    clearAdminLoginFailures();
                    $otpStep = true;
                }
            } else {
                clearAdminLoginFailures();
                finalizeAdminLoginSession($pdo, $user, !empty($_POST['remember_device']));
                require_once __DIR__ . '/../includes/audit-log.php';
                logAdminAudit($pdo, 'login', 'admin', (int) $user['id'], $username);
                header('Location: dashboard.php');
                exit;
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="dark" class="erp-admin">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Admin Login | <?= h($siteName) ?></title>
    <?php
    $assetBase   = '../';
    $themeColor  = '#2563eb';
    $enablePwa   = true;
    $pwaManifest = 'admin-manifest.php';
    $pwaAppTitle = 'Admin';
    require_once __DIR__ . '/../includes/theme.php';
    $themeColor = getThemeColor($pdo);
    include __DIR__ . '/../includes/pwa-head.php';
    ?>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/admin-login.css">
</head>
<body <?= renderAdminUiBodyAttributes($pdo, 'login-page') ?> data-pwa-install="1" data-pwa-context="admin" data-pwa-sw="../sw.js" data-pwa-scope="/">
    <main class="login-page__wrap">
        <section class="card login-card">
            <div class="login-card__brand">
                <div class="login-card__logo" aria-hidden="true">
                    <?php if (hasCompanyLogo($pdo)): ?>
                        <?php renderSiteBrandLogo($pdo, 'header', '../', $siteName); ?>
                    <?php else: ?>
                        <span class="brand-icon"><?= renderThemeBrandIcon($pdo) ?></span>
                    <?php endif; ?>
                </div>
                <h1 class="card__title"><?= h($siteName) ?></h1>
                <p class="card__subtitle"><?= $otpStep ? 'Enter verification code' : 'Admin sign in' ?></p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert--error alert--visible"><?= h($error) ?></div>
            <?php endif; ?>

            <?php if ($otpStep): ?>
                <p class="login-card__hint">We sent a 6-digit code to <strong><?= h($otpEmail) ?></strong>. It expires in 10 minutes.</p>
                <form method="post" class="form-grid login-form">
                    <input type="hidden" name="step" value="otp">
                    <div class="form-group form-group--full">
                        <label class="form-label" for="otp_code">Verification code</label>
                        <input class="form-input" type="text" id="otp_code" name="otp_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus>
                    </div>
                    <label class="form-check form-group--full">
                        <input type="checkbox" name="remember_device" value="1" checked>
                        <span>Trust this browser for 30 days</span>
                    </label>
                    <div class="form-actions">
                        <button type="submit" class="btn btn--primary btn--block">Verify &amp; sign in</button>
                    </div>
                </form>
                <p class="login-card__hint"><a href="login.php?cancel_otp=1">← Back to password login</a></p>
            <?php else: ?>
                <form method="post" class="form-grid login-form">
                    <div class="form-group form-group--full">
                        <label class="form-label" for="username">Username</label>
                        <input class="form-input" type="text" id="username" name="username" autocomplete="username" required<?= $lock['locked'] ? ' disabled' : '' ?>>
                    </div>
                    <div class="form-group form-group--full">
                        <label class="form-label" for="password">Password</label>
                        <input class="form-input" type="password" id="password" name="password" autocomplete="current-password" required<?= $lock['locked'] ? ' disabled' : '' ?>>
                    </div>
                    <label class="form-check form-group--full">
                        <input type="checkbox" name="remember_device" value="1" checked>
                        <span>Trust this browser for 30 days (skip code next time)</span>
                    </label>
                    <div class="form-actions">
                        <button type="submit" class="btn btn--primary btn--block"<?= $lock['locked'] ? ' disabled' : '' ?>>Sign In</button>
                    </div>
                </form>
                <p class="login-card__hint">Verification codes are sent to <?= h($otpEmail) ?>.</p>
            <?php endif; ?>

            <?php if (!isProductionApp()): ?>
                <p class="login-card__hint">Development only: default login <strong>admin</strong> / <strong>admin123</strong>.</p>
            <?php else: ?>
                <p class="login-card__hint">Contact your system administrator if you need access.</p>
            <?php endif; ?>

            <p class="login-card__hint"><a href="<?= h(getRegistrationFormUrl($pdo)) ?>" target="_blank" rel="noopener">← Open staff registration form</a></p>
        </section>
    </main>
    <script src="../assets/js/mobile.js"></script>
    <script src="../assets/js/admin-pwa.js"></script>
    <script src="../assets/js/pwa-install.js"></script>
    <script src="../assets/js/admin.js"></script>
</body>
</html>
