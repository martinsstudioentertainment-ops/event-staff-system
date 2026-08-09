<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/staff-app-v3-data.php';
require_once __DIR__ . '/staff-portal-session.php';
require_once __DIR__ . '/staff-profile-gate.php';
require_once __DIR__ . '/staff-messages.php';
require_once __DIR__ . '/notification-center.php';
require_once __DIR__ . '/staff-google-oauth.php';
require_once __DIR__ . '/company.php';
require_once __DIR__ . '/brand-logo.php';
require_once __DIR__ . '/share-meta.php';
require_once __DIR__ . '/mobile/services/MobileDashboardService.php';

/**
 * @return array<string, mixed>
 */
function buildStaffV3Context(PDO $pdo, ?array $portalStaff): array
{
    $staffEmail       = $portalStaff !== null ? strtolower(trim((string) ($portalStaff['email'] ?? ''))) : '';
    $staffId          = $portalStaff !== null ? (int) ($portalStaff['id'] ?? 0) : 0;
    $staffStatusToken = $staffEmail !== '' ? (resolveStatusTokenByEmail($pdo, $staffEmail) ?? '') : '';
    $companyName      = getCompanyName($pdo);
    $siteName         = getSiteName($pdo);
    $appDisplayName   = $siteName !== '' && $siteName !== 'Event Staff System' ? $siteName : ($companyName !== '' ? $companyName : 'Olasentra');
    $metrics          = getStaffPortalDashboardMetrics($pdo, $staffEmail, $staffId);
    $monthly          = getStaffV3MonthlyStats($pdo, $staffEmail, $staffId);
    $shiftRows        = getStaffV3ShiftRows($pdo, $staffEmail, $staffStatusToken, $staffId);
    $todayShift       = getStaffV3TodayDashboardShift($shiftRows, $pdo);
    $profileComplete  = $portalStaff !== null && !staffNeedsProfileForm($pdo, $portalStaff);

    $notifPageUrl = 'staff-notifications.php';
    $messagesPageUrl = 'staff-messages.php';

    $statusPageUrl = $staffStatusToken !== ''
        ? 'status.php?token=' . urlencode($staffStatusToken)
        : 'status.php';

    $registerUrl = !$profileComplete ? 'staff-profile.php' : 'index.php';

    $checkinUrl = '';
    if ($todayShift !== null) {
        $checkinUrl = getStaffV3CheckinUrl($pdo, $todayShift);
    } elseif ($shiftRows !== []) {
        foreach ($shiftRows as $row) {
            if ((string) ($row['status'] ?? '') === 'approved' && (int) ($row['is_checked_in'] ?? 0) !== 1) {
                $eventDate = substr((string) ($row['event_date'] ?? ''), 0, 10);
                if ($eventDate >= date('Y-m-d')) {
                    $checkinUrl = getStaffV3CheckinUrl($pdo, $row);
                    if ($checkinUrl !== '') {
                        break;
                    }
                }
            }
        }
    }

    return [
        'pdo'               => $pdo,
        'portal_staff'      => $portalStaff,
        'staff_email'       => $staffEmail,
        'staff_id'          => $staffId,
        'status_token'      => $staffStatusToken,
        'company_name'      => $companyName,
        'site_name'         => $appDisplayName,
        'display_name'      => getStaffPortalDisplayName($portalStaff, $pdo),
        'display_role'      => getStaffPortalRoleLabel($pdo, $portalStaff, $staffEmail),
        'avatar_initials'   => getStaffPortalAvatarInitials($portalStaff),
        'metrics'           => $metrics,
        'monthly'           => $monthly,
        'shift_rows'        => $shiftRows,
        'today_shift'       => $todayShift,
        'profile_complete'  => $profileComplete,
        'notif_unread'      => $staffEmail !== '' ? countUnreadStaffNotifications($pdo, $staffEmail) : 0,
        'msg_unread'        => $staffEmail !== '' ? countUnreadAdminRepliesForStaff($pdo, $staffEmail) : 0,
        'notif_url'         => $notifPageUrl,
        'messages_url'      => $messagesPageUrl,
        'status_url'        => $statusPageUrl,
        'register_url'      => $registerUrl,
        'checkin_url'       => $checkinUrl,
        'profile_url'       => 'staff-profile-hub.php',
        'profile_edit_url'  => 'staff-profile.php?edit=1',
        'shifts_url'        => 'staff-shifts.php',
        'apply_shifts_url'  => 'staff-apply-shifts.php',
        'open_events_count' => mobileDashboardCountAvailableEvents($pdo),
        'home_url'          => 'staff-app.php',
        'employer_filters'  => getStaffV3EmployerFilters($shiftRows, $companyName),
        'signed_out_notice' => '',
    ];
}

