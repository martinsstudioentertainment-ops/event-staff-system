<?php

declare(strict_types=1);

/**
 * Background sync for Apply admin (session auth, throttled ~2 min).
 * Called by JS in secure-layout.php while an operator has the console open.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auto-sync-runner.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$force  = ($_GET['force'] ?? '') === '1';
$result = run_apply_payroll_sync($pdo, $force);

if (!empty($result['error'])) {
    http_response_code(500);
}

echo json_encode($result);
