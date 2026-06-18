<?php
require_once __DIR__ . '/config.php';
initSecureSession();
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/staff-repository.php';
require_once __DIR__ . '/includes/attendance-repository.php';
require_once __DIR__ . '/includes/status-repository.php';
require_once __DIR__ . '/includes/validation.php';
require_once __DIR__ . '/includes/system-settings.php';
require_once __DIR__ . '/includes/public/staff-public-shell.php';
require_once __DIR__ . '/includes/public/registration-success-panel.php';
require_once __DIR__ . '/includes/staff-psa.php';
require_once __DIR__ . '/includes/staff-profile-gate.php';
require_once __DIR__ . '/includes/staff-portal-session.php';
require_once __DIR__ . '/includes/notification-center.php';
require_once __DIR__ . '/includes/components/whatsapp-join.php';
require_once __DIR__ . '/includes/staff-portal-dashboard.php';
require_once __DIR__ . '/includes/components/staff-status-dashboard.php';

$pdo      = getDB();
require_once __DIR__ . '/includes/staff-registration-schema.php';
ensureStaffRegistrationSaveSchema($pdo);
$siteName = getSiteName($pdo);
$token    = trim((string) ($_GET['token'] ?? ''));
$rows        = [];
$error       = '';
$successMsg  = '';
$showLookup  = false;
$staffRecord = null;
$psaErrors   = [];

