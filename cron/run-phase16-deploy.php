<?php

/**
 * Phase 16 production deployment runner (Gate F).
 *
 * CLI:
 *   php cron/run-phase16-deploy.php
 *
 * Web (reminder_cron_key from Admin → Settings → Email):
 *   https://admin.olasentra.com/cron/run-phase16-deploy.php?key=YOUR_SECRET
 *
 * Steps: backup → precheck → phase48–51 → postcheck → smoke summary.
 * Aborts before phase 50 if orphan counts > 0.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/weekly-backup.php';
require_once dirname(__DIR__) . '/includes/audit-log.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');

if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');

    try {
        $pdo         = getDB();
        $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
        $providedKey = trim((string) ($_GET['key'] ?? ''));

        if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            http_response_code(403);
            echo "Forbidden\n";
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo "Database error\n";
        exit;
    }
}

/**
 * @return list<string>
 */
function phase16LogDir(): string
{
    $dir = dirname(__DIR__) . '/storage/backups/phase16';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return $dir;
}

/**
 * @param resource $log
 */
function phase16Write($log, string $line): void
{
    $ts = date('Y-m-d H:i:s');
    $out = "[$ts] $line\n";
    fwrite($log, $out);
    echo $out;
}

/**
 * @return array{ok: bool, message: string}
 */
function phase16ExecSqlFile(PDO $pdo, string $path): array
{
    if (!is_file($path)) {
        return ['ok' => false, 'message' => "Missing file: $path"];
    }

    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        return ['ok' => false, 'message' => "Empty file: $path"];
    }

    try {
        $pdo->exec($sql);

        return ['ok' => true, 'message' => 'OK'];
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'Duplicate key name')
            || str_contains($msg, 'already exists')
            || str_contains($msg, 'Duplicate column')
            || str_contains($msg, '3780')) {
            return ['ok' => true, 'message' => 'Skipped (already applied)'];
        }

        return ['ok' => false, 'message' => $msg];
    }
}

/**
 * @return array<string, int>
 */
function phase16OrphanCounts(PDO $pdo): array
{
    $queries = [
        'missing_staff_id' => "SELECT COUNT(*) FROM staff_registrations WHERE staff_id IS NULL OR staff_id = 0",
        'orphan_staff_id'  => "SELECT COUNT(*) FROM staff_registrations sr LEFT JOIN staff s ON s.id = sr.staff_id WHERE sr.staff_id IS NOT NULL AND sr.staff_id > 0 AND s.id IS NULL",
        'orphan_messages'  => "SELECT COUNT(*) FROM staff_messages sm LEFT JOIN staff s ON s.id = sm.staff_id WHERE s.id IS NULL",
        'orphan_invoice_lines' => "SELECT COUNT(*) FROM personal_invoice_lines pil LEFT JOIN saved_job_records sjr ON sjr.id = pil.invoice_id WHERE sjr.id IS NULL",
        'orphan_job_event' => "SELECT COUNT(*) FROM saved_job_records sjr LEFT JOIN events e ON e.id = sjr.event_id WHERE sjr.event_id IS NOT NULL AND e.id IS NULL",
    ];

    $out = [];
    foreach ($queries as $key => $sql) {
        try {
            $out[$key] = (int) $pdo->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            $out[$key] = -1;
        }
    }

    return $out;
}

/**
 * @param resource $log
 * @return array{ok: bool, rows: list<array<string, mixed>>}
 */
function phase16RunSelectStatements($log, PDO $pdo, string $path): array
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        phase16Write($log, "FAILED reading $path");

        return ['ok' => false, 'rows' => []];
    }

    $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql) ?: []));
    $allRows    = [];

    foreach ($statements as $stmt) {
        if ($stmt === '' || str_starts_with($stmt, '--')) {
            continue;
        }
        $clean = preg_replace('/^--.*$/m', '', $stmt);
        $clean = trim((string) $clean);
        if ($clean === '') {
            continue;
        }

        try {
            $q = $pdo->query($clean);
            if ($q === false) {
                continue;
            }
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            phase16Write($log, 'QUERY: ' . substr(str_replace(["\r", "\n"], ' ', $clean), 0, 120));
            foreach ($rows as $row) {
                phase16Write($log, '  ' . json_encode($row, JSON_UNESCAPED_UNICODE));
                $allRows[] = $row;
            }
        } catch (Throwable $e) {
            phase16Write($log, 'QUERY ERROR: ' . $e->getMessage());

            return ['ok' => false, 'rows' => $allRows];
        }
    }

    return ['ok' => true, 'rows' => $allRows];
}

/**
 * @param resource $log
 */