function staffV3RequireSignIn(PDO $pdo): array
{
    $staff = getStaffFromPortalSession($pdo);
    if ($staff === null) {
        header('Location: staff-app.php');
        exit;
    }

    return $staff;
}

function staffV3CssVersion(): string
{
    $path = dirname(__DIR__) . '/assets/css/staff-app-v3.css';

    return is_file($path) ? (string) filemtime($path) : '3';
}

function staffV3JsVersion(): string
{
    $path = dirname(__DIR__) . '/assets/js/staff-app-v3.js';

    return is_file($path) ? (string) filemtime($path) : '3';
}

/**
 * @param array<string, mixed> $ctx
 */
function renderStaffV3PageStart(array $ctx, string $activeTab, string $pageTitle, bool $showNav = true): void
{
    $pdo         = $ctx['pdo'];
    $portalStaff = $ctx['portal_staff'];
    $staffEmail  = (string) ($ctx['staff_email'] ?? '');
    $siteName    = (string) ($ctx['site_name'] ?? $ctx['company_name'] ?? 'Olasentra');
    $isGuest     = $portalStaff === null;
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0F172A">
    <?php
    renderShareMeta([
        'title'       => $pageTitle . ' — ' . $siteName,
        'description' => $siteName . ' — shifts, check-in, messages',
        'site_name'   => $siteName,
    ], $pdo);
    ?>
    <title><?= h($pageTitle) ?> — <?= h($siteName) ?></title>
    <?php
    $pwaHeadMinimal = true;
    include dirname(__DIR__) . '/includes/pwa-head.php';
    ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/staff-app-v3.css?v=<?= h(staffV3CssVersion()) ?>">
    <link rel="stylesheet" href="assets/css/pwa-install.css">
    <link rel="stylesheet" href="assets/css/notifications.css">
</head>
<body class="es-v3<?= $isGuest ? ' es-v3--guest' : ' es-v3--signed-in' ?>"
      data-staff-app-v3="1"
      data-pwa-install="1"
      data-pwa-analytics="1"
      data-pwa-analytics-csrf="<?= h(csrfToken()) ?>"
      data-pwa-app-context="staff"
      data-active-tab="<?= h($activeTab) ?>"
      <?= $staffEmail !== '' ? ' data-staff-email="' . h($staffEmail) . '"' : '' ?>
      <?= renderStaffPortalBodyAttributes($portalStaff, $pdo) ?>>
    <div class="es-v3__ambient" aria-hidden="true"></div>
    <div class="es-v3__offline" id="es-v3-offline" hidden role="status">
        <span>You're offline — some features may be limited</span>
    </div>
    <div id="es-v3-pwa-banner" class="es-v3__pwa-banner" hidden>
        <div class="es-v3__pwa-banner-text">
            <strong>Install <?= h($siteName) ?></strong>
            <span>One-tap access on shift days</span>
        </div>
        <button type="button" class="es-v3__pwa-banner-btn" id="es-v3-pwa-install">Install</button>
        <button type="button" class="es-v3__pwa-banner-dismiss" id="es-v3-pwa-dismiss" aria-label="Dismiss">×</button>
    </div>
    <main class="es-v3__main" id="es-v3-main">
    <?php
    if (!empty($ctx['signed_out_notice'])) {
        echo '<div class="es-v3__alert" role="status">' . h((string) $ctx['signed_out_notice']) . '</div>';
    }
}

/**
 * @param array<string, mixed> $ctx
 */
