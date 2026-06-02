<?php

require_once __DIR__ . '/nav-icons.php';

require_once __DIR__ . '/../admin-capabilities.php';



$adminInitials = getAdminUserInitials($adminUser ?? null);

$adminName     = trim((string) ($adminUser['name'] ?? $adminUser['username'] ?? 'Admin'));

$adminRole     = formatAdminRoleLabel(getAdminRole());

?>

<aside class="sidebar" id="sidebar">

    <div class="sidebar__brand">

        <div class="sidebar__logo brand-icon" aria-hidden="true"><?= renderThemeBrandIcon($pdo) ?></div>

        <div class="sidebar__brand-text">

            <div class="sidebar__title"><?= h($siteName ?? 'Event Staff') ?></div>

            <div class="sidebar__badge">ERP Console</div>

        </div>

    </div>



    <nav class="sidebar__nav" aria-label="Admin navigation">

        <?php foreach (getAdminSidebarSections() as $section): ?>

            <?php

            $visibleItems = array_values(array_filter(

                $section['items'],

                static fn (array $item): bool => adminCan($item['cap'])

            ));

            if ($visibleItems === []) {

                continue;

            }

            ?>

            <div class="sidebar__nav-label"><?= h($section['section']) ?></div>

            <?php foreach ($visibleItems as $item): ?>

                <a href="<?= h($item['url']) ?>" class="sidebar__link<?= isAdminSidebarLinkActive($item['key'], $activePage) ? ' sidebar__link--active' : '' ?>">

                    <span class="sidebar__link-icon"><?= renderAdminNavIcon($item['icon']) ?></span>

                    <?= h($item['label']) ?>

                </a>

            <?php endforeach; ?>

        <?php endforeach; ?>

    </nav>



    <div class="sidebar__bottom">

        <?php if (adminCan('website')): ?>

        <div class="sidebar__quick-links">

            <a href="<?= h($homePageUrl ?? (normalizePublicSiteUrl(getAppBaseUrl()) . '/home.php')) ?>" class="sidebar__quick-link" target="_blank" rel="noopener" title="View public homepage">

                <?= renderAdminNavIcon('external') ?>

                <span>Homepage</span>

            </a>

            <a href="<?= h($registrationFormUrl ?? getRegistrationFormUrl($pdo)) ?>" class="sidebar__quick-link" target="_blank" rel="noopener" title="Open registration form">

                <?= renderAdminNavIcon('external') ?>

                <span>Register</span>

            </a>

        </div>

        <?php endif; ?>

        <a href="logout.php" class="sidebar__logout">

            <span class="sidebar__link-icon"><?= renderAdminNavIcon('logout') ?></span>

            Log out

        </a>

    </div>

</aside>

<div class="sidebar-overlay" id="sidebar-overlay"></div>

