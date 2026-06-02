<?php
require_once __DIR__ . '/config.php';
initSecureSession();

require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/theme.php';

$pdo      = getDB();
$siteName = getSiteName($pdo);
$assetBase = '';
$themeColor = getThemeColor($pdo);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Notice | <?= h($siteName) ?></title>
    <link rel="stylesheet" href="assets/css/variables.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <main class="login-page__wrap" style="max-width:720px;">
        <section class="card">
            <div class="card__header">
                <h1 class="card__title">Privacy Notice</h1>
                <p class="card__subtitle"><?= h($siteName) ?> — event staff registration</p>
            </div>

            <div class="card__body" style="line-height:1.65;color:var(--text-secondary);">
                <p><strong>Who we are.</strong> <?= h($siteName) ?> processes personal data so we can register, approve, and pay event staff, and manage attendance at events.</p>

                <p><strong>Data we collect.</strong> Name, address, Eircode, email, mobile, date of birth, gender, PPS/NI number, bank account/IBAN, role, event selections, and optional location coordinates if you use the map on the registration form.</p>

                <p><strong>Why we use it.</strong></p>
                <ul>
                    <li>Assess and approve staff applications for events</li>
                    <li>Contact you about your registration and shifts</li>
                    <li>Check you in at events (including email/PPS verification and venue location where used)</li>
                    <li>Process payroll and payments to your bank account</li>
                    <li>Meet legal and insurance requirements for event staffing</li>
                </ul>

                <p><strong>Lawful basis.</strong> Processing is necessary for contract/pre-contract steps when you apply to work, and for legal obligations relating to tax and employment. We rely on your consent for the privacy checkbox at registration.</p>

                <p><strong>Who we share data with.</strong> Authorised event organisers, payroll/finance providers, and IT systems that host this application. We do not sell your data.</p>

                <p><strong>How long we keep it.</strong> For as long as needed to manage your registration, payments, and legal record-keeping, then securely deleted or anonymised unless law requires longer retention.</p>

                <p><strong>Your rights.</strong> You may request access, correction, or deletion of your data, or withdraw consent where applicable. Contact us using the details on our website.</p>

                <p><strong>Security.</strong> Access to sensitive fields in our admin system is restricted. Bank details are exported only for authorised payroll processing.</p>
            </div>

            <p class="login-card__hint"><a href="index.php">← Back to registration</a></p>
        </section>
    </main>
</body>
</html>
