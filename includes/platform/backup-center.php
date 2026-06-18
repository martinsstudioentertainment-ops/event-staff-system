<?php

declare(strict_types=1);

require_once __DIR__ . '/../settings-repository.php';
require_once __DIR__ . '/../weekly-backup.php';
require_once __DIR__ . '/../database-backup.php';
require_once __DIR__ . '/../production-readiness.php';

/** @return array<string, mixed> */
function getBackupCenterSnapshot(PDO $pdo): array
{
    $weeklyFiles = listWeeklyBackupFiles();
    $totalBytes  = 0;
    $allExist    = true;

    foreach ($weeklyFiles as $file) {
        if (!$file['exists']) {
            $allExist = false;
            continue;
        }
        $totalBytes += (int) $file['size'];
    }

    $lastAt = getLastWeeklyBackupAt($pdo);
    $daysSince = null;
    if ($lastAt !== null && $lastAt !== '') {
        $ts = strtotime($lastAt);
        if ($ts !== false) {
            $daysSince = (int) floor((time() - $ts) / 86400);
        }
    }

    $checks = getProductionReadinessChecks($pdo);
    $pass   = countReadinessStatus($checks, 'pass');
    $total  = max(1, count($checks));
    $score  = (int) round(($pass / $total) * 100);

    return [
        'last_backup_at'    => $lastAt,
        'days_since_backup' => $daysSince,
        'total_bytes'       => $totalBytes,
        'files'             => $weeklyFiles,
        'all_files_exist'   => $allExist,
        'auto_backup_on'    => isAutoBackupEnabled($pdo),
        'restore_ready'     => $allExist && $daysSince !== null && $daysSince <= 14,
        'readiness_score'   => $score,
        'readiness_checks'  => $checks,
    ];
}

/** @return list<string> */
function getDisasterRecoveryPlaybookSteps(): array
{
    return [
        '1. Confirm incident scope — note affected portals (admin, apply, register, staff).',
        '2. Put registration in maintenance mode if data integrity is uncertain (Settings → System).',
        '3. Download latest weekly backup from Backup Center or storage/backups/weekly/.',
        '4. Restore database.sql via phpMyAdmin or mysql CLI to a staging DB first.',
        '5. Verify restored data: staff count, pending queue, event dates.',
        '6. Restore settings-and-cms.json via Settings import if configuration was lost.',
        '7. Re-deploy application files from git main if code was corrupted.',
        '8. Do NOT overwrite production config.php or Google service account JSON.',
        '9. Reconnect Google OAuth in Settings if tokens were revoked.',
        '10. Run system-health.php and platform smoke scripts before reopening registration.',
        '11. Notify team via Apply admin and staff messaging when service is restored.',
    ];
}
