<?php



require_once __DIR__ . '/nav-icons.php';

require_once __DIR__ . '/../admin-capabilities.php';

require_once __DIR__ . '/../notification-center.php';

require_once __DIR__ . '/../staff-messages.php';
require_once __DIR__ . '/../platform/sidebar-ops.php';

$adminNotifUnread = adminCan('dashboard') ? countUnreadAdminNotifications($pdo) : 0;
$adminMsgUnread   = adminCan('staff') ? countUnreadStaffMessagesForAdmin($pdo) : 0;

$sidebarSections = getAdminSidebarSections();
$opsItems        = getPlatformOpsSidebarItems($pdo);
if ($opsItems !== []) {
    array_splice($sidebarSections, 1, 0, [[
        'section' => 'Operations',
        'items'   => $opsItems,
    ]]);
}

?>

<aside class="sidebar erp-sidebar erp-sidebar--v3" id="sidebar" aria-label="Admin navigation">

    <header class="erp-sidebar__header">

        <a href="dashboard.php" class="erp-sidebar__brand">

            <span class="erp-sidebar__logo brand-icon" aria-hidden="true"><?= renderThemeBrandIcon($pdo) ?></span>

            <span class="erp-sidebar__brand-text">

                <span class="erp-sidebar__brand-name"><?= h($siteName ?? 'Event Staff') ?></span>

            </span>

        </a>

        <button type="button" class="erp-sidebar__collapse-btn" id="sidebar-collapse" aria-label="Collapse sidebar" aria-pressed="false">

            <?= renderAdminNavIcon('ui') ?>

        </button>

    </header>



    <nav class="erp-sidebar__nav" aria-label="Modules">

        <?php foreach ($sidebarSections as $section): ?>

            <?php

            $visibleItems = array_values(array_filter(

                $section['items'],

                static fn (array $item): bool => adminCan($item['cap'])

            ));

            if ($visibleItems === []) {

                continue;

            }

            ?>

            <div class="erp-sidebar__section">

                <p class="erp-sidebar__section-label"><?= h($section['section']) ?></p>

                <div class="erp-sidebar__section-links">

                    <?php foreach ($visibleItems as $item): ?>

                        <?php $isActive = isAdminSidebarLinkActive($item['key'], $activePage); ?>

                        <a

                            href="<?= h($item['url']) ?>"

                            class="erp-sidebar__link<?= $isActive ? ' erp-sidebar__link--active' : '' ?>"

                            data-tooltip="<?= h($item['label']) ?>"

                            <?= $isActive ? 'aria-current="page"' : '' ?>

                        >

                            <span class="erp-sidebar__link-icon" aria-hidden="true"><?= renderAdminNavIcon($item['icon']) ?></span>

                            <span class="erp-sidebar__link-text"><?= h($item['label']) ?></span>

                            <?php if ($item['key'] === 'notifications'): ?>

                                <span

                                    class="erp-sidebar__badge"

                                    data-admin-notif-badge

                                    <?= $adminNotifUnread > 0 ? '' : ' hidden' ?>

                                ><?= $adminNotifUnread > 99 ? '99+' : (int) $adminNotifUnread ?></span>

                            <?php endif; ?>

                            <?php if ($item['key'] === 'staff-inbox'): ?>

                                <span class="erp-sidebar__badge" <?= $adminMsgUnread > 0 ? '' : ' hidden' ?>><?= $adminMsgUnread > 99 ? '99+' : (int) $adminMsgUnread ?></span>

                            <?php endif; ?>

                        </a>

                    <?php endforeach; ?>

                </div>

            </div>

        <?php endforeach; ?>

    </nav>

</aside>



<div class="erp-sidebar-tooltip" id="erp-sidebar-tooltip" role="tooltip" hidden></div>

<div class="sidebar-overlay" id="sidebar-overlay"></div>

