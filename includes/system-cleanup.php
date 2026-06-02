<?php
/**
 * Safe server cleanup — logs, temp files, optional Google test sheet purge.
 */

require_once __DIR__ . '/settings-repository.php';

/**
 * @return list<array{path: string, label: string, bytes: int, writable: bool}>
 */
function getStorageUsageReport(?string $root = null): array
{
    $root  = $root ?? dirname(__DIR__);
    $items = [];

    $scanDirs = [
        'storage/logs'            => 'Log files',
        'storage/backups'         => 'Database backups',
        'storage/google'          => 'Google credentials',
        'storage/branding'        => 'Branding uploads',
        'vendor'                  => 'PHP libraries (vendor)',
        'assets'                  => 'CSS / JS assets',
    ];

    foreach ($scanDirs as $rel => $label) {
        $path = $root . '/' . $rel;
        if (!is_dir($path) && !is_file($path)) {
            continue;
        }
        $items[] = [
            'path'     => $rel,
            'label'    => $label,
            'bytes'    => directorySizeBytes($path),
            'writable' => is_writable($path),
        ];
    }

    usort($items, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

    return $items;
}

function directorySizeBytes(string $path): int
{
    if (is_file($path)) {
        return (int) filesize($path);
    }

    if (!is_dir($path)) {
        return 0;
    }

    $total = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $total += (int) $file->getSize();
        }
    }

    return $total;
}

function formatBytesHuman(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return round($bytes / 1048576, 2) . ' MB';
}

/**
 * @return list<array{file: string, bytes: int}>
 */
function listLargeLogFiles(?string $root = null, int $minBytes = 524288): array
{
    $root = $root ?? dirname(__DIR__);
    $dir  = $root . '/storage/logs';
    $out  = [];

    if (!is_dir($dir)) {
        return $out;
    }

    foreach (glob($dir . '/*.log') ?: [] as $file) {
        if (!is_file($file)) {
            continue;
        }
        $size = (int) filesize($file);
        if ($size >= $minBytes) {
            $out[] = ['file' => basename($file), 'bytes' => $size];
        }
    }

    usort($out, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

    return $out;
}

/**
 * Truncate or remove log files (does not touch credentials or SQL backups).
 *
 * @return array{cleared: list<string>, freed_bytes: int, errors: list<string>}
 */
function clearApplicationLogs(?string $root = null, bool $deleteEmptyOnly = false): array
{
    $root    = $root ?? dirname(__DIR__);
    $dir     = $root . '/storage/logs';
    $cleared = [];
    $freed   = 0;
    $errors  = [];

    if (!is_dir($dir)) {
        return ['cleared' => [], 'freed_bytes' => 0, 'errors' => ['storage/logs folder missing']];
    }

    foreach (glob($dir . '/*') ?: [] as $path) {
        $base = basename($path);
        if ($base === '.gitkeep') {
            continue;
        }
        if (!is_file($path)) {
            continue;
        }
        if (!str_ends_with(strtolower($base), '.log')) {
            continue;
        }

        $size = (int) filesize($path);
        if ($deleteEmptyOnly && $size > 0) {
            continue;
        }

        if (@file_put_contents($path, '') !== false) {
            $cleared[] = $base;
            $freed    += $size;
            continue;
        }

        if (@unlink($path)) {
            $cleared[] = $base . ' (deleted)';
            $freed    += $size;
            continue;
        }

        $errors[] = 'Could not clear ' . $base;
    }

    return ['cleared' => $cleared, 'freed_bytes' => $freed, 'errors' => $errors];
}

/**
 * @return array{ok: bool, message: string, details: array<string, mixed>}
 */
function runSystemCleanup(PDO $pdo, array $options = []): array
{
    $clearLogs   = !empty($options['clear_logs']);
    $purgeSheets = !empty($options['purge_google_test_sheets']);
    $details     = [];
    $messages    = [];

    if ($clearLogs) {
        $logResult = clearApplicationLogs();
        $details['logs'] = $logResult;
        if ($logResult['cleared'] !== []) {
            $messages[] = 'Cleared logs: ' . implode(', ', $logResult['cleared'])
                . ' (' . formatBytesHuman($logResult['freed_bytes']) . ' freed).';
        } else {
            $messages[] = 'No log files needed clearing.';
        }
        if ($logResult['errors'] !== []) {
            $messages[] = implode('; ', $logResult['errors']);
        }
    }

    if ($purgeSheets) {
        require_once __DIR__ . '/google-sheets-sync.php';
        $sa = loadGoogleServiceAccount();
        if ($sa === null) {
            $messages[] = 'Google service account not configured — skipped sheet purge.';
        } else {
            $purge = googleDrivePurgeTestSpreadsheets($sa);
            $details['google_sheets'] = $purge;
            $messages[] = $purge['message'];
        }
    }

    $details['storage_after'] = getStorageUsageReport();

    return [
        'ok'      => true,
        'message' => $messages !== [] ? implode(' ', $messages) : 'Nothing selected to clean.',
        'details' => $details,
    ];
}
