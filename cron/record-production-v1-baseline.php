<?php

declare(strict_types=1);

/**
 * Record Olasentra ERP v1.0 production baseline on server.
 *
 * - Writes certification settings
 * - Runs full weekly backup (DB + settings/CMS + site files)
 * - Copies artifacts to storage/backups/baseline/OLASENTRA_ERP_PRODUCTION_V1.0_BASELINE/
 *
 *   ?key=CRON_KEY
 *   ?key=CRON_KEY&dry_run=1
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/weekly-backup.php';

header('Content-Type: application/json; charset=UTF-8');

const V1_BASELINE_LABEL = 'OLASENTRA_ERP_PRODUCTION_V1.0_BASELINE';
const V1_BUILD = 2026062800;

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $dryRun = isset($_GET['dry_run']) && (string) $_GET['dry_run'] === '1';
    $manifestPath = dirname(__DIR__) . '/storage/baseline/OLASENTRA_ERP_PRODUCTION_V1.0_BASELINE.json';
    $manifest = is_file($manifestPath)
        ? json_decode((string) file_get_contents($manifestPath), true)
        : null;

    $baselineDir = dirname(__DIR__) . '/storage/backups/baseline/' . V1_BASELINE_LABEL;
    $weeklyDir   = getWeeklyBackupDirectory();

    $result = [
        'ok'        => true,
        'dry_run'   => $dryRun,
        'label'     => V1_BASELINE_LABEL,
        'build'     => V1_BUILD,
        'version'   => '1.0.0',
        'certified' => 'CERTIFIED FOR LIVE OPERATIONS',
    ];

    if (!$dryRun) {
        $backup = runWeeklyFullBackup($pdo);
        $result['weekly_backup'] = $backup;

        if (!is_dir($baselineDir)) {
            mkdir($baselineDir, 0755, true);
        }

        $copied = [];
        foreach ([WEEKLY_BACKUP_DB_FILE, WEEKLY_BACKUP_SETTINGS_FILE, WEEKLY_BACKUP_SITE_ZIP] as $file) {
            $src = $weeklyDir . '/' . $file;
            $dst = $baselineDir . '/' . $file;
            if (is_file($src)) {
                copy($src, $dst);
                $copied[$file] = [
                    'path' => $dst,
                    'size' => (int) filesize($dst),
                ];
            }
        }

        if (is_file($manifestPath)) {
            copy($manifestPath, $baselineDir . '/OLASENTRA_ERP_PRODUCTION_V1.0_BASELINE.json');
            $copied['manifest'] = $baselineDir . '/OLASENTRA_ERP_PRODUCTION_V1.0_BASELINE.json';
        }

        setSetting($pdo, 'production_baseline_v1_label', V1_BASELINE_LABEL);
        setSetting($pdo, 'production_baseline_v1_build', (string) V1_BUILD);
        setSetting($pdo, 'production_baseline_v1_certified_at', gmdate('c'));
        setSetting($pdo, 'production_baseline_v1_status', 'CERTIFIED FOR LIVE OPERATIONS');
        if (is_array($manifest)) {
            setSetting($pdo, 'production_baseline_v1_manifest', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $result['baseline_dir'] = $baselineDir;
        $result['copied']       = $copied;
    } else {
        $result['would_backup_to'] = $baselineDir;
        $result['weekly_dir']      = $weeklyDir;
        $result['weekly_files']    = listWeeklyBackupFiles();
    }

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