if (!empty($_SESSION['registration_status_message'])) {
    $successMsg = (string) $_SESSION['registration_status_message'];
    unset($_SESSION['registration_status_message']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status_psa_update'])) {
    $token = trim((string) ($_POST['status_token'] ?? $_GET['token'] ?? ''));
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
        $showLookup = $token === '';
    } elseif ($token === '') {
        $error = 'Invalid status link.';
        $showLookup = true;
    } else {
        $rows = getStaffStatusRows($pdo, $token);
        if ($rows === []) {
            $error = 'Status link not found or expired.';
            $showLookup = true;
            $token = '';
        } else {
            $staffId = ensureStaffRecordForEmail($pdo, (string) ($rows[0]['email'] ?? ''));
            $staffRecord = $staffId !== null ? getStaffById($pdo, $staffId) : null;
            $psaErrors = validateRegistrationPsa($_POST, $staffRecord, $_FILES);
            if ($psaErrors === [] && $staffId !== null) {
                ensureStaffPsaSchema($pdo);
                $saveErrors = saveStaffPsaFromForm($pdo, $staffId, $_POST, $_FILES);
                if ($saveErrors !== []) {
                    $psaErrors = array_merge($psaErrors, $saveErrors);
                } else {
                    $_SESSION['registration_status_message'] = 'PSA details saved.';
                    header('Location: status.php?token=' . urlencode($token));
                    exit;
                }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status_lookup'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
        $showLookup = true;
    } else {
        $emailInput = trim((string) ($_POST['email'] ?? ''));
        $linkInput  = trim((string) ($_POST['status_link'] ?? ''));
        $parsed     = parseStatusTokenFromInput($linkInput);

        if ($parsed !== '') {
            header('Location: status.php?token=' . urlencode($parsed));
            exit;
        }

        $resolved = resolveStatusTokenByEmail($pdo, $emailInput);
        if ($resolved !== null) {
            header('Location: status.php?token=' . urlencode($resolved));
            exit;
        }

        $error = 'No registration found for that email. Register first or paste your full status link from email.';
        $showLookup = true;
    }
} elseif ($token === '') {
    $showLookup = true;
} else {
    $rows = getStaffStatusRows($pdo, $token);
    if ($rows === []) {
        $error = 'Status link not found or expired. Try your email below or use a fresh link from email.';
        $showLookup = true;
        $token = '';
    } else {
        $staffId = ensureStaffRecordForEmail($pdo, (string) ($rows[0]['email'] ?? ''));
        if ($staffId !== null) {
            $staffRecord = getStaffById($pdo, $staffId);
        }

        if ($staffRecord !== null && staffNeedsProfileForm($pdo, $staffRecord)) {
            require_once __DIR__ . '/includes/staff-portal-remember.php';
            establishStaffPortalSessionWithRemember($pdo, $staffRecord);
            $_SESSION['staff_profile_return'] = 'status.php?token=' . urlencode($token);
            header('Location: staff-profile.php');
            exit;
        }
    }
}

enforceStaffProfileGate($pdo, ['staff-profile.php', 'staff-portal.php', 'status.php']);

$assetBase      = '';
$portalStaff    = getStaffFromPortalSession($pdo);
$statusFilter   = strtolower(trim((string) ($_GET['filter'] ?? '')));
if ($statusFilter === 'all' || $statusFilter === 'total') {
    $statusFilter = '';
}
$statusMetrics  = [];
$displayRows    = $rows;
if ($rows !== []) {
    $statusMetrics = computeStaffStatusMetricsFromRows($rows);
    $displayRows   = filterStaffStatusRows($rows, $statusFilter);
}
$profilePageUrl = $portalStaff !== null ? 'staff-profile.php?edit=1' : 'staff-portal.php';
require_once __DIR__ . '/includes/theme.php';
$themeColor = getThemeColor($pdo);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>My Status | <?= h($siteName) ?></title>
    <?php include __DIR__ . '/includes/pwa-head.php'; ?>
    <link rel="stylesheet" href="assets/css/notifications.css">
    <link rel="stylesheet" href="assets/css/staff-status-dashboard.css">
</head>
<body class="staff-public-shell staff-public-shell--event-ops staff-public-shell--narrow login-page staff-mobile-page" data-pwa-install="1" <?= renderStaffPortalBodyAttributes($portalStaff, $pdo) ?>>
    <?php renderStaffPublicBackground(true); ?>
    <?php renderStaffPublicHeader($pdo, $siteName, ['home_url' => 'staff-app.php', 'portal_staff' => $portalStaff]); ?>
    <?php renderStaffFlashBroadcast($pdo); ?>

    <main class="login-page__wrap staff-public-main">
        <section class="card login-card staff-public-card">
            <div class="card__header">
                <h1 class="card__title">My status</h1>
                <p class="card__subtitle">Applications, shifts &amp; check-in</p>
            </div>

            <?php if ($successMsg !== ''): ?>
                <div class="alert alert--success alert--visible"><?= h($successMsg) ?></div>
            <?php endif; ?>

            <?php if ($successMsg !== '' && !$showLookup && $rows !== []): ?>
                <?php renderRegistrationSuccessPanel($rows); ?>
            <?php endif; ?>

            <?php if ($showLookup): ?>
                <?php if ($error !== ''): ?>
                    <div class="alert alert--error alert--visible"><?= h($error) ?></div>
                <?php else: ?>
                    <p class="staff-status-intro">Enter the <strong>same email</strong> you used to register, or paste the status link from your email.</p>
                <?php endif; ?>

                <form method="post" class="staff-status-lookup" action="status.php">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="status_lookup" value="1">

                    <div class="form-group">
                        <label class="form-label form-label--required" for="email">Your email</label>
                        <input class="form-input" type="email" id="email" name="email" autocomplete="email" inputmode="email" placeholder="you@example.com" value="<?= h((string) ($_POST['email'] ?? '')) ?>">
                    </div>

                    <p class="staff-status-or">or paste your link</p>

                    <div class="form-group">
                        <label class="form-label" for="status_link">Status link from email</label>
                        <input class="form-input" type="url" id="status_link" name="status_link" autocomplete="off" placeholder="https://…/status.php?token=…" value="<?= h((string) ($_POST['status_link'] ?? '')) ?>">
                    </div>

                    <button type="submit" class="btn btn--primary btn--block">View my status</button>
                </form>

                <p class="login-card__hint">New here? <a href="index.php">Register for an event</a></p>
            <?php else: ?>
                <?php $person = $rows[0]; ?>
                <?php renderStaffStatusMetricsDashboard($token, $statusMetrics, $statusFilter); ?>
                <?php renderStaffStatusApplicationsList($displayRows, $pdo, $token, $statusFilter); ?>

                <?php
                $personEmail = strtolower(trim((string) ($person['email'] ?? '')));
                $notifUnread = $personEmail !== '' ? countUnreadStaffNotifications($pdo, $personEmail) : 0;
                ?>
                <?php if ($personEmail !== ''): ?>
                    <p style="margin-top:1rem">
                        <a href="staff-notifications.php?token=<?= h($token) ?>" class="btn btn--secondary btn--block">
                            Notifications<?= $notifUnread > 0 ? ' (' . (int) $notifUnread . ' new)' : '' ?>
                        </a>
                    </p>
                <?php endif; ?>

                <?php renderWhatsappGroupCard($pdo, 'compact'); ?>

                <details class="status-dash__account">
                    <summary>Account &amp; profile details</summary>
                    <div class="status-dash__account-body">
                        <dl class="detail-list detail-list--compact">
                            <div class="detail-list__row"><dt>Name</dt><dd><?= h($person['first_name'] . ' ' . $person['surname']) ?></dd></div>
                            <div class="detail-list__row"><dt>Email</dt><dd><?= h($person['email']) ?></dd></div>
                        </dl>
                        <p style="margin-top:0.75rem">
                            <a href="<?= h($profilePageUrl) ?>" class="btn btn--secondary btn--sm">Manage profile</a>
                        </p>
                        <?php if ($staffRecord !== null): ?>
                            <?php
                            $staff = $staffRecord;
                            $psaFlash = $successMsg;
                            $hidePsaAfterRegistration = $successMsg !== '' && isStaffPsaComplete($staff);
                            if ($hidePsaAfterRegistration) {
                                echo '<p class="status-psa-on-file" role="status">Your PSA licence details are on file from registration.</p>';
                            } else {
                                include __DIR__ . '/includes/status-psa-form.php';
                            }
                            ?>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>

            <p class="login-card__hint"><a href="staff-app.php">← Staff app home</a></p>
        </section>
    </main>
    <?php if (!$showLookup && $rows !== []): ?>
        <div id="pwa-push-root" data-status-token="<?= h($token) ?>" data-registration-id="<?= (int) ($rows[0]['id'] ?? 0) ?>"></div>
    <?php endif; ?>
    <?php
    $enablePwaInstall = true;
    $enablePwaPush    = !$showLookup && $rows !== [];
    include __DIR__ . '/includes/pwa-scripts.php';
    if ($portalStaff !== null) {
        renderStaffPortalSessionIdleScript($pdo, $portalStaff);
    }
    ?>
</body>
</html>
