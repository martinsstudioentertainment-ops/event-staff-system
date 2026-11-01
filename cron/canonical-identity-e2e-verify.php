<?php

declare(strict_types=1);

/**
 * End-to-end canonical identity verification (read-only).
 *
 *   /cron/canonical-identity-e2e-verify.php?key=CRON_KEY
 *   /cron/canonical-identity-e2e-verify.php?key=CRON_KEY&record_baseline=1
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/platform/canonical-identity.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }

    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    if (isset($_GET['record_baseline']) && (string) $_GET['record_baseline'] === '1') {
        canonicalIdentityRecordProductionBaseline($pdo);
    }

    $result = canonicalIdentityRunE2eVerification($pdo);
    $pass   = !empty($result['pass']);

    echo json_encode([
        'ok'             => true,
        'pass'           => $pass,
        'version'        => canonicalIdentityVersionInfo($pdo),
        'verification'   => $result,
        'locked_message' => $pass ? 'MASTER STAFF IDENTITY PROTECTION ACTIVE ✅' : null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
