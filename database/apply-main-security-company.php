<?php
/**
 * Set main_security_company on events (default: all active upcoming).
 *
 * Usage:
 *   php database/apply-main-security-company.php
 *   php database/apply-main-security-company.php "Contractor Name" --all-active
 */

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/event-main-security-schema.php';

$allActive = in_array('--all-active', $argv ?? [], true);
$company   = '';
foreach ($argv as $i => $arg) {
    if ($i === 0 || str_starts_with($arg, '--')) {
        continue;
    }
    $company = trim($arg);
    break;
}

if ($company === '') {
    $dataFile = __DIR__ . '/live-events-2026.php';
    if (is_file($dataFile)) {
        $loaded = require $dataFile;
        if (is_array($loaded) && trim((string) ($loaded['main_security_company'] ?? '')) !== '') {
            $company = trim((string) $loaded['main_security_company']);
        }
    }
}

if ($company === '') {
    fwrite(STDERR, "Usage: php database/apply-main-security-company.php \"Company name\" [--all-active]\n");
    fwrite(STDERR, "To clear the field on events: php database/clear-main-security-company.php\n");
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
