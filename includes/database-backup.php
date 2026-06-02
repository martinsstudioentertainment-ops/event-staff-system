<?php
/**
 * Full MySQL database backup (mysqldump with PDO fallback).
 */

function getDatabaseBackupDirectory(): string
{
    $dir = dirname(__DIR__) . '/storage/backups/database';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return $dir;
}

/**
 * @return list<string>
 */
function findMysqldumpBinaries(): array
{
    $candidates = ['mysqldump'];

    if (DIRECTORY_SEPARATOR === '\\') {
        $laragonRoot = getenv('LARAGON_ROOT') ?: 'C:\\laragon';
        $candidates[] = $laragonRoot . '\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysqldump.exe';
        $candidates[] = $laragonRoot . '\\bin\\mysql\\mysql-8.0.30-winx64\\bin\\mysqldump.exe';
        $candidates[] = 'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysqldump.exe';
        $candidates[] = 'C:\\laragon\\bin\\mysql\\mysql-8.0.30-winx64\\bin\\mysqldump.exe';
    }

    $found = [];
    foreach ($candidates as $bin) {
        if ($bin === 'mysqldump') {
            $found[] = $bin;
            continue;
        }
        if (is_file($bin)) {
            $found[] = $bin;
        }
    }

    return array_values(array_unique($found));
}

/**
 * @return array{success: bool, path: string, message: string, method: string}
 */
function runDatabaseBackup(?PDO $pdo = null, ?string $targetFile = null): array
{
    $pdo = $pdo ?? getDB();
    $dir = getDatabaseBackupDirectory();

    if ($targetFile !== null) {
        $file = $targetFile;
        if (is_file($file)) {
            @unlink($file);
        }
    } else {
        $stamp = date('Ymd-His');
        $file  = $dir . '/db-' . DB_NAME . '-' . $stamp . '.sql';
    }

    foreach (findMysqldumpBinaries() as $bin) {
        $result = runMysqldumpBackup($bin, $file);
        if ($result['success']) {
            if ($targetFile === null) {
                pruneOldDatabaseBackups($dir, 14);
            }

            return $result;
        }
    }

    $fallback = runPdoDatabaseBackup($pdo, $file);
    if ($fallback['success'] && $targetFile === null) {
        pruneOldDatabaseBackups($dir, 14);
    }

    return $fallback;
}

/**
 * @return array{success: bool, path: string, message: string, method: string}
 */
function runMysqldumpBackup(string $binary, string $file): array
{
    $host = DB_HOST;
    $user = DB_USER;
    $pass = DB_PASS;
    $name = DB_NAME;

    $cmd = escapeshellarg($binary)
        . ' --host=' . escapeshellarg($host)
        . ' --user=' . escapeshellarg($user)
        . ' --default-character-set=utf8mb4'
        . ' --single-transaction'
        . ' --routines'
        . ' --triggers'
        . ' ' . escapeshellarg($name);

    if ($pass !== '') {
        $cmd .= ' --password=' . escapeshellarg($pass);
    }

    $cmd .= ' > ' . escapeshellarg($file) . ' 2>&1';

    if (DIRECTORY_SEPARATOR === '\\') {
        $cmd = 'cmd /C ' . $cmd;
    }

    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('exec', $disabled, true) && in_array('shell_exec', $disabled, true) && in_array('proc_open', $disabled, true)) {
        return [
            'success' => false,
            'path'    => '',
            'message' => 'Shell functions disabled — using PDO fallback.',
            'method'  => 'mysqldump',
        ];
    }

    try {
        exec($cmd, $output, $code);
    } catch (Throwable $e) {
        return [
            'success' => false,
            'path'    => '',
            'message' => $e->getMessage(),
            'method'  => 'mysqldump',
        ];
    }

    if ($code === 0 && is_file($file) && filesize($file) > 64) {
        return [
            'success' => true,
            'path'    => $file,
            'message' => 'Database backup saved (' . formatBackupBytes((int) filesize($file)) . ').',
            'method'  => 'mysqldump',
        ];
    }

    if (is_file($file)) {
        @unlink($file);
    }

    return [
        'success' => false,
        'path'    => '',
        'message' => trim(implode("\n", $output)) ?: 'mysqldump failed.',
        'method'  => 'mysqldump',
    ];
}

