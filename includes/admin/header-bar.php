<?php



require_once __DIR__ . '/nav-icons.php';

require_once __DIR__ . '/../admin-capabilities.php';

require_once __DIR__ . '/../notification-center.php';



$adminInitials = getAdminUserInitials($adminUser ?? null);

$adminName     = trim((string) ($adminUser['name'] ?? $adminUser['username'] ?? 'Admin'));

$adminRole     = formatAdminRoleLabel(getAdminRole());

$notifUnread   = adminCan('dashboard') ? countUnreadAdminNotifications($pdo) : 0;
$msgUnread     = 0;
if (adminCan('staff')) {
    require_once __DIR__ . '/../staff-messages.php';
    $msgUnread = countUnreadStaffMessagesForAdmin($pdo);
}

$pendingCount  = 0;

if (adminCan('staff')) {

    require_once __DIR__ . '/../staff-repository.php';

    $pendingCount = countPendingRegistrations($pdo);

}

$settingsUrl = adminCan('settings') ? 'settings-site.php' : 'settings-account.php';



?>

<header class="header erp-header erp-header--v3">

    <div class="header__left">

        <button class="header__menu-btn" id="menu-toggle" aria-label="Toggle navigation menu" type="button">

            <span></span><span></span><span></span>

        </button>

        <form class="erp-search" action="staff.php" method="get" role="search">

            <span class="erp-search__icon" aria-hidden="true"><?= renderAdminNavIcon('search') ?></span>

            <input class="erp-search__input" type="search" name="q" placeholder="Search staff, events…" aria-label="Search">

        </form>

    </div>



    <div class="header__center erp-header__quick">

        <?php if (adminCan('staff') && $pendingCount > 0): ?>

            <a href="staff.php?status=pending&amp;page=1" class="erp-header__chip erp-header__chip--warn">

                <?= (int) $pendingCount ?> pending

            </a>

        <?php endif; ?>

        <?php if (adminCan('attendance')): ?>

            <a href="scan-checkin.php" class="erp-header__chip">Scan check-in</a>

        <?php endif; ?>

        <?php if (adminCan('events')): ?>

            <a href="events.php" class="erp-header__chip">Events</a>

        <?php endif; ?>

        <?php if (adminCan('export')): ?>

            <a href="export-staff.php" class="erp-header__chip">Export</a>

        <?php endif; ?>

    </div>



    <div class="header__right">

        <?php if (adminCan('website')): ?>

            <a href="<?= h($homePageUrl ?? (normalizePublicSiteUrl(getAppBaseUrl()) . '/home.php')) ?>" class="header__icon-btn" target="_blank" rel="noopener" title="View homepage">

                <?= renderAdminNavIcon('website') ?>

                <span class="header__icon-btn-label">Site</span>

            </a>

        <?php endif; ?>



        <?php if (adminCan('dashboard')): ?>

            <a href="notifications.php" class="header__icon-btn erp-header__notif" title="Notifications">

                <?= renderAdminNavIcon('bell') ?>

                <span
                    class="erp-header__notif-badge"
                    data-admin-notif-header-badge
                    <?= $notifUnread > 0 ? '' : 'hidden' ?>
                ><?= $notifUnread > 99 ? '99+' : (int) $notifUnread ?></span>

            </a>

        <?php endif; ?>

        <?php if (adminCan('staff')): ?>

            <a href="staff-inbox.php" class="header__icon-btn erp-header__notif" title="Messages">

                <?= renderAdminNavIcon('message') ?>

                <?php if ($msgUnread > 0): ?>

                    <span class="erp-header__notif-badge"><?= $msgUnread > 99 ? '99+' : (int) $msgUnread ?></span>

                <?php endif; ?>

            </a>

        <?php endif; ?>



        <button type="button" class="header__icon-btn header__icon-btn--install" id="admin-app-install-btn" title="Install admin app" aria-label="Install admin app">

            <?= renderAdminNavIcon('external') ?>

        </button>



        <div class="erp-header__user-menu">

            <button type="button" class="erp-header__user-trigger" id="admin-user-menu-btn" aria-expanded="false" aria-haspopup="true">

                <span class="header__user-avatar" aria-hidden="true"><?= h($adminInitials) ?></span>

                <span class="header__user-meta">

                    <span class="header__user-name"><?= h($adminName) ?></span>

                    <span class="header__user-role"><?= h($adminRole) ?></span>

                </span>

            </button>

            <div class="erp-header__user-dropdown" id="admin-user-menu" hidden>

                <a href="settings-account.php">Profile &amp; password</a>

                <?php if (adminCan('settings')): ?>

                    <a href="<?= h($settingsUrl) ?>">Settings</a>

                    <a href="system-health.php">System health</a>

                    <a href="go-live.php">Go live</a>

                <?php endif; ?>

                <?php if (adminCan('staff')): ?>

                    <a href="staff-inbox.php">Messages</a>

                <?php endif; ?>

                <a href="logout.php" class="erp-header__user-signout">Sign out</a>

            </div>

        </div>

    </div>

</header>

