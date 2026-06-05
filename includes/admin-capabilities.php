<?php

/** @return list<string> */function getAdminRoleOptions(): array
{
    return ['admin', 'manager', 'staff'];
}

function formatAdminRoleLabel(string $role): string
{
    return match ($role) {
        'admin'   => 'Administrator',
        'manager' => 'Manager',
        'staff'   => 'Staff',
        default   => ucfirst($role),
    };
}

/**
 * Capabilities per role. Admin has full access via wildcard.
 *
 * @return list<string>
 */
function getCapabilitiesForRole(string $role): array
{
    return match ($role) {
        'admin' => ['*'],
        'manager' => [
            'dashboard', 'staff', 'events', 'attendance', 'invoices', 'export', 'audit', 'apply',
        ],
        'staff' => [
            'dashboard', 'attendance',
        ],
        default => [],
    };
}

/** Full administrator (can override incomplete profiles and edit staff records). */
function isAdminSuperUser(): bool
{
    return getAdminRole() === 'admin';
}

function adminCan(string $capability): bool
{
    if (!isAdminLoggedIn()) {
        return false;
    }

    $role = getAdminRole();
    $caps = getCapabilitiesForRole($role);

    if (in_array('*', $caps, true)) {
        return true;
    }

    return in_array($capability, $caps, true);
}

function requireAdminCapability(string $capability): void
{
    requireAdmin();

    if (!adminCan($capability)) {
        setAdminFlash('error', 'You do not have permission to access that area.');
        header('Location: dashboard.php');
        exit;
    }
}

/**
 * @return array<int, array{section: string, items: list<array{key: string, label: string, url: string, icon: string, cap: string}>}>
 */
function getAdminSidebarSections(): array
{
    return [
        [
            'section' => 'Operations',
            'items'   => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'url' => 'dashboard.php', 'icon' => 'dashboard', 'cap' => 'dashboard'],
                ['key' => 'notifications', 'label' => 'Notifications', 'url' => 'notifications.php', 'icon' => 'bell', 'cap' => 'dashboard'],
                ['key' => 'staff', 'label' => 'Staff', 'url' => 'staff.php', 'icon' => 'staff', 'cap' => 'staff'],
                ['key' => 'staff-directory', 'label' => 'Staff Directory', 'url' => 'staff-directory.php', 'icon' => 'staff', 'cap' => 'staff'],
                ['key' => 'blacklist', 'label' => 'Blacklist', 'url' => 'blacklist.php', 'icon' => 'staff', 'cap' => 'staff'],
                ['key' => 'events', 'label' => 'Events', 'url' => 'events.php', 'icon' => 'events', 'cap' => 'events'],
                ['key' => 'venues', 'label' => 'Venues', 'url' => 'venues.php', 'icon' => 'events', 'cap' => 'events'],
                ['key' => 'work-types', 'label' => 'Work types', 'url' => 'work-types.php', 'icon' => 'events', 'cap' => 'events'],
                ['key' => 'attendance', 'label' => 'Attendance', 'url' => 'attendance.php', 'icon' => 'attendance', 'cap' => 'attendance'],
                ['key' => 'work-hours', 'label' => 'Work hours', 'url' => 'work-hours.php', 'icon' => 'activity', 'cap' => 'attendance'],
                ['key' => 'invoices', 'label' => 'Invoices', 'url' => 'invoices.php', 'icon' => 'export', 'cap' => 'invoices'],
                ['key' => 'scan-checkin', 'label' => 'Scan check-in', 'url' => 'scan-checkin.php', 'icon' => 'attendance', 'cap' => 'attendance'],
                ['key' => 'export', 'label' => 'Export', 'url' => 'export-staff.php', 'icon' => 'export', 'cap' => 'export'],
            ],
        ],
        [
            'section' => 'Site',
            'items'   => [
                ['key' => 'forms', 'label' => 'Registration forms', 'url' => 'forms.php', 'icon' => 'form', 'cap' => 'forms'],
                ['key' => 'apply', 'label' => 'Apply admin', 'url' => 'apply-portal.php', 'icon' => 'form', 'cap' => 'apply'],
            ],
        ],
        [
            'section' => 'System',
            'items'   => [
                ['key' => 'go-live', 'label' => 'Go live', 'url' => 'go-live.php', 'icon' => 'activity', 'cap' => 'settings'],
                ['key' => 'settings', 'label' => 'ERP settings', 'url' => 'settings-site.php', 'icon' => 'settings', 'cap' => 'settings'],
                ['key' => 'website', 'label' => 'Website CMS', 'url' => 'website-global.php?panel=cms', 'icon' => 'website', 'cap' => 'website'],
                ['key' => 'visitor-locations', 'label' => 'Visitor locations', 'url' => 'visitor-locations.php', 'icon' => 'geo', 'cap' => 'audit'],
                ['key' => 'geo-audits', 'label' => 'Login geo audits', 'url' => 'geo-audits.php', 'icon' => 'activity', 'cap' => 'audit'],
                ['key' => 'audit-log', 'label' => 'Activity logs', 'url' => 'audit-log.php', 'icon' => 'activity', 'cap' => 'audit'],
                ['key' => 'users', 'label' => 'Team users', 'url' => 'users.php', 'icon' => 'users', 'cap' => 'users'],
            ],
        ],
    ];
}

function isAdminSidebarLinkActive(string $key, string $activePage): bool
{
    if ($key === $activePage) {
        return true;
    }

    return match ($key) {
        'export'           => str_starts_with($activePage, 'export'),
        'settings'         => str_starts_with($activePage, 'settings') || $activePage === 'backups' || $activePage === 'go-live'
            || ($activePage === 'website-global' && (($_GET['panel'] ?? '') !== 'cms')),
        'go-live'          => $activePage === 'go-live',
        'website'          => str_starts_with($activePage, 'website') || ($activePage === 'website-global' && (($_GET['panel'] ?? '') === 'cms')),
        'staff'            => $activePage === 'staff' || $activePage === 'blacklist',
        'staff-directory'  => $activePage === 'staff-directory',
        'blacklist'        => $activePage === 'blacklist',
        'venues'           => $activePage === 'venues',
        'work-hours'       => $activePage === 'work-hours',
        'invoices'         => $activePage === 'invoices' || str_starts_with($activePage, 'invoice'),
        default            => false,
    };
}
