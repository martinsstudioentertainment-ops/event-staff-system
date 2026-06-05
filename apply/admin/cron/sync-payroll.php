<?php

declare(strict_types=1);

/**
 * Payroll + Google Sheets sync endpoint (cron key or main ERP trigger).
 * Throttled to ~2 minutes unless ?force=1 (e.g. after staff approval on main ERP).
 */

require_once __DIR__ . '/../includes/cron-auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auto-sync-runner.php';

require_apply_cron_key();

header('Content-Type: application/json; charset=utf-8');

$force  = ($_GET['force'] ?? '') === '1';
$result = run_apply_payroll_sync($pdo, $force);

if (!empty($result['error'])) {
    http_response_code(500);
}

echo json_encode($result, JSON_PRETTY_PRINT);
