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

require_once __DIR__ . '/includes/staff-messages.php';

require_once __DIR__ . '/includes/status-repository.php';

require_once __DIR__ . '/includes/staff-portal-dashboard.php';
require_once __DIR__ . '/includes/feature-flags.php';



$pdo        = getDB();

bootstrapAppLocale($pdo);



$siteName   = getCompanyName($pdo);

$themeColor = getThemeColor($pdo);

$assetBase  = '';



if (isset($_GET['signout'])) {

    header('Location: staff-signout.php?return=staff-app.php');

    exit;

}



$signedOutNotice = '';

if (isset($_GET['signed_out'])) {

    $signedOutNotice = ($_GET['signed_out'] === 'idle')

        ? 'You were signed out after 5 minutes of inactivity.'

        : 'You have been signed out.';

}



$gateState           = handleStaffPortalVerifyPost($pdo);

$portalStaff         = getStaffFromPortalSession($pdo);

$profileUpdateForced = isStaffProfileUpdateRequired($pdo);

$profileComplete     = $portalStaff !== null && !staffNeedsProfileForm($pdo, $portalStaff);



if ($portalStaff !== null && staffNeedsProfileForm($pdo, $portalStaff)) {

    $_SESSION['staff_profile_return'] = 'staff-app.php';

    header('Location: staff-profile.php');

    exit;

}



$showSignInGate    = $portalStaff === null && $profileUpdateForced;

$signInMode        = 'update';

$needsProfileFirst = $portalStaff !== null && staffNeedsProfileForm($pdo, $portalStaff);

$registerUrl       = $needsProfileFirst ? 'staff-profile.php' : 'index.php';



$staffEmail       = $portalStaff !== null ? strtolower(trim((string) ($portalStaff['email'] ?? ''))) : '';

$staffNotifUnread = $staffEmail !== '' ? countUnreadStaffNotifications($pdo, $staffEmail) : 0;

$staffMsgUnread   = $staffEmail !== '' ? countUnreadAdminRepliesForStaff($pdo, $staffEmail) : 0;

$staffStatusToken = $staffEmail !== '' ? (resolveStatusTokenByEmail($pdo, $staffEmail) ?? '') : '';

$whatsappGroupUrl = getCompanyWhatsappGroup($pdo);

$notifPageUrl     = $staffStatusToken !== ''

    ? 'staff-notifications.php?token=' . urlencode($staffStatusToken)

    : 'staff-notifications.php';

$messagesPageUrl  = $staffStatusToken !== ''

    ? 'staff-messages.php?token=' . urlencode($staffStatusToken)

    : 'staff-messages.php';

$statusPageUrl    = $staffStatusToken !== ''

    ? 'status.php?token=' . urlencode($staffStatusToken)

    : 'status.php';

$profileUrl       = $portalStaff !== null ? 'staff-profile.php?edit=1' : 'staff-portal.php';

$statusFilterUrls = [
    'all'       => buildStaffStatusPageUrl($staffStatusToken, ''),
    'approved'  => buildStaffStatusPageUrl($staffStatusToken, 'approved'),
    'pending'   => buildStaffStatusPageUrl($staffStatusToken, 'pending'),
    'rejected'  => buildStaffStatusPageUrl($staffStatusToken, 'rejected'),
    'upcoming'  => buildStaffStatusPageUrl($staffStatusToken, 'upcoming'),
    'completed' => buildStaffStatusPageUrl($staffStatusToken, 'completed'),
];

$metrics      = getStaffPortalDashboardMetrics($pdo, $staffEmail);

$activityFeed = getStaffPortalActivityFeed($pdo, $staffEmail, 6, $staffStatusToken);

$avatarInit   = getStaffPortalAvatarInitials($portalStaff);

$statusBadge  = getStaffPortalStatusBadge($portalStaff, $metrics);

if ($portalStaff !== null && !$profileComplete) {

    $statusBadge = ['label' => 'Profile needed', 'tone' => 'warn'];

}



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



$welcomeLine = $portalStaff !== null

    ? ($metrics['has_data']

        ? 'You have ' . (int) $metrics['upcoming'] . ' upcoming shift' . ((int) $metrics['upcoming'] === 1 ? '' : 's') . '.'

        : 'Register for your first event to get started.')

    : 'Register for events or sign in to see your dashboard.';



