<?php

require_once __DIR__ . '/config.php';
initSecureSession();

require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/status-repository.php';
require_once __DIR__ . '/includes/notification-center.php';
require_once __DIR__ . '/includes/public/staff-public-shell.php';
require_once __DIR__ . '/includes/staff-portal-session.php';
require_once __DIR__ . '/includes/components/whatsapp-join.php';
require_once __DIR__ . '/includes/components/notification-list.php';

$pdo      = getDB();
$siteName = getSiteName($pdo);
$token    = trim((string) ($_GET['token'] ?? ''));
$email    = '';
$error    = '';
$showLookup = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notif_lookup'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
        $showLookup = true;
    } else {
        $emailInput = trim((string) ($_POST['email'] ?? ''));
        $resolved   = resolveStatusTokenByEmail($pdo, $emailInput);
        if ($resolved !== null) {
            header('Location: staff-notifications.php?token=' . urlencode($resolved));
            exit;
        }
        $error = 'No registration found for that email.';
        $showLookup = true;
    }
} elseif ($token !== '') {
    $rows = getStaffStatusRows($pdo, $token);
    if ($rows === []) {
        $error = 'Link not found or expired.';
        $showLookup = true;
        $token = '';
    } else {
        $email = strtolower(trim((string) ($rows[0]['email'] ?? '')));
    }
} else {
    $portalStaff = getStaffFromPortalSession($pdo);
    if ($portalStaff !== null) {
        $email = strtolower(trim((string) ($portalStaff['email'] ?? '')));
        $resolved = resolveStatusTokenByEmail($pdo, $email);
        if ($resolved !== null) {
            $token = $resolved;
        }
    } else {
        $showLookup = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read']) && $email !== '') {
    if (verifyCsrf($_POST['csrf_token'] ?? null)) {
        markAllNotificationsRead($pdo, 'staff', $email);
        $qs = $token !== '' ? '?token=' . urlencode($token) : '';
        header('Location: staff-notifications.php' . $qs);
        exit;
    }
}

$notifications = $email !== '' ? getStaffNotifications($pdo, $email, 80) : [];
$unreadCount   = $email !== '' ? countUnreadStaffNotifications($pdo, $email) : 0;

require_once __DIR__ . '/includes/theme.php';
$themeColor = getThemeColor($pdo);
$notifUrl   = $token !== '' ? 'staff-notifications.php?token=' . urlencode($token) : 'staff-notifications.php';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Notifications | <?= h($siteName) ?></title>
    <?php include __DIR__ . '/includes/pwa-head.php'; ?>
    <link rel="stylesheet" href="assets/css/notifications.css">
</head>
<body class="staff-public-shell staff-public-shell--event-ops staff-public-shell--narrow login-page staff-mobile-page" data-pwa-install="1">
    <?php renderStaffPublicBackground(true); ?>
    <?php renderStaffPublicHeader($pdo, $siteName, ['home_url' => 'staff-app.php']); ?>

    <main class="login-page__wrap staff-public-main">
        <section class="card login-card staff-public-card">
            <div class="card__header notif-page__header">
                <div>
                    <h1 class="card__title">Notifications</h1>
                    <p class="card__subtitle">Updates about your registrations and shifts</p>
                </div>
                <?php if ($unreadCount > 0): ?>
                    <span class="notif-page__badge" aria-label="<?= (int) $unreadCount ?> unread"><?= (int) $unreadCount ?></span>
                <?php endif; ?>
            </div>

            <?php if ($showLookup): ?>
                <?php if ($error !== ''): ?>
                    <div class="alert alert--error alert--visible"><?= h($error) ?></div>
                <?php else: ?>
                    <p class="staff-status-intro">Enter the email you used to register to see your notifications.</p>
                <?php endif; ?>
                <form method="post" class="staff-status-lookup" action="staff-notifications.php">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="notif_lookup" value="1">
                    <div class="form-group">
                        <label class="form-label form-label--required" for="email">Your email</label>
                        <input class="form-input" type="email" id="email" name="email" autocomplete="email" inputmode="email" placeholder="you@example.com">
                    </div>
                    <button type="submit" class="btn btn--primary btn--block">View notifications</button>
                </form>
            <?php else: ?>
                <?php if ($unreadCount > 0): ?>
                    <form method="post" action="<?= h($notifUrl) ?>" style="margin-top:0.5rem">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="mark_all_read" value="1">
                        <button type="submit" class="btn btn--secondary btn--sm">Mark all as read</button>
                    </form>
                <?php endif; ?>

                <?php renderNotificationList($notifications, 'No notifications yet. You will see updates here when your application is reviewed.', false); ?>

                <?php renderWhatsappGroupCard($pdo); ?>
            <?php endif; ?>

            <p class="login-card__hint"><a href="staff-app.php">← Staff app home</a></p>
        </section>
    </main>

    <?php if (!$showLookup && $email !== ''): ?>
        <div id="pwa-push-root" data-staff-email="<?= h($email) ?>"<?= $token !== '' ? ' data-status-token="' . h($token) . '"' : '' ?>></div>
    <?php endif; ?>
    <?php
    $enablePwaInstall = true;
    $enablePwaPush    = !$showLookup && $email !== '';
    include __DIR__ . '/includes/pwa-scripts.php';
    ?>
    <script src="assets/js/notifications.js" defer></script>
</body>
</html>
