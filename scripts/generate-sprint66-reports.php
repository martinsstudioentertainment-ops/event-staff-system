<?php

declare(strict_types=1);

/**
 * Sprint 6.6 — generate all data integrity HTML reports.
 * Run: php scripts/generate-sprint66-reports.php
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/platform/data-integrity-reports.php';

try {
    $result = generateSprint66DataIntegrityReports();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

if (!$result['ok']) {
    fwrite(STDERR, $result['message'] . PHP_EOL);
    exit(1);
}

echo "Sprint 6.6 reports written to docs/\n";
foreach ($result['written'] as $f) {
    echo "  - {$f}\n";
}
echo "\nIntegrity score: " . (int) $result['integrity_score'] . "%\n";
echo "Vault health: " . (int) $result['vault_score'] . "%\n";
echo "Test accounts: " . (int) $result['test_accounts'] . "\n";
echo "Merge recommendations: " . (int) $result['merge_recs'] . "\n";
