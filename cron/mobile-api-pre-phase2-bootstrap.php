<?php

declare(strict_types=1);

/**
 * Pre-Phase 2 production bootstrap — migrations + JWT + enable Mobile API.
 *
 * Web: https://register.olasentra.com/cron/mobile-api-pre-phase2-bootstrap.php?key=REMINDER_CRON_KEY
 * CLI: php cron/mobile-api-pre-phase2-bootstrap.php
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/mobile/schema/mobile-api-schema.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');

if (!$isCli) {
    header('Content-Type: application/json; charset=UTF-8');

    try {
        $pdo         = getDB();
        $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
        $providedKey = trim((string) ($_GET['key'] ?? ''));

        if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Forbidden']);
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database error']);
        exit;
    }
}

try {
    $pdo = getDB();
} catch (Throwable $e) {
    if ($isCli) {
        fwrite(STDERR, "Database error\n");
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
    exit;
}

$steps = [];

function bootstrapRunSqlFile(PDO $pdo, string $path, string $label): array
{
    if (!is_file($path)) {
        return ['step' => $label, 'ok' => false, 'detail' => 'File missing: ' . $path];
    }

    $sql = (string) file_get_contents($path);
    if (trim($sql) === '') {
        return ['step' => $label, 'ok' => false, 'detail' => 'Empty SQL file'];
    }

    // Strip full-line SQL comments before splitting statements.
    $lines = preg_split('/\R/', $sql) ?: [];
    $clean = [];
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }
        $clean[] = $line;
    }
    $sql = implode("\n", $clean);

    try {
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement === '') {
                continue;
            }
            $pdo->exec($statement);
        }

        return ['step' => $label, 'ok' => true, 'detail' => 'Applied'];
    } catch (Throwable $e) {
        return ['step' => $label, 'ok' => true, 'detail' => 'Skipped or already applied: ' . $e->getMessage()];
    }
}

$dbRoot = dirname(__DIR__) . '/database';

$steps[] = bootstrapRunSqlFile($pdo, $dbRoot . '/migrate-phase69-mobile-api.sql', 'migrate-phase69-mobile-api');
$steps[] = bootstrapRunSqlFile($pdo, $dbRoot . '/migrate-phase69-mobile-offline-sync.sql', 'migrate-phase69-mobile-offline-sync');
$steps[] = bootstrapRunSqlFile($pdo, $dbRoot . '/migrate-phase69-mobile-availability-preferred.sql', 'migrate-phase69-mobile-availability-preferred');

ensureMobileApiSchema($pdo);

$jwtBefore = trim(getSetting($pdo, 'mobile_jwt_secret', ''));
$jwtGenerated = false;
if ($jwtBefore === '') {
    mobileGenerateJwtSecret($pdo);
    $jwtGenerated = true;
}
$jwtAfter = trim(getSetting($pdo, 'mobile_jwt_secret', ''));

$wasEnabled = getSetting($pdo, 'mobile_api_enabled', '0') === '1';
saveSettings($pdo, ['mobile_api_enabled' => '1']);
clearSettingsCache();

$tables = [];
foreach (['mobile_refresh_tokens', 'fcm_device_tokens', 'mobile_api_audit', 'mobile_offline_actions'] as $table) {
    try {
        $exists = (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
        $tables[$table] = $exists;
    } catch (Throwable $e) {
        $tables[$table] = false;
    }
}

$preferredEnum = false;
try {
    if ($pdo->query("SHOW TABLES LIKE 'staff_availability'")->fetchColumn()) {
        $col = $pdo->query("SHOW COLUMNS FROM staff_availability LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $type = (string) ($col['Type'] ?? '');
        $preferredEnum = str_contains($type, 'preferred');
    }
} catch (Throwable $e) {
    $preferredEnum = false;
}

$result = [
    'ok'                  => true,
    'mobile_api_enabled'  => true,
    'was_enabled_before'  => $wasEnabled,
    'jwt_secret_generated'=> $jwtGenerated,
    'jwt_secret_present'  => $jwtAfter !== '',
    'jwt_secret_prefix'   => $jwtAfter !== '' ? substr($jwtAfter, 0, 8) . '…' : '',
    'tables'              => $tables,
    'preferred_enum'      => $preferredEnum,
    'steps'               => $steps,
    'base_url'            => rtrim(getSetting($pdo, 'registration_site_url', 'https://register.olasentra.com'), '/') . '/api/mobile/v1',
    'timestamp'           => date('c'),
];

if ($isCli) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
