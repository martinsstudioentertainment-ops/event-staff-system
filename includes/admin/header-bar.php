<?php

require_once __DIR__ . '/nav-icons.php';

require_once __DIR__ . '/../admin-capabilities.php';



$adminInitials = getAdminUserInitials($adminUser ?? null);

$adminName     = trim((string) ($adminUser['name'] ?? $adminUser['username'] ?? 'Admin'));

$adminRole     = formatAdminRoleLabel(getAdminRole());

?>

<header class="header erp-header">

    <div class="header__left">

        <button class="header__menu-btn" id="menu-toggle" aria-label="Toggle navigation menu" type="button">

            <span></span>

            <span></span>

            <span></span>

        </button>

        <form class="erp-search" action="staff.php" method="get" role="search">

            <span class="erp-search__icon" aria-hidden="true"><?= renderAdminNavIcon('search') ?></span>

            <input class="erp-search__input" type="search" name="q" placeholder="Search staff, events…" aria-label="Search">

        </form>

    </div>

    <div class="header__right">

        <?php if (adminCan('website')): ?>

        <a href="<?= h($homePageUrl ?? (normalizePublicSiteUrl(getAppBaseUrl()) . '/home.php')) ?>" class="header__icon-btn" target="_blank" rel="noopener" title="View homepage">

            <?= renderAdminNavIcon('website') ?>

            <span class="header__icon-btn-label">Site</span>

        </a>

        <?php endif; ?>

        <button class="theme-toggle" id="theme-toggle" aria-label="Toggle dark mode" type="button" title="Toggle theme">☀️</button>

        <div class="header__user" title="<?= h($adminName) ?>">

            <span class="header__user-avatar" aria-hidden="true"><?= h($adminInitials) ?></span>

            <span class="header__user-meta">

                <span class="header__user-name"><?= h($adminName) ?></span>

                <span class="header__user-role"><?= h($adminRole) ?></span>

            </span>

        </div>

    </div>

</header>

