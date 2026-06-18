<?php
/**
 * Nightly hours reconciliation scan — detect missing hours, duplicates, and mismatches.
 *
 * Schedule daily, e.g. 02:30:
 *   30 2 * * * php /path/to/event-staff-system/cron/hours-reconciliation-scan.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/platform/payroll-intelligence.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');

if (!$isCli) {
    try {
        $pdo = getDB();
        $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
        $providedKey = trim((string) ($_GET['key'] ?? ''));

        if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "Forbidden\n";
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo "Error\n";
        exit;
    }
}

$pdo   = getDB();
$found = runPayrollIntelligenceScan($pdo);
setSetting($pdo, 'payroll_intelligence_last_scan', gmdate('Y-m-d H:i:s') . ' UTC');

$total = array_sum($found);
$line  = '[EventStaff] hours reconciliation scan: ' . $total . ' alerts (' . json_encode($found) . ')';
error_log($line);

if ($isCli) {
    echo $line . "\n";
}
