<?php

declare(strict_types=1);

require_once __DIR__ . '/../feature-flags.php';

/**
 * Extra Operations section items. Empty on purpose — daily links live in the main sidebar.
 * Pages (command center, backup, etc.) remain reachable by direct URL.
 *
 * @return list<array{key: string, label: string, url: string, icon: string, cap: string}>
 */
function getPlatformOpsSidebarItems(PDO $pdo): array
{
    return [];
}
