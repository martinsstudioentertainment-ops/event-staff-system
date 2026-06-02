<?php
/**
 * Legacy entry point — runs the weekly full backup when auto backup is enabled.
 * Prefer scheduling cron/weekly-backup.php once per week.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/system-settings.php';

try {
    $pdo = getDB();
} catch (Throwable $e) {
    fwrite(STDERR, "Database unavailable.\n");
    exit(1);
}

if (!isAutoBackupEnabled($pdo)) {
    echo "Auto backup disabled.\n";
    exit(0);
}

require __DIR__ . '/weekly-backup.php';