function renderStaffV3PageEnd(array $ctx, bool $showNav = true): void
{
    $portalStaff = $ctx['portal_staff'];
    $staffEmail  = (string) ($ctx['staff_email'] ?? '');
    $statusToken = (string) ($ctx['status_token'] ?? '');
    $pdo         = $ctx['pdo'];
    ?>
    </main>
    <?php if ($showNav && $portalStaff !== null): ?>
        <?php renderStaffV3BottomNav($ctx); ?>
    <?php endif; ?>
    <?php
    $enablePwaInstall = true;
    $enablePwaPush    = $staffEmail !== '';
    include dirname(__DIR__) . '/includes/pwa-scripts.php';
    ?>
    <div id="pwa-push-root"
         <?= $staffEmail !== '' ? ' data-staff-email="' . h($staffEmail) . '"' : '' ?>
         <?= $statusToken !== '' ? ' data-status-token="' . h($statusToken) . '"' : '' ?>></div>
    <script src="assets/js/notifications.js" defer></script>
    <script src="assets/js/staff-app-v3.js?v=<?= h(staffV3JsVersion()) ?>" defer></script>
    <?php if ($portalStaff === null && is_file(dirname(__DIR__) . '/assets/js/staff-portal-email-otp.js')) {
        $otpJs = dirname(__DIR__) . '/assets/js/staff-portal-email-otp.js'; ?>
    <script src="assets/js/staff-portal-email-otp.js?v=<?= h((string) filemtime($otpJs)) ?>" defer></script>
    <?php } ?>
    <?php if ($portalStaff !== null) {
        renderStaffPortalSessionIdleScript($pdo, $portalStaff);
    } ?>
</body>
</html>
    <?php
}

/**
 * @param array<string, mixed> $ctx
 */
function renderStaffV3BottomNav(array $ctx): void
{
    $active      = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $activeTab   = '';
    if (str_contains($active, 'staff-shifts')) {
        $activeTab = 'shifts';
    } elseif (str_contains($active, 'staff-checkin')) {
        $activeTab = 'checkin';
    } elseif (str_contains($active, 'staff-messages')) {
        $activeTab = 'messages';
    } elseif (str_contains($active, 'staff-profile')) {
        $activeTab = 'profile';
    } else {
        $activeTab = 'home';
    }

    $msgUnread = (int) ($ctx['msg_unread'] ?? 0);
    ?>
    <nav class="es-v3__nav" aria-label="Primary navigation">
        <a href="<?= h((string) $ctx['home_url']) ?>" class="es-v3__nav-item<?= $activeTab === 'home' ? ' es-v3__nav-item--active' : '' ?>" <?= $activeTab === 'home' ? 'aria-current="page"' : '' ?>>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9.5 12 3l9 6.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1V9.5z"/></svg>
            <span>Home</span>
        </a>
        <a href="<?= h((string) $ctx['shifts_url']) ?>" class="es-v3__nav-item<?= $activeTab === 'shifts' ? ' es-v3__nav-item--active' : '' ?>" <?= $activeTab === 'shifts' ? 'aria-current="page"' : '' ?>>
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
            <span>Shifts</span>
        </a>
        <a href="staff-checkin.php" class="es-v3__nav-fab" aria-label="Check in">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M3 17v2a2 2 0 0 0 2 2h2"/><path d="M7 12h10"/></svg>
        </a>
        <a href="<?= h((string) $ctx['messages_url']) ?>" class="es-v3__nav-item<?= $activeTab === 'messages' ? ' es-v3__nav-item--active' : '' ?>" <?= $activeTab === 'messages' ? 'aria-current="page"' : '' ?>>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <span>Messages</span>
            <?php if ($msgUnread > 0): ?>
                <span class="es-v3__nav-badge"><?= $msgUnread > 99 ? '99+' : $msgUnread ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= h((string) $ctx['profile_url']) ?>" class="es-v3__nav-item<?= $activeTab === 'profile' ? ' es-v3__nav-item--active' : '' ?>" <?= $activeTab === 'profile' ? 'aria-current="page"' : '' ?>>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span>Profile</span>
        </a>
    </nav>
    <?php
}

/**
 * @param array<string, mixed> $ctx
 */