$displayName = getStaffPortalDisplayName($portalStaff, $pdo);

$displayRole = $portalStaff !== null || $staffEmail !== ''

    ? getStaffPortalRoleLabel($pdo, $portalStaff, $staffEmail)

    : 'Event Staff';




?>

<!DOCTYPE html>

<html lang="en" data-theme="light">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

    <meta name="description" content="Event staff — register, check in, view status">

    <meta name="theme-color" content="#312e81">

    <title><?= h($siteName) ?> — Staff</title>

    <?php include __DIR__ . '/includes/pwa-head.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="assets/css/staff-app.css">

    <link rel="stylesheet" href="assets/css/staff-app-v2.css">

    <link rel="stylesheet" href="assets/css/pwa-install.css">

    <link rel="stylesheet" href="assets/css/notifications.css">

</head>

<body class="staff-app-shell staff-app-shell--v2 staff-public-shell staff-public-shell--event-ops" data-pwa-install="1" data-staff-app-v2="1" <?= renderStaffPortalBodyAttributes($portalStaff) ?>>

    <?php renderStaffPublicBackground(true); ?>



    <main class="staff-v2" id="staff-v2-main">

        <?php renderStaffFlashBroadcast($pdo); ?>



        <?php if ($signedOutNotice !== ''): ?>

            <div class="staff-v2__alert staff-v2__alert--info" role="status"><?= h($signedOutNotice) ?></div>

        <?php endif; ?>



        <header class="staff-v2__dash-header">

            <div class="staff-v2__dash-header-top">

                <div class="staff-v2__identity">

                    <div class="staff-v2__avatar" aria-hidden="true"><?= h($avatarInit) ?></div>

                    <div class="staff-v2__identity-text">

                        <p class="staff-v2__greeting"><?= h($greeting) ?> <?= $emoji ?></p>

                        <h1 class="staff-v2__name"><?= h($displayName) ?></h1>

                        <p class="staff-v2__role"><?= h($displayRole) ?></p>

                    </div>

                </div>

                <div class="staff-v2__dash-header-actions">

                    <a href="<?= h($notifPageUrl) ?>" class="staff-v2__icon-btn" aria-label="Notifications<?= $staffNotifUnread > 0 ? ' (' . (int) $staffNotifUnread . ' unread)' : '' ?>">

                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>

                        <?php if ($staffNotifUnread > 0): ?>

                            <span class="staff-v2__icon-badge" data-notif-badge data-status-token="<?= h($staffStatusToken) ?>"><?= $staffNotifUnread > 99 ? '99+' : (int) $staffNotifUnread ?></span>

                        <?php else: ?>

                            <span class="staff-v2__icon-badge" data-notif-badge data-status-token="<?= h($staffStatusToken) ?>" hidden>0</span>

                        <?php endif; ?>

                    </a>

                    <a href="<?= h($profileUrl) ?>" class="staff-v2__icon-btn" aria-label="Profile">

                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>

                    </a>

                </div>

            </div>

            <div class="staff-v2__badge-row">

                <span class="staff-v2__status-badge staff-v2__status-badge--<?= h($statusBadge['tone']) ?>"><?= h($statusBadge['label']) ?></span>

            </div>

            <p class="staff-v2__welcome"><?= h($welcomeLine) ?></p>



            <?php if ($metrics['has_data']): ?>

            <div class="staff-v2__hero-stats" aria-label="Quick summary">

                <a href="<?= h($statusFilterUrls['all']) ?>" class="staff-v2__hero-stat staff-v2__hero-stat--link">

                    <span class="staff-v2__hero-stat-val"><?= (int) $metrics['total'] ?></span>

                    <span class="staff-v2__hero-stat-label">Applications</span>

                </a>

                <a href="<?= h($statusFilterUrls['approved']) ?>" class="staff-v2__hero-stat staff-v2__hero-stat--link">

                    <span class="staff-v2__hero-stat-val"><?= (int) $metrics['approved'] ?></span>

                    <span class="staff-v2__hero-stat-label">Approved</span>

                </a>

                <a href="<?= h($statusFilterUrls['upcoming']) ?>" class="staff-v2__hero-stat staff-v2__hero-stat--link">

                    <span class="staff-v2__hero-stat-val"><?= (int) $metrics['upcoming'] ?></span>

                    <span class="staff-v2__hero-stat-label">Upcoming</span>

                </a>

            </div>

            <?php endif; ?>

        </header>



        <section class="staff-v2__metrics" aria-label="Dashboard metrics">

            <a href="<?= h($statusFilterUrls['all']) ?>" class="staff-v2__metric staff-v2__metric--total staff-v2__metric--link" aria-label="View all applications">

                <span class="staff-v2__metric-val"><?= (int) $metrics['total'] ?></span>

                <span class="staff-v2__metric-label">Total</span>

            </a>

            <a href="<?= h($statusFilterUrls['approved']) ?>" class="staff-v2__metric staff-v2__metric--approved staff-v2__metric--link" aria-label="View approved applications">

                <span class="staff-v2__metric-val"><?= (int) $metrics['approved'] ?></span>

                <span class="staff-v2__metric-label">Approved</span>

            </a>

            <a href="<?= h($statusFilterUrls['pending']) ?>" class="staff-v2__metric staff-v2__metric--pending staff-v2__metric--link" aria-label="View pending applications">

                <span class="staff-v2__metric-val"><?= (int) $metrics['pending'] ?></span>

                <span class="staff-v2__metric-label">Pending</span>

            </a>

            <a href="<?= h($statusFilterUrls['upcoming']) ?>" class="staff-v2__metric staff-v2__metric--upcoming staff-v2__metric--link" aria-label="View upcoming events">

                <span class="staff-v2__metric-val"><?= (int) $metrics['upcoming'] ?></span>

                <span class="staff-v2__metric-label">Upcoming</span>

            </a>

            <a href="<?= h($statusFilterUrls['rejected']) ?>" class="staff-v2__metric staff-v2__metric--rejected staff-v2__metric--link" aria-label="View rejected applications">

                <span class="staff-v2__metric-val"><?= (int) $metrics['rejected'] ?></span>

                <span class="staff-v2__metric-label">Rejected</span>

            </a>

            <a href="<?= h($statusFilterUrls['completed']) ?>" class="staff-v2__metric staff-v2__metric--completed staff-v2__metric--link" aria-label="View completed events">

                <span class="staff-v2__metric-val"><?= (int) $metrics['completed'] ?></span>

                <span class="staff-v2__metric-label">Completed</span>

            </a>

        </section>



        <section class="staff-v2__quick" aria-label="Quick actions">

            <h2 class="staff-v2__section-title">Quick actions</h2>

            <div class="staff-v2__quick-scroll">

                <a href="<?= h($registerUrl) ?>" class="staff-v2__quick-btn staff-v2__quick-btn--register">

                    <span class="staff-v2__quick-icon" aria-hidden="true">➕</span>

                    <span>Register</span>

                </a>

                <?php if ($portalStaff === null): ?>

                <a href="staff-portal.php" class="staff-v2__quick-btn staff-v2__quick-btn--signin">

                    <span class="staff-v2__quick-icon" aria-hidden="true">🔐</span>

                    <span>Sign in</span>

                </a>

                <?php endif; ?>

                <a href="<?= h($statusPageUrl) ?>" class="staff-v2__quick-btn staff-v2__quick-btn--status">

                    <span class="staff-v2__quick-icon" aria-hidden="true">✓</span>

                    <span>Status</span>

                </a>

                <a href="<?= h($messagesPageUrl) ?>" class="staff-v2__quick-btn staff-v2__quick-btn--messages">

                    <span class="staff-v2__quick-icon" aria-hidden="true">💬</span>

                    <span>Messages<?= $staffMsgUnread > 0 ? ' (' . (int) $staffMsgUnread . ')' : '' ?></span>

                </a>

            </div>

        </section>



        <section class="staff-v2__features" aria-label="Staff features">

            <h2 class="staff-v2__section-title">Your workspace</h2>

            <div class="staff-v2__widget-grid">

                <a href="<?= h($registerUrl) ?>" class="staff-v2__widget staff-v2__widget--register">

                    <span class="staff-v2__widget-icon" aria-hidden="true">

                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>

                    </span>

                    <span class="staff-v2__widget-body">

                        <span class="staff-v2__widget-title">Register for events</span>

                        <span class="staff-v2__widget-desc"><?= $needsProfileFirst ? 'Complete your profile first' : 'Pick gigs & apply in minutes' ?></span>

                    </span>

                </a>



                <?php if ($portalStaff === null): ?>

                <a href="staff-portal.php" class="staff-v2__widget staff-v2__widget--profile">

                    <span class="staff-v2__widget-icon" aria-hidden="true">

                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>

                    </span>

                    <span class="staff-v2__widget-body">

                        <span class="staff-v2__widget-title">Staff sign-in</span>

                        <span class="staff-v2__widget-desc">Email + date of birth</span>

                    </span>

                </a>

                <?php endif; ?>



                <a href="<?= h($statusPageUrl) ?>" class="staff-v2__widget staff-v2__widget--status">

                    <span class="staff-v2__widget-icon" aria-hidden="true">

                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4 12 14.01l-3-3"/></svg>

                    </span>

                    <span class="staff-v2__widget-body">

                        <span class="staff-v2__widget-title">My status</span>

                        <span class="staff-v2__widget-desc">Applications &amp; check-in links</span>

                    </span>

                </a>



                <a href="<?= h($messagesPageUrl) ?>" class="staff-v2__widget staff-v2__widget--messages">

                    <span class="staff-v2__widget-icon" aria-hidden="true">

                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>

                    </span>

                    <span class="staff-v2__widget-body">

                        <span class="staff-v2__widget-title">Message coordinator</span>

                        <span class="staff-v2__widget-desc">Chat with admin or manager</span>

                    </span>

                    <?php if ($staffMsgUnread > 0): ?>

                        <span class="staff-v2__widget-badge"><?= $staffMsgUnread > 99 ? '99+' : (int) $staffMsgUnread ?></span>

                    <?php endif; ?>

                </a>



                <a href="<?= h($notifPageUrl) ?>" class="staff-v2__widget staff-v2__widget--notifications">

                    <span class="staff-v2__widget-icon" aria-hidden="true">

                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>

                    </span>

                    <span class="staff-v2__widget-body">

                        <span class="staff-v2__widget-title">Notifications</span>

                        <span class="staff-v2__widget-desc">Approvals &amp; shift alerts</span>

                    </span>

                    <?php if ($staffNotifUnread > 0): ?>

                        <span class="staff-v2__widget-badge"><?= $staffNotifUnread > 99 ? '99+' : (int) $staffNotifUnread ?></span>

                    <?php endif; ?>

                </a>



                <?php if ($whatsappGroupUrl !== ''): ?>

                <a href="<?= h($whatsappGroupUrl) ?>" class="staff-v2__widget staff-v2__widget--whatsapp" target="_blank" rel="noopener noreferrer">

                    <span class="staff-v2__widget-icon" aria-hidden="true">

                        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 2C6.477 2 2 6.477 2 12c0 1.89.525 3.66 1.438 5.168L2 22l4.832-1.438A9.955 9.955 0 0 0 12 22c5.523 0 10-4.477 10-10S17.523 2 12 2z"/></svg>

                    </span>

                    <span class="staff-v2__widget-body">

                        <span class="staff-v2__widget-title">WhatsApp group</span>

                        <span class="staff-v2__widget-desc">Shift updates &amp; reminders</span>

                    </span>

                </a>

                <?php endif; ?>



                <a href="<?= h($statusPageUrl) ?>" class="staff-v2__widget staff-v2__widget--checkin" id="staff-v2-checkin">

                    <span class="staff-v2__widget-icon" aria-hidden="true">

                        <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M7 12h10"/></svg>

                    </span>

                    <span class="staff-v2__widget-body">

                        <span class="staff-v2__widget-title">Check in at the venue</span>

                        <span class="staff-v2__widget-desc">Open My Status for your check-in link when approved</span>

                    </span>

                </a>

            </div>

        </section>



        <section class="staff-v2__activity" aria-label="Recent activity">

            <h2 class="staff-v2__section-title">Recent activity</h2>

            <?php if ($activityFeed === []): ?>

                <div class="staff-v2__empty-state" role="status">

                    <span class="staff-v2__empty-state-icon" aria-hidden="true">📋</span>

                    <p class="staff-v2__empty-state-title">No recent activity</p>

                    <p class="staff-v2__empty-state-text"><?= $staffEmail !== '' ? 'Register for an event to get started.' : 'Sign in or register to see your activity here.' ?></p>

                </div>

            <?php else: ?>

                <ul class="staff-v2__activity-list">

                    <?php foreach ($activityFeed as $item): ?>

                    <li>

                        <a href="<?= h($item['url'] !== '' ? $item['url'] : $statusPageUrl) ?>" class="staff-v2__activity-item staff-v2__activity-item--<?= h($item['kind']) ?>">

                            <span class="staff-v2__activity-dot" aria-hidden="true"></span>

                            <span class="staff-v2__activity-body">

                                <span class="staff-v2__activity-title"><?= h($item['title']) ?></span>

                                <?php if ($item['detail'] !== ''): ?>

                                    <span class="staff-v2__activity-detail"><?= h($item['detail']) ?></span>

                                <?php endif; ?>

                            </span>

                            <?php if ($item['time_label'] !== ''): ?>

                                <time class="staff-v2__activity-time" datetime="<?= h($item['time']) ?>"><?= h($item['time_label']) ?></time>

                            <?php endif; ?>

                        </a>

                    </li>

                    <?php endforeach; ?>

                </ul>

            <?php endif; ?>

        </section>



        <section class="staff-v2__how" aria-labelledby="staff-v2-how-title">

            <h2 id="staff-v2-how-title" class="staff-v2__section-title">How it works</h2>

            <ol class="staff-v2__steps">

                <li><span class="staff-v2__step-num">1</span> Register for an event</li>

                <li><span class="staff-v2__step-num">2</span> Turn on notifications &amp; join WhatsApp</li>

                <li><span class="staff-v2__step-num">3</span> Show up &amp; scan QR to sign in</li>

            </ol>

        </section>



        <?php if ($showSignInGate): ?>

        <details class="staff-v2__returning-gate"<?= is_array($gateState) && !empty($gateState['error']) ? ' open' : '' ?>>

            <summary>Returning staff — confirm email &amp; date of birth</summary>

            <?php renderStaffProfileVerifyForm($pdo, is_array($gateState) ? $gateState : [], $signInMode); ?>

        </details>

        <?php endif; ?>



        <button type="button" class="staff-v2__install-card" id="staff-app-install-btn" aria-label="Install app on your phone">

            <span class="staff-v2__install-card-icon" aria-hidden="true">📲</span>

            <span class="staff-v2__install-card-text">

                <strong>Install staff app</strong>

                <span>Add to home screen for one-tap access</span>

            </span>

            <span class="staff-v2__install-card-cta">Install</span>

        </button>

    </main>



    <nav class="staff-v2__bottom-nav" aria-label="Primary">

        <a href="staff-app.php" class="staff-v2__nav-item staff-v2__nav-item--active" aria-current="page">

            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1V9.5z"/></svg>

            <span>Home</span>

        </a>

        <a href="<?= h($registerUrl) ?>" class="staff-v2__nav-item">

            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>

            <span>Events</span>

        </a>

        <a href="<?= h($statusPageUrl) ?>" class="staff-v2__nav-item staff-v2__nav-item--checkin">

            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M7 12h10"/></svg>

            <span>Check-In</span>

        </a>

        <a href="portal/staff-dashboard.php" class="staff-v2__nav-item">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M8 10h8M8 14h5"/></svg>
            <span>Self-service</span>
        </a>
        <a href="<?= h($profileUrl) ?>" class="staff-v2__nav-item">

            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>

            <span>Profile</span>

        </a>

    </nav>



    <?php

    $enablePwaInstall = true;

    $enablePwaPush    = $staffEmail !== '';

    include __DIR__ . '/includes/pwa-scripts.php';

    ?>

    <div id="pwa-push-root"<?= $staffEmail !== '' ? ' data-staff-email="' . h($staffEmail) . '"' : '' ?><?= $staffStatusToken !== '' ? ' data-status-token="' . h($staffStatusToken) . '"' : '' ?>></div>

    <script src="assets/js/notifications.js" defer></script>

    <script src="assets/js/staff-app-v2.js" defer></script>
    <!-- Staff PWA offline sync disabled (Sprint 6.5) -->

    <?php if ($portalStaff !== null) {

        renderStaffPortalSessionIdleScript();

    } ?>



</body>

</html>


