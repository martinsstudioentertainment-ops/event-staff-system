<?php

declare(strict_types=1);

/**
 * Read-only Sprint 6.6 production audit — JSON output only.
 * Uses live MySQL when reachable; does not write reports or mutate data.
 *
 * Usage:
 *   php scripts/run-sprint66-production-audit.php --main-config=PATH [--apply-config=PATH] [--host=HOST]
 */
$root = dirname(__DIR__);
$opts = getopt('', ['main-config:', 'apply-config::', 'host::', 'json::']);

$mainConfig = (string) ($opts['main-config'] ?? '');
$applyConfig = (string) ($opts['apply-config'] ?? '');
$forcedHost = trim((string) ($opts['host'] ?? ''));

if ($mainConfig === '' || !is_file($mainConfig)) {
    fwrite(STDERR, "Missing --main-config=path/to/config.php\n");
    exit(1);
}

function loadConfigConstants(string $path): array
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Cannot read config: ' . $path);
    }
    $map = [];
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
        if (preg_match("/define\\('\\Q{$key}\\E',\\s*'([^']*)'\\)/", $content, $m)) {
            $map[$key] = $m[1];
        } elseif ($key === 'DB_HOST' && preg_match('/\\$host\\s*=\\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            $map[$key] = $m[1];
        } elseif ($key === 'DB_NAME' && preg_match('/\\$db\\s*=\\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            $map[$key] = $m[1];
        } elseif ($key === 'DB_USER' && preg_match('/\\$user\\s*=\\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            $map[$key] = $m[1];
        } elseif ($key === 'DB_PASS' && preg_match('/\\$pass\\s*=\\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            $map[$key] = $m[1];
        }
    }
    if ($key = 'DB_NAME') {
        // apply database.php uses $db not DB_NAME
    }
    if (!isset($map['DB_NAME']) && preg_match('/\\$db\\s*=\\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
        $map['DB_NAME'] = $m[1];
    }
    if (!isset($map['DB_USER']) && preg_match('/\\$user\\s*=\\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
        $map['DB_USER'] = $m[1];
    }
    if (!isset($map['DB_PASS']) && preg_match('/\\$pass\\s*=\\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
        $map['DB_PASS'] = $m[1];
    }
    if (!isset($map['DB_HOST']) && preg_match('/\\$host\\s*=\\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
        $map['DB_HOST'] = $m[1];
    }

    return $map;
}

function connectMysql(array $cfg, string $host): ?PDO
{
    $dsn = 'mysql:host=' . $host . ';dbname=' . ($cfg['DB_NAME'] ?? '') . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, (string) ($cfg['DB_USER'] ?? ''), (string) ($cfg['DB_PASS'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 8,
        ]);
        $pdo->query('SELECT 1');

        return $pdo;
    } catch (Throwable $e) {
        return null;
    }
}

function tryConnect(array $cfg, string $forcedHost, array $extraHosts): array
{
    $hosts = array_values(array_unique(array_filter([
        $forcedHost,
        $cfg['DB_HOST'] ?? '',
        ...$extraHosts,
    ])));

    $errors = [];
    foreach ($hosts as $host) {
        $pdo = connectMysql($cfg, $host);
        if ($pdo instanceof PDO) {
            return ['pdo' => $pdo, 'host' => $host, 'errors' => $errors];
        }
        $errors[] = $host . ': unreachable';
    }

    return ['pdo' => null, 'host' => null, 'errors' => $errors];
}

$mainCfg = loadConfigConstants($mainConfig);
$applyCfg = $applyConfig !== '' && is_file($applyConfig) ? loadConfigConstants($applyConfig) : [];

$extraHosts = ['ftp.olasentra.com', '162.254.39.125', 'olasentra.com', 'localhost'];
$mainConn = tryConnect($mainCfg, $forcedHost, $extraHosts);
$pdo = $mainConn['pdo'];
$applyPdo = null;
$applyHost = null;

if ($applyCfg !== []) {
    $applyConn = tryConnect($applyCfg, $forcedHost, $extraHosts);
    $applyPdo = $applyConn['pdo'];
    $applyHost = $applyConn['host'];
}

if (!$pdo instanceof PDO) {
    echo json_encode([
        'ok'     => false,
        'error'  => 'Cannot connect to main production database from this machine (MySQL not reachable remotely).',
        'tried'  => $mainConn['errors'],
        'hint'   => 'Run on server: php scripts/generate-sprint66-reports.php OR Admin → Data integrity → Regenerate reports',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(2);
}

// Bootstrap app for data-integrity module (read-only queries).
if (!defined('DB_HOST')) {
    define('DB_HOST', $mainConn['host']);
    define('DB_NAME', $mainCfg['DB_NAME']);
    define('DB_USER', $mainCfg['DB_USER']);
    define('DB_PASS', $mainCfg['DB_PASS']);
}

require_once $root . '/includes/platform/data-integrity.php';

ensureDataIntegritySchema($pdo);

$audit = runFullDataIntegrityAudit($pdo, $applyPdo);
$testData = detectTestAccounts($pdo, $applyPdo);
$vaultHealth = computeVaultHealthScore($applyPdo);
$integrityScore = computeDataIntegrityScore($audit, $vaultHealth);
$mergeRecs = buildMergeRecommendations($pdo, $applyPdo);
$trustQ = auditTrustScoreDataQuality($pdo);
$plan = buildProductionCleanupPlan($audit, $testData, $mergeRecs);

$summary = [
    'ok'                  => true,
    'generated_at_utc'    => gmdate('c'),
    'source'              => 'live_production_mysql',
    'main_db_host'        => $mainConn['host'],
    'main_db_name'        => $mainCfg['DB_NAME'],
    'apply_db_connected'  => $applyPdo instanceof PDO,
    'apply_db_host'       => $applyHost,
    'integrity_score'     => $integrityScore,
    'vault_health'        => $vaultHealth,
    'counts'              => [
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
    'trust_quality'       => $trustQ,
    'orphaned'            => $audit['orphaned'] ?? [],
    'inactive'            => $audit['inactive'] ?? [],
    'duplicate_phones_main'  => array_slice($audit['duplicate_phones_main'] ?? [], 0, 15),
    'duplicate_phones_vault' => array_slice($audit['duplicate_phones_vault'] ?? [], 0, 15),
    'duplicate_psa_vault'    => array_slice($audit['duplicate_psa_vault'] ?? [], 0, 15),
    'duplicate_profiles'     => array_slice($audit['duplicate_staff_profiles'] ?? [], 0, 10),
    'test_accounts_sample'   => array_slice($testData['accounts'] ?? [], 0, 20),
    'merge_recommendations_sample' => array_slice($mergeRecs, 0, 15),
    'cleanup_plan_phases'    => array_map(static fn ($p) => [
        'title' => $p['title'] ?? '',
        'item_count' => count($p['items'] ?? []),
        'items' => array_slice($p['items'] ?? [], 0, 10),
    ], $plan),
    'import_stabilization' => [
        'precheck_module' => is_file($root . '/apply/admin/includes/import-precheck.php'),
        'staff_import'    => is_file($root . '/apply/admin/includes/staff-import.php'),
    ],
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
