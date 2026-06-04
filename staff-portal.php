<?php
require_once __DIR__ . '/config.php';
initSecureSession();
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/staff-portal-session.php';
require_once __DIR__ . '/includes/staff-onboarding.php';
require_once __DIR__ . '/includes/staff-profile-gate.php';
require_once __DIR__ . '/includes/public/staff-public-shell.php';
require_once __DIR__ . '/includes/brand-logo.php';

$pdo      = getDB();
$siteName = getSiteName($pdo);
$error    = '';
$success  = '';

$sessionStaff = getStaffFromPortalSession($pdo);
if ($sessionStaff !== null) {
    header('Location: staff-profile.php');
    exit;
}

if (isStaffProfileUpdateRequired($pdo) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: staff-app.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $dob   = trim((string) ($_POST['date_of_birth'] ?? ''));

    $staff = authenticateStaffPortal($pdo, $email, $dob);
    if ($staff === null) {
        $error = 'We could not verify your email and date of birth. Check the details match your registration exactly.';
    } else {
        establishStaffPortalSession($staff);
        header('Location: staff-profile.php');
        exit;
    }
}

$assetBase = '';
require_once __DIR__ . '/includes/theme.php';
$themeColor = getThemeColor($pdo);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Staff Profile Portal | <?= h($siteName) ?></title>
    <?php include __DIR__ . '/includes/pwa-head.php'; ?>
</head>
<body class="staff-public-shell staff-public-shell--event-ops staff-public-shell--narrow login-page staff-mobile-page">
    <?php renderStaffPublicBackground(true); ?>
    <?php renderStaffPublicHeader($pdo, $siteName, ['home_url' => 'staff-app.php']); ?>

    <main class="login-page__wrap staff-public-main">
        <section class="card login-card staff-public-card">
            <div class="staff-portal-card__logo-wrap">
                <?php renderStaffBrandLogo($pdo, 'staff-portal-card__logo', $assetBase, $siteName); ?>
            </div>
            <div class="card__header">
                <h1 class="card__title">Staff profile portal</h1>
                <p class="card__subtitle">Sign in with the email and date of birth you used when registering. You can update PSA licence photos, bank details, and address here.</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert--error alert--visible"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" class="form-grid" autocomplete="on">
                <div class="form-group form-group--full">
                    <label class="form-label" for="portal-email">Email</label>
                    <input type="email" id="portal-email" name="email" class="form-input" required autocomplete="email" value="<?= h((string) ($_POST['email'] ?? '')) ?>">
                </div>
                <div class="form-group form-group--full">
                    <label class="form-label" for="portal-dob">Date of birth</label>
                    <input type="date" id="portal-dob" name="date_of_birth" class="form-input" required value="<?= h((string) ($_POST['date_of_birth'] ?? '')) ?>">
                </div>
                <div class="form-group form-group--full form-actions form-actions--end">
                    <button type="submit" class="btn btn--primary">Continue to my profile</button>
                </div>
            </form>

            <p class="login-card__hint" style="margin-top:1rem;">
                <a href="staff-app.php">← Staff app home</a>
            </p>
        </section>
    </main>
</body>
</html>
