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

/** Full administrator (danger zone, user management, system settings). */
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
 * @return array{code: string, accent: string}
 */
function getAdminSidebarSectionMeta(string $section): array
{
    return match ($section) {
        'Home'           => ['code' => '01', 'accent' => 'violet', 'icon' => 'dashboard'],
        'Staff'          => ['code' => '02', 'accent' => 'pink', 'icon' => 'staff'],
        'Events'         => ['code' => '03', 'accent' => 'violet', 'icon' => 'events'],
        'Communications' => ['code' => '04', 'accent' => 'pink', 'icon' => 'message'],
        'Finance'        => ['code' => '05', 'accent' => 'pink', 'icon' => 'export'],
        'Portal'         => ['code' => '06', 'accent' => 'violet', 'icon' => 'form'],
        'Admin'          => ['code' => '07', 'accent' => 'slate', 'icon' => 'settings'],
        'Tools'          => ['code' => '08', 'accent' => 'slate', 'icon' => 'activity'],
        'People'         => ['code' => '02', 'accent' => 'pink', 'icon' => 'staff'],
        'Site'           => ['code' => '06', 'accent' => 'violet', 'icon' => 'website'],
        'System'         => ['code' => '07', 'accent' => 'slate', 'icon' => 'settings'],
        'Operations'     => ['code' => '00', 'accent' => 'violet', 'icon' => 'activity'],
        'Workforce'      => ['code' => '09', 'accent' => 'pink', 'icon' => 'staff'],
        'Automation'     => ['code' => '10', 'accent' => 'violet', 'icon' => 'activity'],
        default          => ['code' => '00', 'accent' => 'violet', 'icon' => 'activity'],
    };
}

/**
 * @return array<int, array{section: string, items: list<array{key: string, label: string, url: string, icon: string, cap: string}>}>
 */
