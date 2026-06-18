<?php

require_once __DIR__ . '/../admin-capabilities.php';

/** @return array<int, array{key: string, label: string, url: string, icon: string, cap: string}> */
function getErpSettingsNavItems(): array
{
    return [
        ['key' => 'general',  'label' => 'General',     'url' => 'settings-site.php',        'icon' => 'general',  'cap' => 'settings'],
        ['key' => 'branding', 'label' => 'Branding',    'url' => 'website-global.php',       'icon' => 'branding', 'cap' => 'settings'],
        ['key' => 'ui',       'label' => 'UI controls', 'url' => 'settings-theme.php',     'icon' => 'ui',       'cap' => 'settings'],
        ['key' => 'system',   'label' => 'System',      'url' => 'settings-production.php', 'icon' => 'system',   'cap' => 'settings'],
        ['key' => 'mobile',   'label' => 'Mobile app',  'url' => 'settings-mobile-portal.php', 'icon' => 'general', 'cap' => 'settings'],
        ['key' => 'preference-locations', 'label' => 'Preference locations', 'url' => 'settings-preference-locations.php', 'icon' => 'geo', 'cap' => 'settings'],
        ['key' => 'email',    'label' => 'Email',       'url' => 'settings-email.php',       'icon' => 'email',    'cap' => 'settings'],
        ['key' => 'security', 'label' => 'Security',    'url' => 'settings-security.php',    'icon' => 'security', 'cap' => 'settings'],
        ['key' => 'backup',   'label' => 'Backup',      'url' => 'backup-center.php',        'icon' => 'backup',   'cap' => 'backups'],
    ];
}

/** @deprecated Use getErpSettingsNavItems() */
function getAdminSettingsNavItems(): array
{
    return array_map(static fn (array $item): array => [
        'key'   => $item['key'],
        'label' => $item['label'],
        'url'   => $item['url'],
    ], getErpSettingsNavItems());
}

function renderErpSettingsLayoutStart(string $activeKey): void
{
    require_once __DIR__ . '/nav-icons.php';
    require_once __DIR__ . '/../company.php';
    require_once __DIR__ . '/../brand-logo.php';

    $pdo         = getDB();
    $companyName = getCompanyName($pdo);
    $logoUrl     = getCompanyLogoUrl($pdo, '../');
    ?>
    <div class="erp-settings-hub">
        <div class="erp-settings-hub__header">
            <div class="erp-settings-hub__intro">
                <h1 class="erp-settings-hub__title">ERP Settings Control Center</h1>
                <p class="erp-settings-hub__subtitle">Manage ERP branding, appearance, compact UI scaling, security, email configuration and system settings.</p>
            </div>
            <div class="erp-settings-hub__actions">
                <?php if (adminCan('settings')): ?>
                    <a href="website-global.php?panel=cms" class="btn btn--secondary btn--sm">Website CMS</a>
                <?php endif; ?>
                <?php if (adminCan('settings')): ?>
                    <a href="settings-production.php#maintenance-toggles" class="btn btn--secondary btn--sm erp-settings-hub__maint">
                        <?= renderAdminNavIcon('maintenance') ?>
                        <span>Maintenance</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <div class="erp-settings-layout">
            <aside class="erp-settings-sidecard">
                <div class="erp-settings-brand">
                    <?php if ($logoUrl !== ''): ?>
                        <img class="erp-settings-brand__logo" src="<?= h($logoUrl) ?>" alt="" decoding="async">
                    <?php else: ?>
                        <div class="erp-settings-brand__logo-slot" aria-hidden="true">
                            <span class="erp-settings-brand__logo-slot-label">Company logo</span>
                        </div>
                    <?php endif; ?>
                    <div class="erp-settings-brand__text">
                        <p class="erp-settings-brand__name"><?= h($companyName) ?></p>
                        <p class="erp-settings-brand__meta">System Settings</p>
                    </div>
                </div>
                <nav class="erp-settings-nav" aria-label="ERP settings sections">
                    <?php foreach (getErpSettingsNavItems() as $item): ?>
                        <?php if (!adminCan($item['cap'])) {
                            continue;
                        } ?>
                        <a href="<?= h($item['url']) ?>" class="erp-settings-nav__link<?= $item['key'] === $activeKey ? ' erp-settings-nav__link--active' : '' ?>">
                            <span class="erp-settings-nav__icon"><?= renderAdminNavIcon($item['icon']) ?></span>
                            <span class="erp-settings-nav__label"><?= h($item['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </aside>
            <div class="erp-settings-main">
    <?php
}

function renderErpSettingsLayoutEnd(): void
{
    echo '</div></div></div>';
}
/** @return array<int, array{key: string, label: string, url: string}> */
function getAdminWebsiteNavItems(): array
{
    return [
        ['key' => 'global',  'label' => 'Global & brand', 'url' => 'website-global.php'],
        ['key' => 'home',    'label' => 'Homepage',       'url' => 'website-home.php'],
        ['key' => 'roles',   'label' => 'Roles',          'url' => 'website-roles.php'],
        ['key' => 'events',  'label' => 'Events page',    'url' => 'website-events.php'],
        ['key' => 'how',     'label' => 'How it works',   'url' => 'website-how.php'],
        ['key' => 'contact', 'label' => 'Contact',        'url' => 'website-contact.php'],
        ['key' => 'faq',     'label' => 'FAQ',            'url' => 'website-faq.php'],
    ];
}

/** @return array<int, array{key: string, label: string, url: string}> */
function getAdminExportNavItems(): array
{
    return [
        ['key' => 'staff',      'label' => 'Staff CSV',      'url' => 'export-staff.php'],
        ['key' => 'attendance', 'label' => 'Attendance CSV', 'url' => 'export-attendance.php'],
        ['key' => 'signins',    'label' => 'Event sign-ins', 'url' => 'export-event-signins.php'],
        ['key' => 'work-hours', 'label' => 'Work hours', 'url' => 'work-hours.php'],
    ];
}

function renderAdminSectionNav(array $items, string $activeKey): void
{
    if ($items === []) {
        return;
    }
    ?>
    <nav class="admin-section-nav" aria-label="Section pages">
        <?php foreach ($items as $item): ?>
            <a href="<?= h($item['url']) ?>" class="admin-section-nav__link<?= $item['key'] === $activeKey ? ' admin-section-nav__link--active' : '' ?>">
                <?= h($item['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php
}

function websiteTabRedirectUrl(string $tab): string
{
    $map = [
        'global'  => 'website-global.php',
        'home'    => 'website-home.php',
        'roles'   => 'website-roles.php',
        'events'  => 'website-events.php',
        'how'     => 'website-how.php',
        'contact' => 'website-contact.php',
        'faq'     => 'website-faq.php',
    ];

    return $map[$tab] ?? 'website-global.php';
}
