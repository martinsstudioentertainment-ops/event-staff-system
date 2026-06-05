<?php

require_once __DIR__ . '/config.php';

initSecureSession();

require_once __DIR__ . '/includes/settings-repository.php';

require_once __DIR__ . '/includes/company.php';

require_once __DIR__ . '/includes/i18n.php';

require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/public/staff-public-shell.php';
require_once __DIR__ . '/includes/brand-logo.php';
require_once __DIR__ . '/includes/staff-profile-gate.php';
require_once __DIR__ . '/includes/staff-portal-session.php';
require_once __DIR__ . '/includes/notification-center.php';
require_once __DIR__ . '/includes/status-repository.php';



$pdo        = getDB();

bootstrapAppLocale($pdo);

$siteName   = getCompanyName($pdo);

$themeColor = getThemeColor($pdo);

$assetBase  = '';

if (isset($_GET['signout'])) {
    clearStaffPortalSession();
    header('Location: staff-app.php');
    exit;
}

$gateState           = handleStaffPortalVerifyPost($pdo);
$portalStaff         = getStaffFromPortalSession($pdo);
$profileUpdateForced = isStaffProfileUpdateRequired($pdo);

if ($portalStaff !== null && staffNeedsProfileForm($pdo, $portalStaff)) {
    $_SESSION['staff_profile_return'] = 'staff-app.php';
    header('Location: staff-profile.php');
    exit;
}

// Only block the app for returning staff when a profile rollout is active — new staff must register first.
$showSignInGate = $portalStaff === null && $profileUpdateForced;
$showStaffNav   = $portalStaff !== null || !$profileUpdateForced;
$signInMode     = 'update';

$staffEmail         = $portalStaff !== null ? strtolower(trim((string) ($portalStaff['email'] ?? ''))) : '';
$staffNotifUnread   = $staffEmail !== '' ? countUnreadStaffNotifications($pdo, $staffEmail) : 0;
$staffStatusToken   = $staffEmail !== '' ? (resolveStatusTokenByEmail($pdo, $staffEmail) ?? '') : '';
$whatsappGroupUrl   = getCompanyWhatsappGroup($pdo);
$notifPageUrl       = $staffStatusToken !== ''
    ? 'staff-notifications.php?token=' . urlencode($staffStatusToken)
    : 'staff-notifications.php';

$hour = (int) date('H');

if ($hour < 12) {

    $greeting = 'Good morning';

    $emoji    = '☀️';

} elseif ($hour < 17) {

    $greeting = 'Good afternoon';

    $emoji    = '👋';

} else {

    $greeting = 'Good evening';

    $emoji    = '🌙';

}

?>

<!DOCTYPE html>

<html lang="en" data-theme="light">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

    <meta name="description" content="Event staff — register, check in, view status">

    <title><?= h($siteName) ?></title>

    <?php include __DIR__ . '/includes/pwa-head.php'; ?>
    <link rel="stylesheet" href="assets/css/staff-app.css">
    <link rel="stylesheet" href="assets/css/pwa-install.css">
    <link rel="stylesheet" href="assets/css/notifications.css">

</head>

