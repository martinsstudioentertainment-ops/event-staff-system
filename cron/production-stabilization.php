<?php

declare(strict_types=1);

/**
 * Production stabilization — verification, housekeeping, commission rebuild.
 *
 * Read-only full report:
 *   /cron/production-stabilization.php?key=...
 *
 * Apply housekeeping + commission rebuild:
 *   /cron/production-stabilization.php?key=...&apply=1
 *
 * Single phase:
 *   /cron/production-stabilization.php?key=...&phase=verify|housekeeping|commission|thomas_park|all&apply=1
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $apply = isset($_GET['apply']) && (string) $_GET['apply'] === '1';
    $phase = strtolower(trim((string) ($_GET['phase'] ?? 'all')));

    $payload = ['ok' => true, 'applied' => $apply, 'phase' => $phase, 'generated_at' => gmdate('c')];

    switch ($phase) {
        case 'verify':
        case 'integrity':
            $payload['result'] = runProductionIntegrityVerification($pdo);
            break;

        case 'housekeeping':
            $payload['result'] = runProductionHousekeeping($pdo, $apply);
            break;

        case 'commission':
            $payload['result'] = rebuildAllCommissionInvoices($pdo, $apply);
            break;

        case 'thomas_park':
            $payload['result'] = verifyThomasParkEvent($pdo);
            break;

        case 'google':
            $payload['result'] = auditGoogleSheetsSynchronization($pdo);
            break;

        case 'mobile':
            $payload['result'] = auditMobileApplication($pdo);
            break;

        case 'quality':
            $payload['result'] = auditStaffDataQuality($pdo);
            break;

        case 'health':
            $payload['result'] = getProductionHealthSnapshot($pdo);
            break;

        case 'performance':
            $payload['result'] = runProductionPerformanceChecks($pdo);
            break;

        case 'security':
            $payload['result'] = runProductionSecurityVerification($pdo);
            break;

        case 'all':
        default:
            $payload = runFullProductionStabilization($pdo, $apply);
            break;
    }

    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
