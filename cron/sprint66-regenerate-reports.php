<?php

declare(strict_types=1);

/**
 * Sprint 6.6 — regenerate HTML reports on server (read-only audit writes to docs/ only).
 *
 * Web: /cron/sprint66-regenerate-reports.php?key=REMINDER_CRON_KEY
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/settings-repository.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');

if (!$isCli) {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $pdo         = getDB();
        $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
        $providedKey = trim((string) ($_GET['key'] ?? ''));

        if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_THROW_ON_ERROR);
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database error'], JSON_THROW_ON_ERROR);
        exit;
    }
}

$script = dirname(__DIR__) . '/scripts/generate-sprint66-reports.php';
if (!is_file($script)) {
    $payload = ['ok' => false, 'error' => 'Missing scripts/generate-sprint66-reports.php on server'];
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit(1);
}

$cmd  = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1';
$out  = [];
$code = 0;
exec($cmd, $out, $code);

$reportFiles = [
    'DATA-INTEGRITY-AUDIT-REPORT.html',
    'TEST-DATA-INVENTORY-REPORT.html',
    'PHONE-DUPLICATE-REPORT.html',
    'PSA-INTEGRITY-REPORT.html',
    'IMPORT-STABILIZATION-REPORT.html',
    'VAULT-HEALTH-REPORT.html',
    'TRUST-SCORE-DATA-QUALITY-REPORT.html',
    'PRODUCTION-CLEANUP-PLAN.html',
];

$docsDir = dirname(__DIR__) . '/docs';
$written = [];
foreach ($reportFiles as $file) {
    $path = $docsDir . '/' . $file;
    $written[] = [
        'file'   => $file,
        'exists' => is_file($path),
        'bytes'  => is_file($path) ? (int) filesize($path) : 0,
    ];
}

require_once __DIR__ . '/../includes/platform/apply-vault-bridge.php';

$payload = [
    'ok'           => $code === 0,
    'exit_code'    => $code,
    'generated_at' => gmdate('c'),
    'cli_output'   => implode("\n", $out),
    'reports'      => $written,
    'vault_bridge' => getApplyVaultBridgeStatus(),
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
if ($isCli) {
    echo "\n";
}
