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
$supportEmail = 'developer@olasentra.com';
$pageUrl     = 'https://olasentra.com/account-deletion';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="<?= h($themeColor) ?>">
    <meta name="description" content="Request deletion of your Olasentra account and associated personal data.">
    <link rel="canonical" href="<?= h($pageUrl) ?>">
    <title>Olasentra Account Deletion Request | <?= h($siteName) ?></title>
    <link rel="stylesheet" href="assets/css/variables.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
</head>
<body class="login-page">
    <main class="login-page__wrap" style="max-width:720px;">
        <section class="card">
            <div class="card__header">
                <h1 class="card__title">Olasentra Account Deletion Request</h1>
                <p class="card__subtitle"><?= h($displayName) ?> — account &amp; data deletion</p>
            </div>

            <div class="card__body" style="line-height:1.65;color:var(--text-secondary);">
                <p>Users may request deletion of their Olasentra account and associated personal data by contacting support at:</p>

                <p><a href="mailto:<?= h($supportEmail) ?>"><?= h($supportEmail) ?></a></p>

                <p>Please include your registered email address and account details in your request.</p>

                <p>Upon verification, we will process the deletion request and remove personal data associated with the account, except where retention is required by law, security, auditing, payroll, tax, employment, or regulatory obligations.</p>

                <p>Requests are normally processed within 30 days.</p>
            </div>

            <p class="login-card__hint">
                <a href="https://olasentra.com">← Back to olasentra.com</a>
                · <a href="privacy.php">Privacy Notice</a>
                · <a href="terms.php">Terms of Use</a>
            </p>
        </section>
    </main>
</body>
</html>
