<?php

require_once __DIR__ . '/config.php';

initSecureSession();

require_once __DIR__ . '/includes/settings-repository.php';

require_once __DIR__ . '/includes/company.php';

require_once __DIR__ . '/includes/i18n.php';

require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/public/staff-public-shell.php';



$pdo        = getDB();

bootstrapAppLocale($pdo);

$siteName   = getCompanyName($pdo);

$themeColor = getThemeColor($pdo);

$assetBase  = '';



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

</head>

<body class="staff-app-shell staff-public-shell staff-public-shell--event-ops" data-pwa-install="1">
    <?php renderStaffPublicBackground(true); ?>



    <main class="staff-app__main">

        <header class="staff-app__hero">

            <div class="staff-app__logo brand-icon" aria-hidden="true"><?= renderThemeBrandIcon($pdo) ?></div>

            <p class="staff-app__greeting"><?= h($greeting) ?> <?= $emoji ?></p>

            <h1 class="staff-app__title"><?= h($siteName) ?></h1>

            <p class="staff-app__tagline">Your pocket companion for event shifts — register, track status, and check in on the day.</p>

        </header>



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



        <section class="staff-app__how" aria-labelledby="how-it-works">

            <h2 id="how-it-works" class="staff-app__how-title">How it works</h2>

            <ol class="staff-app__steps">

                <li><span class="staff-app__step-num">1</span> Register for an event</li>

                <li><span class="staff-app__step-num">2</span> Wait for approval email</li>

                <li><span class="staff-app__step-num">3</span> Show up &amp; scan QR to sign in</li>

            </ol>

        </section>



        <footer class="staff-app__footer">

            <div class="staff-app__install-pill">

                <span class="staff-app__install-icon" aria-hidden="true">📲</span>

                <span>Tap <strong>Share → Add to Home Screen</strong> to install this app</span>

            </div>

        </footer>

    </main>



    <?php

    $enablePwaInstall = true;

    include __DIR__ . '/includes/pwa-scripts.php';

    ?>

</body>

</html>

