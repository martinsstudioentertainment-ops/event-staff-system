<?php

require_once __DIR__ . '/config.php';
initSecureSession();

require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/theme.php';
require_once __DIR__ . '/includes/company.php';

$pdo         = getDB();
$siteName    = getSiteName($pdo);
$companyName = getCompanyName($pdo);
$displayName = $companyName !== '' ? $companyName : $siteName;
$themeColor  = getThemeColor($pdo);
$privacyUrl  = 'https://olasentra.com/privacy.php';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="<?= h($themeColor) ?>">
    <title>Terms of Use | <?= h($siteName) ?></title>
    <link rel="stylesheet" href="assets/css/variables.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
</head>
<body class="login-page">
    <main class="login-page__wrap" style="max-width:720px;">
        <section class="card">
            <div class="card__header">
                <h1 class="card__title">Terms of Use</h1>
                <p class="card__subtitle"><?= h($displayName) ?> — staff registration &amp; attendance app</p>
            </div>

            <div class="card__body" style="line-height:1.65;color:var(--text-secondary);">
                <p><strong>Last updated:</strong> <?= h(date('j F Y')) ?></p>

                <p>These terms apply when you use the <?= h($displayName) ?> staff registration website, mobile web app, and any official Android app published by <?= h($displayName) ?> that connects to the same platform.</p>

                <p><strong>What this service is.</strong> A registration and workforce coordination portal for event staff. <?= h($displayName) ?> is not your employer unless a separate written contract says otherwise. Event organisers and staffing partners manage approvals, shifts, check-in, and payroll through this system.</p>

                <p><strong>Your account.</strong> You must sign in with the email or Google account you used when registering. Do not share your sign-in details. You are responsible for activity on your account. If you reinstall the app, sign in again — your existing profile and approval status will load; you do not need to register twice.</p>

                <p><strong>Accurate information.</strong> You must provide truthful personal, contact, PSA, and bank details. False information may lead to removal from events and suspension from the platform.</p>

                <p><strong>Check-in &amp; location.</strong> When GPS attendance is enabled for an event, you agree that location may be used to verify venue check-in and automatic sign-out when you leave the attendance zone. QR and venue sign-in flows may also require location permission in your browser or app.</p>

                <p><strong>Acceptable use.</strong> Do not attempt to bypass check-in rules, share false attendance, scrape data, reverse engineer the service, or use the app in ways that harm other staff, clients, or event operations.</p>

                <p><strong>Notifications.</strong> We may send email, in-app, or push notifications about registrations, shifts, messages, and operational updates. You can manage browser notification permissions on your device.</p>

                <p><strong>Availability.</strong> The service is provided on an operational basis. Maintenance, connectivity issues, or device limitations may occasionally affect access. Critical attendance features depend on HTTPS, accurate GPS, and an installed or supported browser environment.</p>

                <p><strong>Intellectual property.</strong> The platform, branding, and software remain the property of <?= h($displayName) ?> and its licensors. You receive a limited right to use the service for your staffing relationship with us and participating organisers.</p>

                <p><strong>Privacy.</strong> Personal data is processed as described in our <a href="<?= h($privacyUrl) ?>">Privacy Notice</a>.</p>

                <p><strong>Changes.</strong> We may update these terms. Continued use after changes are posted constitutes acceptance of the updated terms.</p>

                <p><strong>Contact.</strong> For questions about these terms, use the contact details published on <a href="https://olasentra.com">olasentra.com</a>.</p>
            </div>

            <p class="login-card__hint">
                <a href="staff-app.php">← Staff app</a>
                · <a href="<?= h($privacyUrl) ?>">Privacy Notice</a>
            </p>
        </section>
    </main>
</body>
</html>
