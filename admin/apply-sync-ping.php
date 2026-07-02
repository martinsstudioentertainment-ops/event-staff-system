<?php

declare(strict_types=1);

/**
 * Main ERP admin background ping → apply vault + Google Sheets sync.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/apply-remote-sync.php';

requireAdmin();

if (!adminCan('apply')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pdo    = getDB();
$force  = ($_GET['force'] ?? '') === '1';
$url    = getApplyPortalCronSyncUrl($pdo, $force);

if ($url === '') {
    echo json_encode(['ok' => false, 'error' => 'Apply site URL is not configured.']);
    exit;
}

triggerApplyPortalSyncAsync($pdo, $force);

echo json_encode([
    'ok'      => true,
    'queued'  => true,
    'force'   => $force,
    'apply'   => rtrim(getApplySiteUrl($pdo), '/'),
]);
