<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/weekly-backup.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('settings');

$file = basename((string) ($_GET['file'] ?? ''));
$allowed = [
    WEEKLY_BACKUP_DB_FILE,
    WEEKLY_BACKUP_SETTINGS_FILE,
    WEEKLY_BACKUP_SITE_ZIP,
];

if (!in_array($file, $allowed, true)) {
    http_response_code(404);
    exit('Not found');
}

$path = getWeeklyBackupDirectory() . '/' . $file;
if (!is_file($path)) {
    setAdminFlash('error', 'Backup file not found. Run weekly backup first.');
    header('Location: backup-center.php');
    exit;
}

$size = (int) filesize($path);
if ($size < 1) {
    setAdminFlash('error', 'Backup file is empty. Run weekly backup again.');
    header('Location: backup-center.php');
    exit;
}

logAdminAudit(getDB(), 'export_backup', 'system', 0, 'weekly:' . $file);

$types = [
    WEEKLY_BACKUP_DB_FILE       => 'application/sql',
    WEEKLY_BACKUP_SETTINGS_FILE => 'application/json',
    WEEKLY_BACKUP_SITE_ZIP      => 'application/zip',
];

while (ob_get_level() > 0) {
    ob_end_clean();
}

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('memory_limit', '256M');
set_time_limit(0);

header('Content-Type: ' . ($types[$file] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . (string) $size);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Accel-Buffering: no');

$handle = fopen($path, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit('Could not open backup file.');
}

try {
    while (!feof($handle)) {
        $chunk = fread($handle, 1024 * 1024);
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        if (function_exists('flush')) {
            flush();
        }
    }
} finally {
    fclose($handle);
}

exit;
