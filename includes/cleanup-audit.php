<?php

declare(strict_types=1);

/**
 * Read-only cleanup audit — report first, never auto-delete.
 * Reports written to storage/reports/cleanup-{timestamp}.json
 */

require_once __DIR__ . '/system-cleanup.php';

/** @return list<string> */
function getCleanupAuditProtectedPaths(): array
{
    return [
        'config.php',
        'apply/admin/config/database.php',
        'apply/admin/config/eventstaff-database.php',
        'storage/google/service-account.json',
        'vendor',
        'database',
        '.git',
    ];
}

/** @return array<string, mixed> */
function runCleanupAudit(?string $root = null): array
{
    $root = $root ?? dirname(__DIR__);
    $ts   = gmdate('Y-m-d\TH:i:s\Z');

    $report = [
        'generated_at' => $ts,
        'root'         => $root,
        'mode'         => 'read_only',
        'summary'      => [
            'issues_critical' => 0,
            'issues_high'     => 0,
            'issues_medium'   => 0,
            'issues_low'      => 0,
            'total_findings'  => 0,
        ],
        'storage'        => getStorageUsageReport($root),
        'large_files'    => findCleanupLargeFiles($root),
        'old_backups'    => findCleanupOldBackups($root),
        'duplicate_assets' => findCleanupDuplicateAssets($root),
        'empty_directories' => findCleanupEmptyDirectories($root),
        'log_files'      => listLargeLogFiles($root, 102400),
        'findings'       => [],
    ];

    foreach ($report['large_files'] as $file) {
        $report['findings'][] = [
            'severity' => 'medium',
            'category' => 'large_file',
            'path'     => $file['path'],
            'bytes'    => $file['bytes'],
            'message'  => 'File exceeds 5 MB outside vendor/',
        ];
    }

    foreach ($report['old_backups'] as $backup) {
        $report['findings'][] = [
            'severity' => 'low',
            'category' => 'old_backup',
            'path'     => $backup['path'],
            'age_days' => $backup['age_days'],
            'bytes'    => $backup['bytes'],
            'message'  => 'Backup older than 90 days — review before manual delete',
        ];
    }

    foreach ($report['duplicate_assets'] as $dup) {
        $report['findings'][] = [
            'severity' => 'medium',
            'category' => 'duplicate_asset',
            'hash'     => $dup['hash'],
            'paths'    => $dup['paths'],
            'bytes'    => $dup['bytes'],
            'message'  => 'Duplicate file content in assets/',
        ];
    }

    foreach ($report['empty_directories'] as $dir) {
        $report['findings'][] = [
            'severity' => 'low',
            'category' => 'empty_directory',
            'path'     => $dir,
            'message'  => 'Empty directory — safe to review for removal',
        ];
    }

    foreach ($report['log_files'] as $log) {
        $severity = $log['bytes'] >= 5242880 ? 'high' : 'medium';
        $report['findings'][] = [
            'severity' => $severity,
            'category' => 'large_log',
            'path'     => 'storage/logs/' . $log['file'],
            'bytes'    => $log['bytes'],
            'message'  => 'Large log file — truncate via System cleanup (manual)',
        ];
    }

    $suspectPhp = findCleanupUnreferencedRootPhp($root);
    foreach ($suspectPhp as $item) {
        $report['findings'][] = [
            'severity' => 'low',
            'category' => 'unreferenced_php',
            'path'     => $item,
            'message'  => 'Root PHP not in known route map — verify before delete',
        ];
    }

    foreach ($report['findings'] as $finding) {
        ++$report['summary']['total_findings'];
        $sev = (string) ($finding['severity'] ?? 'low');
        $key = 'issues_' . $sev;
        if (isset($report['summary'][$key])) {
            ++$report['summary'][$key];
        }
    }

    $report['rollback_note'] = 'Restore from storage/backups/pre-deploy-*.zip or admin → Backups. Never auto-delete.';

    return $report;
}

