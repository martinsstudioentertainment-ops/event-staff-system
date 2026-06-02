<?php
/**
 * Set main_security_company on events (default: all active upcoming).
 *
 * Usage:
 *   php database/apply-main-security-company.php "1plus Security"
 *   php database/apply-main-security-company.php "1plus Security" --all-active
 */

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/event-main-security-schema.php';

$company = trim((string) ($argv[1] ?? ''));
$allActive = in_array('--all-active', $argv ?? [], true);

if ($company === '') {
    echo "Usage: php database/apply-main-security-company.php \"Company Name\" [--all-active]\n";
    exit(1);
}

$pdo = getDB();
ensureEventMainSecuritySchema($pdo);

$sql = 'UPDATE events SET main_security_company = :company WHERE is_active = 1';
if (!$allActive) {
    $sql .= ' AND event_date >= CURDATE()';
}

$stmt = $pdo->prepare($sql);
$stmt->execute(['company' => $company]);
$count = $stmt->rowCount();

$scope = $allActive ? 'all active events' : 'active upcoming events (today and later)';
echo "Updated {$count} {$scope} to main security: {$company}\n";
