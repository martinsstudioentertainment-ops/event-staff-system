<?php
/**
 * Remove on-site security company name from events (e.g. after wrong import).
 *
 * Usage:
 *   php database/clear-main-security-company.php
 *   php database/clear-main-security-company.php --upcoming-only
 */

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/event-main-security-schema.php';

$upcomingOnly = in_array('--upcoming-only', $argv ?? [], true);

$pdo = getDB();
ensureEventMainSecuritySchema($pdo);

$sql = "UPDATE events SET main_security_company = NULL WHERE TRIM(COALESCE(main_security_company, '')) != ''";
if ($upcomingOnly) {
    $sql .= ' AND is_active = 1 AND event_date >= CURDATE()';
}

$count = $pdo->exec($sql);
$scope = $upcomingOnly ? 'upcoming active events' : 'all events';
echo "Cleared on-site security company on {$count} {$scope}.\n";