/** @return list<array{path: string, bytes: int}> */
function findCleanupLargeFiles(string $root, int $minBytes = 5242880): array
{
    $out          = [];
    $protected    = getCleanupAuditProtectedPaths();
    $scanPrefixes = ['assets', 'uploads', 'docs', 'scripts', 'cron'];
    $maxFiles     = 8000;
    $scanned      = 0;

    foreach ($scanPrefixes as $prefix) {
        $base = $root . '/' . $prefix;
        if (!is_dir($base)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (++$scanned > $maxFiles) {
                break 2;
            }
            if (!$file->isFile()) {
                continue;
            }
            $full = $file->getPathname();
            $rel  = str_replace('\\', '/', substr($full, strlen($root) + 1));
            $skip = false;
            foreach ($protected as $p) {
                if (str_starts_with($rel, $p)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            $size = (int) $file->getSize();
            if ($size >= $minBytes) {
                $out[] = ['path' => $rel, 'bytes' => $size];
            }
        }
    }

    usort($out, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

    return $out;
}

/** @return list<array{path: string, bytes: int, age_days: int}> */
function findCleanupOldBackups(string $root, int $maxAgeDays = 90): array
{
    $dir = $root . '/storage/backups';
    $out = [];

    if (!is_dir($dir)) {
        return $out;
    }

    $cutoff = time() - ($maxAgeDays * 86400);

    foreach (array_slice(glob($dir . '/*') ?: [], 0, 200) as $path) {
        if (!is_file($path)) {
            continue;
        }
        $mtime = (int) filemtime($path);
        if ($mtime >= $cutoff) {
            continue;
        }
        $out[] = [
            'path'     => 'storage/backups/' . basename($path),
            'bytes'    => (int) filesize($path),
            'age_days' => (int) floor((time() - $mtime) / 86400),
        ];
    }

    usort($out, static fn (array $a, array $b): int => $b['age_days'] <=> $a['age_days']);

    return $out;
}

/** @return list<array{hash: string, paths: list<string>, bytes: int}> */
function findCleanupDuplicateAssets(string $root): array
{
    $assetRoot = $root . '/assets';
    $hashes    = [];

    if (!is_dir($assetRoot)) {
        return [];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($assetRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['css', 'js'], true)) {
            continue;
        }
        $full = $file->getPathname();
        $rel  = str_replace('\\', '/', substr($full, strlen($root) + 1));
        $hash = @md5_file($full);
        if ($hash === false) {
            continue;
        }
        if (!isset($hashes[$hash])) {
            $hashes[$hash] = ['paths' => [], 'bytes' => (int) $file->getSize()];
        }
        $hashes[$hash]['paths'][] = $rel;
    }

    $dupes = [];
    foreach ($hashes as $hash => $meta) {
        if (count($meta['paths']) < 2) {
            continue;
        }
        $dupes[] = [
            'hash'  => $hash,
            'paths' => $meta['paths'],
            'bytes' => $meta['bytes'],
        ];
    }

    return $dupes;
}

/** @return list<string> */
function findCleanupEmptyDirectories(string $root, int $maxDepth = 4): array
{
    $out     = [];
    $skip    = ['vendor', 'node_modules', '.git'];
    $scan    = ['uploads', 'scripts', 'cron', 'docs'];

    foreach ($scan as $prefix) {
        $base = $root . '/' . $prefix;
        if (!is_dir($base)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $iterator->setMaxDepth($maxDepth);
        $checked = 0;

        foreach ($iterator as $item) {
            if (++$checked > 500) {
                break;
            }
            if (!$item->isDir()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            foreach ($skip as $s) {
                if (str_contains($rel, $s)) {
                    continue 2;
                }
            }
            if (str_contains($rel, 'storage/backups')) {
                continue;
            }
            if (isDirectoryEffectivelyEmpty($item->getPathname())) {
                $out[] = $rel;
            }
        }
    }

    return array_values(array_unique($out));
}

function isDirectoryEffectivelyEmpty(string $path): bool
{
    if (!is_dir($path)) {
        return false;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if ($entry === '.gitkeep') {
            continue;
        }

        return false;
    }

    return true;
}

/** @return list<string> */
function findCleanupUnreferencedRootPhp(string $root): array
{
    $known = [
        'index.php', 'home.php', 'submit.php', 'status.php', 'check-in.php',
        'staff-app.php', 'staff-portal.php', 'staff-profile.php', 'staff-messages.php',
        'staff-notifications.php', 'staff-portal.php', 'privacy.php', 'faq.php',
        'contact.php', 'how-it-works.php', 'roles.php', 'events-page.php',
        'admin-manifest.php', 'sw.js',
    ];

    $suspect = [];
    foreach (glob($root . '/*.php') ?: [] as $file) {
        $base = basename($file);
        if (in_array($base, $known, true)) {
            continue;
        }
        if (str_starts_with($base, 'test-') || str_starts_with($base, 'diag-')) {
            $suspect[] = $base;
        }
    }

    return $suspect;
}

/** @param array<string, mixed> $report */
function writeCleanupAuditReport(array $report, ?string $root = null): string
{
    $root    = $root ?? dirname(__DIR__);
    $dir     = $root . '/storage/reports';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $stamp    = gmdate('Ymd-His');
    $filename = 'cleanup-' . $stamp . '.json';
    $path     = $dir . '/' . $filename;
    $json     = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Could not encode cleanup report JSON.');
    }

    file_put_contents($path, $json);
    file_put_contents($dir . '/cleanup-latest.json', $json);

    return $path;
}

function getLatestCleanupAuditReportPath(?string $root = null): ?string
{
    $root = $root ?? dirname(__DIR__);
    $path = $root . '/storage/reports/cleanup-latest.json';

    return is_file($path) ? $path : null;
}

/** @return array<string, mixed>|null */
function readLatestCleanupAuditReport(?string $root = null): ?array
{
    $path = getLatestCleanupAuditReportPath($root);
    if ($path === null) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}