/**
 * @return array{success: bool, path: string, message: string, method: string}
 */
function runPdoDatabaseBackup(PDO $pdo, string $file): array
{
    try {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($tables === []) {
            return [
                'success' => false,
                'path'    => '',
                'message' => 'No tables found to export.',
                'method'  => 'pdo',
            ];
        }

        $sql  = "-- Event Staff System PDO backup\n";
        $sql .= '-- Generated: ' . date('c') . "\n\n";
        $sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            $table = (string) $table;
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_ASSOC);
            if (!$create) {
                continue;
            }

            $createSql = $create['Create Table'] ?? $create['Create View'] ?? '';
            if ($createSql === '') {
                continue;
            }

            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n{$createSql};\n\n";

            $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`')->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                continue;
            }

            $columns = array_keys($rows[0]);
            $colList = implode(', ', array_map(static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`', $columns));

            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $column) {
                    $value = $row[$column];
                    $values[] = $value === null ? 'NULL' : $pdo->quote((string) $value);
                }
                $sql .= 'INSERT INTO `' . $table . '` (' . $colList . ') VALUES (' . implode(', ', $values) . ");\n";
            }
            $sql .= "\n";
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        if (file_put_contents($file, $sql) === false) {
            return [
                'success' => false,
                'path'    => '',
                'message' => 'Could not write backup file.',
                'method'  => 'pdo',
            ];
        }

        return [
            'success' => true,
            'path'    => $file,
            'message' => 'Database backup saved via PDO (' . formatBackupBytes((int) filesize($file)) . ').',
            'method'  => 'pdo',
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'path'    => '',
            'message' => $e->getMessage(),
            'method'  => 'pdo',
        ];
    }
}

function pruneOldDatabaseBackups(string $dir, int $keepDays = 14): void
{
    $cutoff = time() - ($keepDays * 86400);
    foreach (glob($dir . '/db-*.sql') ?: [] as $path) {
        if (is_file($path) && filemtime($path) < $cutoff) {
            @unlink($path);
        }
    }
}

function formatBackupBytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }

    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return $bytes . ' B';
}

/**
 * @return array<int, array{filename: string, path: string, size: int, modified: int}>
 */
function listDatabaseBackups(int $limit = 20): array
{
    $weeklyDir = dirname(__DIR__) . '/storage/backups/weekly';
    $items     = [
        ['filename' => 'database.sql', 'label' => 'Database (MySQL)'],
        ['filename' => 'settings-and-cms.json', 'label' => 'Settings & CMS JSON'],
        ['filename' => 'site-files.zip', 'label' => 'Site files (ZIP)'],
    ];

    $out = [];
    foreach ($items as $item) {
        $path = $weeklyDir . '/' . $item['filename'];
        if (!is_file($path)) {
            continue;
        }
        $out[] = [
            'filename' => $item['filename'],
            'path'     => $path,
            'size'     => (int) filesize($path),
            'modified' => (int) filemtime($path),
            'label'    => $item['label'],
        ];
    }

    if ($out !== []) {
        return array_slice($out, 0, max(1, $limit));
    }

    $dir   = getDatabaseBackupDirectory();
    $files = glob($dir . '/db-*.sql') ?: [];
    rsort($files);

    foreach (array_slice($files, 0, max(1, $limit)) as $path) {
        if (!is_file($path)) {
            continue;
        }
        $out[] = [
            'filename' => basename($path),
            'path'     => $path,
            'size'     => (int) filesize($path),
            'modified' => (int) filemtime($path),
            'label'    => 'Database (legacy)',
        ];
    }

    return $out;
}

function getLastDatabaseBackupAt(?PDO $pdo = null): ?string
{
    require_once __DIR__ . '/settings-repository.php';

    $pdo = $pdo ?? getDB();
    $val = trim(getSetting($pdo, 'last_database_backup_at', ''));

    return $val !== '' ? $val : null;
}

function markDatabaseBackupCompleted(?PDO $pdo = null, string $path = ''): void
{
    require_once __DIR__ . '/settings-repository.php';

    $pdo = $pdo ?? getDB();
    setSetting($pdo, 'last_database_backup_at', date('c'));
    if ($path !== '') {
        setSetting($pdo, 'last_database_backup_file', basename($path));
    }
}