function getAdminSidebarSections(): array
{
    return [
        [
            'section' => 'Home',
            'items'   => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'url' => 'dashboard.php', 'icon' => 'dashboard', 'cap' => 'dashboard'],
            ],
        ],
        [
            'section' => 'Staff',
            'items'   => [
                ['key' => 'staff', 'label' => 'Queue', 'url' => 'staff.php', 'icon' => 'staff', 'cap' => 'staff'],
                ['key' => 'allocation-centre', 'label' => 'Allocation centre', 'url' => 'allocation-centre.php', 'icon' => 'events', 'cap' => 'staff'],
                ['key' => 'staff-directory', 'label' => 'Directory', 'url' => 'staff-directory.php', 'icon' => 'staff', 'cap' => 'staff'],
                ['key' => 'blacklist', 'label' => 'Blacklist', 'url' => 'blacklist.php', 'icon' => 'staff', 'cap' => 'staff'],
            ],
        ],
        [
            'section' => 'Events',
            'items'   => [
                ['key' => 'events', 'label' => 'Events', 'url' => 'events.php', 'icon' => 'events', 'cap' => 'events'],
                ['key' => 'venues', 'label' => 'Venues', 'url' => 'venues.php', 'icon' => 'events', 'cap' => 'events'],
                ['key' => 'work-types', 'label' => 'Work types', 'url' => 'work-types.php', 'icon' => 'events', 'cap' => 'events'],
                ['key' => 'attendance', 'label' => 'Attendance', 'url' => 'attendance.php', 'icon' => 'attendance', 'cap' => 'attendance'],
                ['key' => 'work-hours', 'label' => 'Work hours', 'url' => 'work-hours.php', 'icon' => 'activity', 'cap' => 'attendance'],
                ['key' => 'manual-signin', 'label' => 'Manual sign-in', 'url' => 'manual-signin.php', 'icon' => 'attendance', 'cap' => 'attendance'],
            ],
        ],
        [
            'section' => 'Communications',
            'items'   => [
                ['key' => 'staff-inbox', 'label' => 'Messages', 'url' => 'staff-inbox.php', 'icon' => 'message', 'cap' => 'staff'],
                ['key' => 'communication-hub', 'label' => 'Communication Hub', 'url' => 'communication-hub.php', 'icon' => 'message', 'cap' => 'staff'],
                ['key' => 'notifications', 'label' => 'Notifications', 'url' => 'notifications.php', 'icon' => 'activity', 'cap' => 'dashboard'],
            ],
        ],
        [
            'section' => 'Workforce',
            'items'   => [
                ['key' => 'workforce-performance', 'label' => 'Performance', 'url' => 'workforce-performance.php', 'icon' => 'staff', 'cap' => 'staff'],
                ['key' => 'workforce-risk', 'label' => 'Risk management', 'url' => 'workforce-risk.php', 'icon' => 'staff', 'cap' => 'staff'],
                ['key' => 'event-staffing', 'label' => 'Event staffing', 'url' => 'event-staffing.php', 'icon' => 'events', 'cap' => 'staff'],
                ['key' => 'compliance-centre', 'label' => 'Compliance', 'url' => 'compliance-centre.php', 'icon' => 'staff', 'cap' => 'staff'],
                ['key' => 'staff-documents', 'label' => 'Documents', 'url' => 'staff-documents.php', 'icon' => 'staff', 'cap' => 'staff'],
                ['key' => 'staff-search', 'label' => 'Smart search', 'url' => 'staff-search.php', 'icon' => 'staff', 'cap' => 'staff'],
                ['key' => 'staff-availability', 'label' => 'Availability', 'url' => 'staff-availability.php', 'icon' => 'events', 'cap' => 'staff'],
                ['key' => 'staff-preferences', 'label' => 'Staff preferences', 'url' => 'staff-preferences.php', 'icon' => 'staff', 'cap' => 'staff'],
                ['key' => 'operations-reports', 'label' => 'Reports', 'url' => 'operations-reports.php', 'icon' => 'export', 'cap' => 'export'],
                ['key' => 'executive-dashboard', 'label' => 'Executive', 'url' => 'executive-dashboard.php', 'icon' => 'dashboard', 'cap' => 'dashboard'],
                ['key' => 'compliance-audit', 'label' => 'Audit logs', 'url' => 'compliance-audit.php', 'icon' => 'activity', 'cap' => 'audit'],
            ],
        ],
        [
            'section' => 'Automation',
            'items'   => [
                ['key' => 'event-rostering', 'label' => 'Event rostering', 'url' => 'event-rostering.php', 'icon' => 'events', 'cap' => 'events'],
                ['key' => 'recruitment-centre', 'label' => 'Recruitment', 'url' => 'recruitment-centre.php', 'icon' => 'staff', 'cap' => 'staff'],
                ['key' => 'training-centre', 'label' => 'Training', 'url' => 'training-centre.php', 'icon' => 'staff', 'cap' => 'staff'],
                ['key' => 'payroll-centre', 'label' => 'Payroll prep', 'url' => 'payroll-centre.php', 'icon' => 'export', 'cap' => 'export'],
                ['key' => 'communication-centre', 'label' => 'Communications', 'url' => 'communication-centre.php', 'icon' => 'message', 'cap' => 'staff'],
                ['key' => 'incident-centre', 'label' => 'Incidents', 'url' => 'incident-centre.php', 'icon' => 'activity', 'cap' => 'staff'],
                ['key' => 'client-centre', 'label' => 'Clients', 'url' => 'client-centre.php', 'icon' => 'export', 'cap' => 'invoices'],
                ['key' => 'contracts-centre', 'label' => 'Contracts', 'url' => 'contracts-centre.php', 'icon' => 'staff', 'cap' => 'staff'],
                ['key' => 'ops-automation', 'label' => 'Automation', 'url' => 'ops-automation.php', 'icon' => 'activity', 'cap' => 'settings'],
            ],
        ],
        [
            'section' => 'Finance',
            'items'   => [
                ['key' => 'invoices', 'label' => 'Invoices', 'url' => 'invoices.php', 'icon' => 'export', 'cap' => 'invoices'],
                ['key' => 'export', 'label' => 'Export', 'url' => 'export-staff.php', 'icon' => 'export', 'cap' => 'export'],
            ],
        ],
        [
            'section' => 'Portal',
            'items'   => [
                ['key' => 'forms', 'label' => 'Forms', 'url' => 'forms.php', 'icon' => 'form', 'cap' => 'forms'],
                ['key' => 'apply', 'label' => 'Apply admin', 'url' => 'apply-portal.php', 'icon' => 'form', 'cap' => 'apply'],
            ],
        ],
        [
            'section' => 'Admin',
            'items'   => [
                ['key' => 'system-health', 'label' => 'Health', 'url' => 'system-health.php', 'icon' => 'activity', 'cap' => 'settings'],
                ['key' => 'feature-flags', 'label' => 'Feature flags', 'url' => 'feature-flags.php', 'icon' => 'settings', 'cap' => 'settings'],
                ['key' => 'settings', 'label' => 'Settings', 'url' => 'settings-site.php', 'icon' => 'settings', 'cap' => 'settings'],
                ['key' => 'data-integrity', 'label' => 'Data integrity', 'url' => 'data-integrity.php', 'icon' => 'activity', 'cap' => 'settings'],
                ['key' => 'audit-log', 'label' => 'Activity', 'url' => 'audit-log.php', 'icon' => 'activity', 'cap' => 'audit'],
                ['key' => 'users', 'label' => 'Users', 'url' => 'users.php', 'icon' => 'users', 'cap' => 'users'],
            ],
        ],
        [
            'section' => 'Tools',
            'items'   => [
                ['key' => 'go-live', 'label' => 'Go live', 'url' => 'go-live.php', 'icon' => 'activity', 'cap' => 'settings'],
                ['key' => 'website', 'label' => 'Website CMS', 'url' => 'website-global.php?panel=cms', 'icon' => 'website', 'cap' => 'website'],
                ['key' => 'ops', 'label' => 'Ops checklist', 'url' => 'ops-checklist.php', 'icon' => 'activity', 'cap' => 'dashboard'],
                ['key' => 'visitor-locations', 'label' => 'Visitors', 'url' => 'visitor-locations.php', 'icon' => 'geo', 'cap' => 'audit'],
                ['key' => 'geo-audits', 'label' => 'Geo audits', 'url' => 'geo-audits.php', 'icon' => 'activity', 'cap' => 'audit'],
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
        'settings'         => str_starts_with($activePage, 'settings') || $activePage === 'backups' || $activePage === 'backup-center' || $activePage === 'go-live'
            || $activePage === 'settings-preference-locations'
            || ($activePage === 'website-global' && (($_GET['panel'] ?? '') !== 'cms')),
        'feature-flags'    => $activePage === 'feature-flags',
        'go-live'          => $activePage === 'go-live',
        'system-health'    => $activePage === 'system-health',
        'website'          => str_starts_with($activePage, 'website') || ($activePage === 'website-global' && (($_GET['panel'] ?? '') === 'cms')),
        'staff-inbox'      => $activePage === 'staff-inbox' || str_starts_with($activePage, 'staff-inbox'),
        'notifications'    => $activePage === 'notifications',
        'staff'            => $activePage === 'staff',
        'allocation-centre'=> $activePage === 'allocation-centre',
        'staff-directory'  => $activePage === 'staff-directory',
        'blacklist'        => $activePage === 'blacklist',
        'venues'           => $activePage === 'venues',
        'work-hours'       => $activePage === 'work-hours',
        'invoices'         => $activePage === 'invoices' || $activePage === 'job-records' || $activePage === 'personal-invoices'
            || str_starts_with($activePage, 'invoice') || str_starts_with($activePage, 'job-record') || str_starts_with($activePage, 'personal-invoice'),
        'command-center'   => $activePage === 'command-center',
        'unified-inbox'    => $activePage === 'unified-inbox',
        'event-hub'        => $activePage === 'event-hub',
        'trust-scores'     => $activePage === 'trust-scores',
        'ai-ops'           => $activePage === 'ai-ops',
        'auto-approval'    => $activePage === 'auto-approval',
        'payroll-intelligence' => $activePage === 'payroll-intelligence',
        'google-sheets-control' => $activePage === 'google-sheets-control',
        'backup-center'    => $activePage === 'backup-center',
        'data-integrity'   => $activePage === 'data-integrity' || $activePage === 'duplicate-merge',
        'workforce-performance' => $activePage === 'workforce-performance',
        'workforce-risk'   => $activePage === 'workforce-risk',
        'event-staffing'   => $activePage === 'event-staffing',
        'compliance-centre'=> $activePage === 'compliance-centre',
        'staff-documents'  => $activePage === 'staff-documents',
        'staff-search'     => $activePage === 'staff-search',
        'staff-availability' => $activePage === 'staff-availability',
        'staff-preferences'  => $activePage === 'staff-preferences',
        'operations-reports' => $activePage === 'operations-reports',
        'executive-dashboard' => $activePage === 'executive-dashboard',
        'compliance-audit' => $activePage === 'compliance-audit',
        'event-rostering'  => $activePage === 'event-rostering',
        'recruitment-centre' => $activePage === 'recruitment-centre',
        'training-centre'  => $activePage === 'training-centre',
        'payroll-centre'   => $activePage === 'payroll-centre',
        'communication-centre'=> $activePage === 'communication-centre' || $activePage === 'communication-hub',
        'communication-hub' => $activePage === 'communication-hub' || $activePage === 'communication-centre',
        'incident-centre'  => $activePage === 'incident-centre',
        'client-centre'    => $activePage === 'client-centre',
        'contracts-centre' => $activePage === 'contracts-centre' || $activePage === 'contract-centre',
        'contract-centre'  => $activePage === 'contract-centre' || $activePage === 'contracts-centre',
        'ops-automation'   => $activePage === 'ops-automation',
        default            => false,
    };
}
