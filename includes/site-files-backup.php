<?php
/**
 * Zip archive of site PHP, assets, and storage (excluding backup folders).
 */

function getProjectRoot(): string
{
    return dirname(__DIR__);
}

/**
 * @return list<string> Paths relative to project root
 */
function getSiteBackupExcludePatterns(): array
{
    return [
        'storage/backups',
        '.git',
        '.vscode',
        'node_modules',
        '.cursor',
    ];
}

function shouldExcludeFromSiteBackup(string $relativePath): bool
{
    $relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));

    foreach (getSiteBackupExcludePatterns() as $pattern) {
        $pattern = str_replace('\\', '/', trim($pattern, '/'));
        if ($relativePath === $pattern || str_starts_with($relativePath, $pattern . '/')) {
            return true;
        }
    }

    return false;
}

/**
 * @return array{success: bool, path: string, message: string, files: int}
 */
function runSiteFilesBackup(?string $targetZip = null): array
{
    if (!class_exists('ZipArchive')) {
        return [
            'success' => false,
            'path'    => '',
            'message' => 'ZipArchive PHP extension is not enabled on this server.',
            'files'   => 0,
        ];
    }

    $weeklyDir = dirname(__DIR__) . '/storage/backups/weekly';
    if (!is_dir($weeklyDir)) {
        mkdir($weeklyDir, 0755, true);
    }

    $targetZip = $targetZip ?? ($weeklyDir . '/site-files.zip');
    $tempZip   = $targetZip . '.tmp';
    $root      = getProjectRoot();

    if (is_file($tempZip)) {
        @unlink($tempZip);
    }

    $zip = new ZipArchive();
    if ($zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return [
            'success' => false,
            'path'    => '',
            'message' => 'Could not create zip archive.',
            'files'   => 0,
        ];
    }

    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo) {
            continue;
        }

        $fullPath = $fileInfo->getPathname();
        $relative = substr($fullPath, strlen($root) + 1);
        $relative = str_replace('\\', '/', $relative);

        if ($relative === '' || shouldExcludeFromSiteBackup($relative)) {
            continue;
        }

        if ($fileInfo->isDir()) {
            continue;
        }

        if ($zip->addFile($fullPath, $relative)) {
            $count++;
        }
    }

    $zip->close();

    if (!is_file($tempZip) || $count < 1) {
        @unlink($tempZip);

        return [
            'success' => false,
            'path'    => '',
            'message' => 'Site zip was empty or could not be written.',
            'files'   => 0,
        ];
    }

    if (is_file($targetZip)) {
        @unlink($targetZip);
    }

    if (!@rename($tempZip, $targetZip)) {
        @unlink($tempZip);

        return [
            'success' => false,
            'path'    => '',
            'message' => 'Could not save site-files.zip.',
            'files'   => 0,
        ];
    }

    return [
        'success' => true,
        'path'    => $targetZip,
        'message' => 'Site files saved (' . formatBackupBytes((int) filesize($targetZip)) . ', ' . $count . ' files).',
        'files'   => $count,
    ];
}
