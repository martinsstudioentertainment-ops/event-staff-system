<?php

declare(strict_types=1);

/**
 * Sprint 6.6 — read-only production audit (JSON). No writes, merges, or deletes.
 *
 * Web: /cron/sprint66-production-audit.php?key=REMINDER_CRON_KEY
 * CLI: php cron/sprint66-production-audit.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/platform/data-integrity.php';

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

$pdo      = getDB();
$applyPdo = getApplyVaultPdo();
ensureDataIntegritySchema($pdo);

$audit          = runFullDataIntegrityAudit($pdo, $applyPdo);
$testData       = detectTestAccounts($pdo, $applyPdo);
$vaultHealth    = computeVaultHealthScore($applyPdo);
$integrityScore = computeDataIntegrityScore($audit, $vaultHealth);
$mergeRecs      = buildMergeRecommendations($pdo, $applyPdo);
$trustQ         = auditTrustScoreDataQuality($pdo);
$plan           = buildProductionCleanupPlan($audit, $testData, $mergeRecs);

$vaultBridge = getApplyVaultBridgeStatus();

$payload = [
    'ok'               => true,
    'generated_at_utc' => gmdate('c'),
    'source'           => 'live_production_server',
    'vault_bridge'     => $vaultBridge,
    'integrity_score'  => $integrityScore,
    'vault_health'     => $vaultHealth,
    'counts'           => [
        'duplicate_phones_main'    => count($audit['duplicate_phones_main'] ?? []),
        'duplicate_phones_vault'   => count($audit['duplicate_phones_vault'] ?? []),
        'duplicate_psa_vault'      => count($audit['duplicate_psa_vault'] ?? []),
        'duplicate_staff_profiles' => count($audit['duplicate_staff_profiles'] ?? []),
        'duplicate_emails_main'    => count($audit['duplicate_emails_main'] ?? []),
        'orphan_types'             => count($audit['orphaned'] ?? []),
        'inactive_types'           => count($audit['inactive'] ?? []),
        'test_accounts'            => count($testData['accounts'] ?? []),
        'merge_recommendations'    => count($mergeRecs),
        'blank_phones_vault'       => (int) ($audit['blank_phones_vault'] ?? 0),
        'invalid_phones_vault'     => (int) ($audit['invalid_phones_vault'] ?? 0),
    ],
    'trust_quality'                => $trustQ,
    'orphaned'                     => $audit['orphaned'] ?? [],
    'inactive'                     => $audit['inactive'] ?? [],
    'duplicate_phones_main'        => array_slice($audit['duplicate_phones_main'] ?? [], 0, 20),
    'duplicate_phones_vault'       => array_slice($audit['duplicate_phones_vault'] ?? [], 0, 20),
    'duplicate_psa_vault'          => array_slice($audit['duplicate_psa_vault'] ?? [], 0, 20),
    'duplicate_staff_profiles'     => array_slice($audit['duplicate_staff_profiles'] ?? [], 0, 15),
    'duplicate_emails_main'        => array_slice($audit['duplicate_emails_main'] ?? [], 0, 10),
    'test_accounts'                => $testData['accounts'] ?? [],
    'merge_recommendations'        => array_slice($mergeRecs, 0, 20),
    'cleanup_plan'                 => $plan,
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
if ($isCli) {
    echo "\n";
}