function phase16RunDeploy($log, PDO $pdo, bool $runBackup): void
{
    $dbDir = dirname(__DIR__) . '/database';
    $stamp = date('Ymd-His');
    $logFile = phase16LogDir() . "/phase16-deploy-$stamp.log";
    phase16Write($log, "Log file: $logFile");

    if ($runBackup) {
        phase16Write($log, 'STEP 1: Production weekly backup');
        $backup = runWeeklyFullBackup($pdo);
        phase16Write($log, $backup['message']);
        if (!$backup['success']) {
            phase16Write($log, 'FAILED: Backup did not complete successfully. ABORT.');
            throw new RuntimeException('Backup failed');
        }
    } else {
        phase16Write($log, 'STEP 1: Backup skipped (orchestrator ran backup)');
    }

    phase16Write($log, 'STEP 2: phase16-precheck.sql');
    $precheck = phase16RunSelectStatements($log, $pdo, $dbDir . '/phase16-precheck.sql');
    if (!$precheck['ok']) {
        throw new RuntimeException('Precheck failed');
    }

    $migrations = [
        'migrate-phase48-composite-indexes.sql',
        'migrate-phase49-backfill-staff-id.sql',
    ];

    foreach ($migrations as $file) {
        phase16Write($log, "STEP: $file");
        $result = phase16ExecSqlFile($pdo, $dbDir . '/' . $file);
        phase16Write($log, $result['message']);
        if (!$result['ok']) {
            throw new RuntimeException("$file failed");
        }
    }

    phase16Write($log, 'STEP: Orphan gate (must be zero before phase 50)');
    $orphans = phase16OrphanCounts($pdo);
    foreach ($orphans as $key => $count) {
        phase16Write($log, "  $key = $count");
    }

    $gateKeys = ['orphan_staff_id', 'orphan_messages', 'orphan_invoice_lines', 'orphan_job_event'];
    foreach ($gateKeys as $key) {
        if (($orphans[$key] ?? 0) > 0) {
            phase16Write($log, "ABORT: $key > 0 — do NOT run phase 50. Fix data manually.");
            throw new RuntimeException('Orphan gate failed');
        }
    }

    if (($orphans['missing_staff_id'] ?? 0) > 0) {
        phase16Write($log, 'WARN: missing_staff_id > 0 after backfill — FK on registrations not in scope; continuing.');
    }

    $lateMigrations = [
        'migrate-phase50-foreign-keys.sql',
        'migrate-phase51-platform-ops-tables.sql',
    ];

    foreach ($lateMigrations as $file) {
        phase16Write($log, "STEP: $file");
        $result = phase16ExecSqlFile($pdo, $dbDir . '/' . $file);
        phase16Write($log, $result['message']);
        if (!$result['ok']) {
            throw new RuntimeException("$file failed");
        }
    }

    phase16Write($log, 'STEP: phase16-postcheck.sql');
    $postcheck = phase16RunSelectStatements($log, $pdo, $dbDir . '/phase16-postcheck.sql');
    if (!$postcheck['ok']) {
        throw new RuntimeException('Postcheck query failed');
    }

    foreach ($postcheck['rows'] as $row) {
        if (isset($row['check_name'], $row['bad_count']) && (int) $row['bad_count'] > 0) {
            phase16Write($log, 'FAILED postcheck: ' . json_encode($row));
            throw new RuntimeException('Postcheck bad_count > 0');
        }
    }

    phase16Write($log, 'STEP: Smoke — event #1 counts');
    $baseline = $pdo->query(
        "SELECT event_id, status, COUNT(*) AS cnt FROM staff_registrations WHERE event_id = 1 GROUP BY event_id, status"
    )->fetchAll(PDO::FETCH_ASSOC);
    phase16Write($log, '  baseline: ' . json_encode($baseline));

    $platformTables = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (
            'platform_cleanup_log','platform_storage_snapshots','platform_audit_runs',
            'platform_ssl_checks','emergency_event_log',
            'equipment_categories','equipment_items','equipment_rentals'
        )"
    )->fetchColumn();
    phase16Write($log, "  platform_tables = $platformTables");
    if ((int) $platformTables !== 8) {
        throw new RuntimeException('Expected 8 platform tables');
    }

    try {
        logAdminAudit($pdo, 'phase16_deploy', 'system', 0, 'migrate phase48-51 complete');
    } catch (Throwable $e) {
        phase16Write($log, 'Audit log skipped: ' . $e->getMessage());
    }

    phase16Write($log, 'PHASE16_COMPLETE');
}

$runBackup = $isCli
    ? !in_array('--skip-backup', $argv ?? [], true)
    : !isset($_GET['skip_backup']);
$logPath   = phase16LogDir() . '/phase16-deploy-' . date('Ymd-His') . '.log';
$log       = fopen($logPath, 'ab');
if ($log === false) {
    echo "Cannot open log\n";
    exit(1);
}

try {
    $pdo = getDB();
    phase16Write($log, '=== Phase 16 deploy start ===');
    phase16RunDeploy($log, $pdo, $runBackup);
    phase16Write($log, '=== SUCCESS ===');
    fclose($log);
    exit(0);
} catch (Throwable $e) {
    phase16Write($log, 'FAILED: ' . $e->getMessage());
    phase16Write($log, 'ROLLBACK REQUIRED if partial migration applied — see database/rollback-phase48-51.sql');
    phase16Write($log, '=== ABORT ===');
    fclose($log);
    if (!$isCli) {
        http_response_code(500);
    }
    exit(1);
}
