<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/company.php';

$pdo      = getDB();
$siteName = getSiteName($pdo);
$company  = getCompanyName($pdo);
$appName  = $siteName !== '' && $siteName !== 'Event Staff System' ? $siteName : ($company !== '' ? $company : 'Olasentra');
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#F58220">
    <title>Offline — <?= h($appName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/staff-app-v3.css">
</head>
<body class="es-v3 es-v3--guest">
    <div class="es-v3__ambient" aria-hidden="true"></div>
    <main class="es-v3__main">
        <div class="es-ds__empty es-ds__empty--page">
            <span class="es-ds__empty-icon es-ds__empty-icon--lg" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>
            </span>
            <h1 class="es-ds__empty-title">You&rsquo;re offline</h1>
            <p class="es-ds__empty-text">Check-in and live updates need internet. Reconnect to Wi&#8209;Fi or mobile data, then try again.</p>
            <button type="button" class="es-ds__btn es-ds__btn--primary es-ds__btn--block" onclick="window.location.reload()">Try again</button>
            <a href="staff-app.php" class="es-ds__btn es-ds__btn--ghost es-ds__btn--block">Staff app home</a>
        </div>
    </main>
</body>
</html>
