<?php

require_once __DIR__ . '/config.php';
initSecureSession();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/status-repository.php';
require_once __DIR__ . '/includes/staff-messages.php';
require_once __DIR__ . '/includes/staff-portal-session.php';
require_once __DIR__ . '/includes/public/staff-public-shell.php';
require_once __DIR__ . '/includes/components/message-thread.php';

$pdo      = getDB();
$siteName = getSiteName($pdo);
$token    = trim((string) ($_GET['token'] ?? ''));
$email    = '';
$staffId  = 0;
$error    = '';
$flash    = '';
$showLookup = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['msg_lookup'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
        $showLookup = true;
    } else {
        $emailInput = trim((string) ($_POST['email'] ?? ''));
        $resolved   = resolveStatusTokenByEmail($pdo, $emailInput);
        if ($resolved !== null) {
            header('Location: staff-messages.php?token=' . urlencode($resolved));
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
        $email   = strtolower(trim((string) ($rows[0]['email'] ?? '')));
        $staffId = (int) (ensureStaffRecordForEmail($pdo, $email) ?? 0);
    }
} else {
    $portalStaff = getStaffFromPortalSession($pdo);
    if ($portalStaff !== null) {
        $email   = strtolower(trim((string) ($portalStaff['email'] ?? '')));
        $staffId = (int) ($portalStaff['id'] ?? 0);
        $resolved = resolveStatusTokenByEmail($pdo, $email);
        if ($resolved !== null) {
            $token = $resolved;
        }
    } else {
        $showLookup = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message']) && $email !== '') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $flash = 'Your session expired. Please try again.';
    } else {
        $result = sendStaffMessageToAdmin($pdo, $email, (string) ($_POST['body'] ?? ''));
        $flash  = $result['message'];
        if (!empty($result['ok'])) {
            $qs = $token !== '' ? '?token=' . urlencode($token) . '&sent=1' : '?sent=1';
            header('Location: staff-messages.php' . $qs);
            exit;
        }
    }
}

if ($staffId < 1 && $email !== '') {
    $staffId = (int) (ensureStaffRecordForEmail($pdo, $email) ?? 0);
}

$messages    = $staffId > 0 ? getStaffMessageThread($pdo, $staffId) : [];
$unreadCount = $email !== '' ? countUnreadAdminRepliesForStaff($pdo, $email) : 0;

if ($email !== '') {
    markAdminRepliesReadForStaff($pdo, $email);
    $unreadCount = 0;
}

$msgUrl      = $token !== '' ? 'staff-messages.php?token=' . urlencode($token) : 'staff-messages.php';
$portalStaff = getStaffFromPortalSession($pdo);
$profileUrl = 'staff-profile.php';
if ($portalStaff !== null) {
    $profileUrl = 'staff-profile.php?edit=1';
} elseif ($token !== '') {
    $profileUrl .= '?token=' . urlencode($token);
} elseif ($email !== '') {
    $staffRow = getStaffByEmail($pdo, $email);
    if (!empty($staffRow['profile_token'])) {
        $profileUrl .= '?token=' . urlencode((string) $staffRow['profile_token']);
    }
}

require_once __DIR__ . '/includes/theme.php';
$themeColor = getThemeColor($pdo);

if (isset($_GET['sent'])) {
    $flash = 'Message sent — a coordinator will reply here.';
}

$useV3Shell = $portalStaff !== null && !$showLookup;

if ($useV3Shell) {
    require_once __DIR__ . '/includes/staff-app-v3-pages.php';
    $ctx = buildStaffV3Context($pdo, $portalStaff);
    renderStaffV3PageStart($ctx, 'messages', 'Messages');
    renderStaffV3MessagesPage($ctx, $messages, $flash, $msgUrl);
    renderStaffV3PageEnd($ctx);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Messages | <?= h($siteName) ?></title>
    <?php include __DIR__ . '/includes/pwa-head.php'; ?>
    <link rel="stylesheet" href="assets/css/notifications.css">
    <link rel="stylesheet" href="assets/css/messages.css">
</head>
<body class="staff-public-shell staff-public-shell--event-ops staff-public-shell--narrow login-page staff-mobile-page" data-pwa-install="1" <?= renderStaffPortalBodyAttributes($portalStaff, $pdo) ?>>
    <?php renderStaffPublicBackground(true); ?>
    <?php renderStaffPublicHeader($pdo, $siteName, ['home_url' => 'staff-app.php', 'portal_staff' => $portalStaff]); ?>

    <?php renderStaffFlashBroadcast($pdo); ?>

    <main class="login-page__wrap staff-public-main">
        <section class="card login-card staff-public-card">
            <div class="card__header notif-page__header">
                <div>
                    <h1 class="card__title">Message coordinator</h1>
                    <p class="card__subtitle">Instant messages to admin or manager — replies appear here</p>
                </div>
                <?php if ($unreadCount > 0): ?>
                    <span class="notif-page__badge"><?= (int) $unreadCount ?></span>
                <?php endif; ?>
            </div>

            <?php if ($flash !== ''): ?>
                <div class="alert alert--<?= str_contains(strtolower($flash), 'sent') ? 'success' : 'error' ?> alert--visible"><?= h($flash) ?></div>
            <?php endif; ?>

            <?php if ($showLookup): ?>
                <?php if ($error !== ''): ?>
                    <div class="alert alert--error alert--visible"><?= h($error) ?></div>
                <?php else: ?>
                    <p class="staff-status-intro">Enter the email you used to register to message the coordinator.</p>
                <?php endif; ?>
                <form method="post" class="staff-status-lookup" action="staff-messages.php">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="msg_lookup" value="1">
                    <div class="form-group">
                        <label class="form-label form-label--required" for="email">Your email</label>
                        <input class="form-input" type="email" id="email" name="email" autocomplete="email" placeholder="you@example.com">
                    </div>
                    <button type="submit" class="btn btn--primary btn--block">Continue</button>
                </form>
            <?php else: ?>
                <p style="margin:0 0 0.75rem;font-size:0.85rem">
                    <a href="<?= h($profileUrl) ?>">Update my profile</a>
                    · PSA, bank &amp; contact details
                </p>

                <?php renderMessageThread($messages, false); ?>

                <form method="post" action="<?= h($msgUrl) ?>" class="msg-compose">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="send_message" value="1">
                    <div class="form-group">
                        <label class="form-label form-label--required" for="body">Your message</label>
                        <textarea class="form-input" id="body" name="body" rows="4" maxlength="4000" placeholder="Ask about shifts, PSA, payroll, or availability…" required></textarea>
                    </div>
                    <button type="submit" class="btn btn--primary btn--block">Send message</button>
                </form>
            <?php endif; ?>

            <p class="login-card__hint"><a href="staff-app.php">← Staff app home</a></p>
        </section>
    </main>

    <?php include __DIR__ . '/includes/pwa-scripts.php'; ?>
    <?php if ($portalStaff !== null) {
        renderStaffPortalSessionIdleScript($pdo, $portalStaff);
    } ?>
</body>
</html>