<body class="staff-app-shell staff-public-shell staff-public-shell--event-ops" data-pwa-install="1">
    <?php renderStaffPublicBackground(true); ?>



    <main class="staff-app__main">

        <header class="staff-app__hero">

            <?php renderStaffBrandLogo($pdo, 'staff-app__logo', $assetBase, $siteName); ?>

            <p class="staff-app__greeting"><?= h($greeting) ?> <?= $emoji ?></p>

            <h1 class="staff-app__title"><?= h($siteName) ?></h1>

            <p class="staff-app__tagline"><?= $showSignInGate
                ? 'Returning staff: confirm your email and date of birth. New here? Register below — no sign-in needed.'
                : ($portalStaff !== null
                    ? 'Your pocket companion for event shifts — register, track status, and check in on the day.'
                    : 'Register for events, check your status, or sign in if you already have an account.') ?></p>

        </header>

        <?php if ($showSignInGate): ?>
            <?php renderStaffProfileVerifyForm($pdo, is_array($gateState) ? $gateState : [], $signInMode); ?>
        <?php endif; ?>

        <?php if ($showStaffNav): ?>
        <nav class="staff-app__nav" aria-label="Staff actions">

            <a href="index.php" class="staff-app__tile staff-app__tile--register">

                <span class="staff-app__tile-icon" aria-hidden="true">

                    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>

                </span>

                <span class="staff-app__tile-body">

                    <span class="staff-app__tile-title">Register for events</span>

                    <span class="staff-app__tile-desc">Pick gigs &amp; apply in minutes</span>

                </span>

                <span class="staff-app__tile-arrow" aria-hidden="true">›</span>

            </a>



            <a href="staff-portal.php" class="staff-app__tile staff-app__tile--profile">
                <span class="staff-app__tile-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </span>
                <span class="staff-app__tile-body">
                    <span class="staff-app__tile-title">My profile</span>
                    <span class="staff-app__tile-desc">Email + date of birth — update PSA &amp; bank details</span>
                </span>
                <span class="staff-app__tile-arrow" aria-hidden="true">›</span>
            </a>

            <a href="status.php" class="staff-app__tile staff-app__tile--status">

                <span class="staff-app__tile-icon" aria-hidden="true">

                    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4 12 14.01l-3-3"/></svg>

                </span>

                <span class="staff-app__tile-body">

                    <span class="staff-app__tile-title">My status</span>

                    <span class="staff-app__tile-desc">Enter your email or paste your link</span>

                </span>

                <span class="staff-app__tile-arrow" aria-hidden="true">›</span>

            </a>

            <a href="<?= h($notifPageUrl) ?>" class="staff-app__tile staff-app__tile--notifications">
                <span class="staff-app__tile-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                </span>
                <span class="staff-app__tile-body">
                    <span class="staff-app__tile-title">Notifications</span>
                    <span class="staff-app__tile-desc">Approval updates and shift alerts</span>
                </span>
                <?php if ($staffNotifUnread > 0): ?>
                    <span class="staff-app__tile-badge" data-notif-badge data-status-token="<?= h($staffStatusToken) ?>"><?= $staffNotifUnread > 99 ? '99+' : (int) $staffNotifUnread ?></span>
                <?php else: ?>
                    <span class="staff-app__tile-badge" data-notif-badge data-status-token="<?= h($staffStatusToken) ?>" hidden>0</span>
                <?php endif; ?>
                <span class="staff-app__tile-arrow" aria-hidden="true">›</span>
            </a>

            <?php if ($whatsappGroupUrl !== ''): ?>
            <a href="<?= h($whatsappGroupUrl) ?>" class="staff-app__tile staff-app__tile--whatsapp" target="_blank" rel="noopener noreferrer">
                <span class="staff-app__tile-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="26" height="26" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 2C6.477 2 2 6.477 2 12c0 1.89.525 3.66 1.438 5.168L2 22l4.832-1.438A9.955 9.955 0 0 0 12 22c5.523 0 10-4.477 10-10S17.523 2 12 2z"/></svg>
                </span>
                <span class="staff-app__tile-body">
                    <span class="staff-app__tile-title">WhatsApp group</span>
                    <span class="staff-app__tile-desc">Join for shift updates &amp; reminders</span>
                </span>
                <span class="staff-app__tile-arrow" aria-hidden="true">›</span>
            </a>
            <?php endif; ?>

            <div class="staff-app__tile staff-app__tile--checkin staff-app__tile--static">

                <span class="staff-app__tile-icon" aria-hidden="true">

                    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M7 12h10"/></svg>

                </span>

                <span class="staff-app__tile-body">

                    <span class="staff-app__tile-title">Check in at the venue</span>

                    <span class="staff-app__tile-desc">Scan the QR on site or use your check-in link when approved</span>

                </span>

            </div>

        </nav>

        <?php if ($portalStaff !== null): ?>
            <p class="staff-app__verified-hint">
                Signed in as <?= h((string) $portalStaff['email']) ?>
                · <a href="staff-profile.php">My profile</a>
                · <a href="staff-app.php?signout=1">Sign out</a>
            </p>
        <?php endif; ?>

        <section class="staff-app__how" aria-labelledby="how-it-works">

            <h2 id="how-it-works" class="staff-app__how-title">How it works</h2>

            <ol class="staff-app__steps">

                <li><span class="staff-app__step-num">1</span> Register for an event</li>

                <li><span class="staff-app__step-num">2</span> Turn on notifications &amp; join WhatsApp</li>

                <li><span class="staff-app__step-num">3</span> Show up &amp; scan QR to sign in</li>

            </ol>

        </section>

        <?php endif; ?>

        <footer class="staff-app__footer">

            <button type="button" class="staff-app__install-pill" id="staff-app-install-btn" aria-label="How to install this app on your phone">

                <span class="staff-app__install-icon" aria-hidden="true">📲</span>

                <span class="staff-app__install-pill-text">Install on your phone — <strong>tap for steps</strong></span>

            </button>

        </footer>

    </main>



    <?php

    $enablePwaInstall = true;
    $enablePwaPush    = $staffEmail !== '';

    include __DIR__ . '/includes/pwa-scripts.php';

    ?>
    <?php if ($showStaffNav): ?>
        <div id="pwa-push-root"<?= $staffEmail !== '' ? ' data-staff-email="' . h($staffEmail) . '"' : '' ?><?= $staffStatusToken !== '' ? ' data-status-token="' . h($staffStatusToken) . '"' : '' ?>></div>
        <script src="assets/js/notifications.js" defer></script>
    <?php endif; ?>

</body>

</html>

