<?php
/**
 * Weekly full backup — one DB dump, one settings pack, one site zip (overwrite each week).
 */

require_once __DIR__ . '/database-backup.php';
require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/website-content.php';

const WEEKLY_BACKUP_DB_FILE      = 'database.sql';
const WEEKLY_BACKUP_SETTINGS_FILE = 'settings-and-cms.json';
const WEEKLY_BACKUP_SITE_ZIP     = 'site-files.zip';

function getWeeklyBackupDirectory(): string
{
    $dir = dirname(__DIR__) . '/storage/backups/weekly';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return $dir;
}

/**
 * @return array<int, array{key: string, label: string, filename: string, path: string, size: int, modified: int, exists: bool}>
 */
function listWeeklyBackupFiles(): array
{
    $dir = getWeeklyBackupDirectory();
    $items = [
        ['key' => 'database', 'label' => 'Database (MySQL)', 'filename' => WEEKLY_BACKUP_DB_FILE],
        ['key' => 'settings', 'label' => 'Settings & CMS JSON', 'filename' => WEEKLY_BACKUP_SETTINGS_FILE],
        ['key' => 'site', 'label' => 'Site files (ZIP)', 'filename' => WEEKLY_BACKUP_SITE_ZIP],
    ];

    $out = [];
    foreach ($items as $item) {
        $path = $dir . '/' . $item['filename'];
        $out[] = array_merge($item, [
            'path'     => $path,
            'size'     => is_file($path) ? (int) filesize($path) : 0,
            'modified' => is_file($path) ? (int) filemtime($path) : 0,
            'exists'   => is_file($path),
        ]);
    }

    return $out;
}

function getLastWeeklyBackupAt(?PDO $pdo = null): ?string
{
    $pdo = $pdo ?? getDB();
    $val = trim(getSetting($pdo, 'last_weekly_backup_at', ''));

    if ($val !== '') {
        return $val;
    }

    return getLastDatabaseBackupAt($pdo);
}

function markWeeklyBackupCompleted(PDO $pdo, array $summary): void
{
    setSetting($pdo, 'last_weekly_backup_at', date('c'));
    setSetting($pdo, 'last_weekly_backup_summary', json_encode($summary, JSON_UNESCAPED_UNICODE));

    if (!empty($summary['database']['path'])) {
        markDatabaseBackupCompleted($pdo, (string) $summary['database']['path']);
    }

    setSetting($pdo, 'last_auto_backup_at', date('c'));
}

/**
 * Remove old timestamped backups so only the weekly overwrite copies remain.
 */
function cleanupLegacyTimestampedBackups(): void
{
    foreach (glob(dirname(__DIR__) . '/storage/backups/database/db-*.sql') ?: [] as $path) {
        @unlink($path);
    }

    foreach (glob(dirname(__DIR__) . '/storage/backups/settings/settings-*.json') ?: [] as $path) {
        @unlink($path);
    }
}

/**
 * @return array{
 *     success: bool,
 *     database: array{success: bool, path: string, message: string, method: string},
 *     settings: array{success: bool, path: string, message: string},
 *     site: array{success: bool, path: string, message: string, files: int},
 *     message: string
 * }
 */
function runWeeklyFullBackup(?PDO $pdo = null): array
{
    $pdo = $pdo ?? getDB();
    require_once __DIR__ . '/site-files-backup.php';

    $dir          = getWeeklyBackupDirectory();
    $dbPath       = $dir . '/' . WEEKLY_BACKUP_DB_FILE;
    $settingsPath = $dir . '/' . WEEKLY_BACKUP_SETTINGS_FILE;
    $sitePath     = $dir . '/' . WEEKLY_BACKUP_SITE_ZIP;

    $dbResult = runDatabaseBackup($pdo, $dbPath);

    $settingsResult = runSettingsCmsBackup($pdo, $settingsPath);
    $siteResult     = runSiteFilesBackup($sitePath);

    cleanupLegacyTimestampedBackups();

    $success = $dbResult['success'] && $settingsResult['success'] && $siteResult['success'];

    $summary = [
        'database' => $dbResult,
        'settings' => $settingsResult,
        'site'     => $siteResult,
    ];

    if ($dbResult['success'] || $settingsResult['success'] || $siteResult['success']) {
        markWeeklyBackupCompleted($pdo, $summary);
    }

    $parts = [];
    if ($dbResult['success']) {
        $parts[] = 'database';
    }
    if ($settingsResult['success']) {
        $parts[] = 'settings';
    }
    if ($siteResult['success']) {
        $parts[] = 'site files';
    }

    $message = $success
        ? 'Weekly backup complete (' . implode(', ', $parts) . ') — previous files overwritten.'
        : 'Weekly backup finished with errors: '
            . (!$dbResult['success'] ? 'DB: ' . $dbResult['message'] . ' ' : '')
            . (!$settingsResult['success'] ? 'Settings: ' . $settingsResult['message'] . ' ' : '')
            . (!$siteResult['success'] ? 'Site: ' . $siteResult['message'] : '');

    return array_merge($summary, [
        'success' => $success,
        'message' => trim($message),
    ]);
}

/**
 * @return array{success: bool, path: string, message: string}
 */
function runSettingsCmsBackup(PDO $pdo, string $targetFile): array
{
    $payload = [
        'exported_at'      => date('c'),
        'app'              => 'event-staff-system',
        'database'         => DB_NAME,
        'settings'         => getAllSettings($pdo),
        'website_content'  => getWebsiteContent($pdo),
        'events_count'     => (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn(),
        'staff_count'      => (int) $pdo->query('SELECT COUNT(*) FROM staff_registrations')->fetchColumn(),
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return [
            'success' => false,
            'path'    => '',
            'message' => 'Could not encode settings JSON.',
        ];
    }

    $temp = $targetFile . '.tmp';
    if (file_put_contents($temp, $json) === false) {
        return [
            'success' => false,
            'path'    => '',
            'message' => 'Could not write settings backup.',
        ];
    }

    if (is_file($targetFile)) {
        @unlink($targetFile);
    }

    if (!@rename($temp, $targetFile)) {
        @unlink($temp);

        return [
            'success' => false,
            'path'    => '',
            'message' => 'Could not save settings backup.',
        ];
    }

    return [
        'success' => true,
        'path'    => $targetFile,
        'message' => 'Settings & CMS saved (' . formatBackupBytes((int) filesize($targetFile)) . ').',
    ];
}
