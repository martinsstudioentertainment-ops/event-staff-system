<?php

declare(strict_types=1);

require_once __DIR__ . '/../feature-flags.php';

/**
 * @return list<array{key: string, label: string, url: string, icon: string, cap: string}>
 */
function getPlatformOpsSidebarItems(PDO $pdo): array
{
    $items = [];

    if (isFeatureEnabled($pdo, 'command_center_v2')) {
        $items[] = ['key' => 'command-center', 'label' => 'Command center', 'url' => 'command-center.php', 'icon' => 'activity', 'cap' => 'dashboard'];
    }
    if (isFeatureEnabled($pdo, 'unified_inbox')) {
        $items[] = ['key' => 'unified-inbox', 'label' => 'Unified inbox', 'url' => 'unified-inbox.php', 'icon' => 'bell', 'cap' => 'dashboard'];
    }
    if (isFeatureEnabled($pdo, 'event_hub')) {
        $items[] = ['key' => 'event-hub', 'label' => 'Event hub', 'url' => 'event-hub.php', 'icon' => 'events', 'cap' => 'events'];
    }
    if (isFeatureEnabled($pdo, 'trust_scores')) {
        $items[] = ['key' => 'trust-scores', 'label' => 'Trust scores', 'url' => 'trust-scores.php', 'icon' => 'staff', 'cap' => 'staff'];
    }

    if (isFeatureEnabled($pdo, 'auto_approval')) {
        $items[] = ['key' => 'auto-approval', 'label' => 'Auto approval', 'url' => 'auto-approval.php', 'icon' => 'staff', 'cap' => 'staff'];
    }

    $items[] = ['key' => 'payroll-intelligence', 'label' => 'Hours reconciliation', 'url' => 'payroll-intelligence.php', 'icon' => 'export', 'cap' => 'invoices'];
    $items[] = ['key' => 'google-sheets-control', 'label' => 'Sheets control', 'url' => 'google-sheets-control.php', 'icon' => 'export', 'cap' => 'events'];
    $items[] = ['key' => 'staff-identity-manager', 'label' => 'Staff Identity Manager', 'url' => 'staff-identity-manager.php', 'icon' => 'staff', 'cap' => 'settings'];
    $items[] = ['key' => 'backup-center', 'label' => 'Backup center', 'url' => 'backup-center.php', 'icon' => 'settings', 'cap' => 'settings'];

    return $items;
}
