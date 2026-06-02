<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/settings-repository.php';

$pdo      = getDB();
$siteName = getSiteName($pdo);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Offline | <?= h($siteName) ?></title>
    <link rel="stylesheet" href="assets/css/variables.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/mobile.css">
    <link rel="stylesheet" href="assets/css/staff-app.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100dvh; padding: 1.5rem; }
        .offline-card { max-width: 24rem; text-align: center; }
    </style>
</head>
<body class="staff-mobile-page">
    <section class="card login-card offline-card">
        <div style="font-size:2.5rem;margin-bottom:0.5rem;" aria-hidden="true">📡</div>
        <h1 class="card__title">You’re offline</h1>
        <p class="card__subtitle">Check-in needs internet. Reconnect to Wi‑Fi or mobile data, then try again.</p>
        <button type="button" class="btn btn--primary btn--block" onclick="window.location.reload()">Try again</button>
        <p class="login-card__hint" style="margin-top:1rem;"><a href="staff-app.php">← Staff app home</a></p>
    </section>
</body>
</html>
