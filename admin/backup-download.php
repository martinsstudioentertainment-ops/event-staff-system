<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/weekly-backup.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('backups');

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
    header('Location: backups.php');
    exit;
}

logAdminAudit(getDB(), 'export_backup', 'system', 0, 'weekly:' . $file);

$types = [
    WEEKLY_BACKUP_DB_FILE       => 'application/sql',
    WEEKLY_BACKUP_SETTINGS_FILE => 'application/json',
    WEEKLY_BACKUP_SITE_ZIP      => 'application/zip',
];

header('Content-Type: ' . ($types[$file] ?? 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . (string) filesize($path));
readfile($path);
exit;
