<?php

require_once __DIR__ . '/../config.php';

initSecureSession();

require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/admin-ui-settings.php';

if (isAdminLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}



$pdo      = getDB();

$siteName = getSiteName($pdo);

$error    = '';

$lock     = getAdminLoginLockStatus();



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($lock['locked']) {

        $error = $lock['message'];

    } else {

        $username = trim((string) ($_POST['username'] ?? ''));

        $password = (string) ($_POST['password'] ?? '');



        if ($username === '' || $password === '') {

            $error = 'Please enter username and password.';

        } elseif (attemptAdminLogin($username, $password)) {

            clearAdminLoginFailures();
            require_once __DIR__ . '/../includes/audit-log.php';
            logAdminAudit(getDB(), 'login', 'admin', (int) $_SESSION['admin_id'], $username);

            header('Location: dashboard.php');

            exit;

        } else {

            recordAdminLoginFailure();

            $lock  = getAdminLoginLockStatus();

            $error = $lock['locked']

                ? $lock['message']

                : 'Invalid username or password.';

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

    $assetBase  = '../';

    $themeColor = '#2563eb';

    require_once __DIR__ . '/../includes/theme.php';

    $themeColor = getThemeColor($pdo);

    include __DIR__ . '/../includes/pwa-head.php';

    ?>

    <link rel="stylesheet" href="../assets/css/admin.css">

</head>

<body <?= renderAdminUiBodyAttributes($pdo, 'login-page') ?>>

    <main class="login-page__wrap">

        <section class="card login-card">

            <div class="login-card__brand">

                <div class="login-card__logo brand-icon" aria-hidden="true"><?= renderThemeBrandIcon($pdo) ?></div>

                <h1 class="card__title"><?= h($siteName) ?></h1>

                <p class="card__subtitle">Sign in to the ERP console</p>

            </div>



            <?php if ($error !== ''): ?>

                <div class="alert alert--error alert--visible"><?= h($error) ?></div>

            <?php endif; ?>



            <form method="post" class="form-grid login-form">

                <div class="form-group form-group--full">

                    <label class="form-label" for="username">Username</label>

                    <input class="form-input" type="text" id="username" name="username" autocomplete="username" required<?= $lock['locked'] ? ' disabled' : '' ?>>

                </div>

                <div class="form-group form-group--full">

                    <label class="form-label" for="password">Password</label>

                    <input class="form-input" type="password" id="password" name="password" autocomplete="current-password" required<?= $lock['locked'] ? ' disabled' : '' ?>>

                </div>

                <div class="form-actions">

                    <button type="submit" class="btn btn--primary btn--block"<?= $lock['locked'] ? ' disabled' : '' ?>>Sign In</button>

                </div>

            </form>



            <?php if (!isProductionApp()): ?>

                <p class="login-card__hint">Development only: default login <strong>admin</strong> / <strong>admin123</strong> — change after first sign-in.</p>

            <?php else: ?>

                <p class="login-card__hint">Contact your system administrator if you need access.</p>

            <?php endif; ?>

            <p class="login-card__hint"><a href="<?= h(getRegistrationFormUrl($pdo)) ?>" target="_blank" rel="noopener">← Open staff registration form</a></p>

        </section>

    </main>

    <script src="../assets/js/mobile.js"></script>
    <script src="../assets/js/admin.js"></script>

</body>

</html>