function renderStaffV3TopBar(array $ctx, bool $showNotif = true): void
{
    $notifUnread = (int) ($ctx['notif_unread'] ?? 0);
    ?>
    <header class="es-v3__topbar">
        <div class="es-v3__profile-chip">
            <div class="es-v3__avatar" aria-hidden="true"><?= h((string) $ctx['avatar_initials']) ?></div>
            <div class="es-v3__profile-text">
                <h1 class="es-v3__profile-name"><?= h((string) $ctx['display_name']) ?></h1>
                <p class="es-v3__profile-employer"><?= h((string) $ctx['company_name']) ?></p>
            </div>
        </div>
        <div class="es-v3__topbar-actions">
        <?php if ($showNotif): ?>
            <a href="<?= h((string) $ctx['notif_url']) ?>" class="es-v3__icon-btn" aria-label="Notifications<?= $notifUnread > 0 ? ' (' . $notifUnread . ' unread)' : '' ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <?php if ($notifUnread > 0): ?>
                    <span class="es-v3__icon-badge" data-notif-badge data-status-token="<?= h((string) $ctx['status_token']) ?>"><?= $notifUnread > 99 ? '99+' : $notifUnread ?></span>
                <?php else: ?>
                    <span class="es-v3__icon-badge" data-notif-badge data-status-token="<?= h((string) $ctx['status_token']) ?>" hidden>0</span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
            <a href="staff-signout.php?return=staff-app.php" class="es-v3__signout-btn">Sign out</a>
        </div>
    </header>
    <?php
}

/**
 * @param array<string, mixed> $row
 */
/**
 * @param array{percent: int, label: string, state: string} $progress
 */
function renderStaffV3ShiftProgressBar(array $progress): void
{
    if (($progress['label'] ?? '') === '') {
        return;
    }
    $percent = (int) ($progress['percent'] ?? 0);
    $state   = (string) ($progress['state'] ?? 'upcoming');
    ?>
    <div class="es-v3__shift-progress" data-shift-progress="<?= h($state) ?>">
        <div class="es-v3__shift-progress-meta">
            <span class="es-v3__shift-progress-label"><?= h((string) $progress['label']) ?></span>
            <?php if ($percent > 0 && $percent < 100): ?>
                <span class="es-v3__shift-progress-pct"><?= $percent ?>%</span>
            <?php endif; ?>
        </div>
        <div class="es-v3__shift-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $percent ?>">
            <span class="es-v3__shift-progress-fill" style="width:<?= $percent ?>%"></span>
        </div>
    </div>
    <?php
}

function renderStaffV3ShiftCard(array $row, PDO $pdo, string $companyFallback, bool $compact = false): void
{
    require_once __DIR__ . '/email-branding.php';

    $statusMeta = getStaffV3ShiftStatusMeta($row);
    $employer   = getStaffV3EmployerLabel($row, $companyFallback);
    $hoursLabel = getStaffV3ShiftHoursLabel($row);
    $payLabel   = formatEmailPayRateLabel($pdo, $row);
    $progress   = getStaffV3ShiftTimeProgress($row);
    $venue      = formatStaffStatusVenueLabel($row);
    $eventDate  = !empty($row['event_date']) ? formatEventDateLabel((string) $row['event_date']) : '—';
    ?>
    <article class="es-v3__shift-card<?= $compact ? ' es-v3__shift-card--compact' : '' ?>">
        <div class="es-v3__shift-card-top">
            <h3 class="es-v3__shift-location"><?= h((string) ($row['event_name'] ?? 'Event')) ?></h3>
            <span class="es-v3__badge es-v3__badge--<?= h($statusMeta['tone']) ?>"><?= h($statusMeta['label']) ?></span>
        </div>
        <p class="es-v3__shift-venue"><?= h($venue) ?></p>
        <div class="es-v3__shift-meta">
            <span class="es-v3__shift-date">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                <?= h($eventDate) ?>
            </span>
            <span class="es-v3__shift-time">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                <?= h(formatStaffV3ShiftTime($row)) ?>
            </span>
        </div>
        <?php renderStaffV3ShiftProgressBar($progress); ?>
        <div class="es-v3__shift-footer">
            <span class="es-v3__employer-badge"><?= h($employer) ?></span>
            <?php if ($hoursLabel !== ''): ?>
                <span class="es-v3__shift-hours"><?= h($hoursLabel) ?></span>
            <?php endif; ?>
            <span class="es-v3__shift-hours"><?= h($payLabel) ?></span>
        </div>
        <?php
        require_once __DIR__ . '/event-whatsapp.php';
        $eventWaUrl = normalizeWhatsappGroupUrl((string) ($row['event_whatsapp_group_url'] ?? ''));
        if ($eventWaUrl !== '' && (string) ($row['status'] ?? '') === 'approved'):
        ?>
        <div class="es-v3__shift-wa">
            <a href="<?= h($eventWaUrl) ?>" class="es-v3__shift-wa-btn" target="_blank" rel="noopener noreferrer">Join event WhatsApp group</a>
        </div>
        <?php endif; ?>
    </article>
    <?php
}
