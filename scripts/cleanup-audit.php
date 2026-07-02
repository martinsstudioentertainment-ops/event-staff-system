<?php

declare(strict_types=1);

/**
 * CLI cleanup audit — read-only, writes storage/reports/cleanup-*.json
 * Usage: php scripts/cleanup-audit.php [--report-only]
 */

$root = dirname(__DIR__);
require_once $root . '/includes/cleanup-audit.php';

$reportOnly = in_array('--report-only', $argv ?? [], true);

try {
    $report = runCleanupAudit($root);
    $path   = writeCleanupAuditReport($report, $root);

    $summary = $report['summary'] ?? [];
    $total   = (int) ($summary['total_findings'] ?? 0);

    fwrite(STDOUT, "Cleanup audit complete — {$total} finding(s).\n");
    fwrite(STDOUT, "Report: {$path}\n");

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Cleanup audit failed: ' . $e->getMessage() . "\n");
    exit(1);
}
