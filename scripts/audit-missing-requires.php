<?php
declare(strict_types=1);

/**
 * Scan PHP for calls to project functions without a direct require of the defining file.
 * Heuristic only — transitive includes are not traced.
 */
$root = dirname(__DIR__);
$fnMap = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fi) {
    if (!$fi->isFile() || $fi->getExtension() !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $fi->getPathname());
    if (str_contains($path, '/vendor/')
        || str_contains($path, '/android/')
        || str_contains($path, '/node_modules/')
        || str_contains($path, '/storage/backups/')
        || str_contains($path, '/storage/reports/')) {
        continue;
    }
    $src = (string) file_get_contents($path);
    if (!preg_match_all('/function\s+([a-zA-Z0-9_]+)\s*\(/', $src, $m)) {
        continue;
    }
    foreach ($m[1] as $fn) {
        $fnMap[$fn][] = $path;
    }
}

$watch = [
    'formatSystemDateTime' => 'includes/system-settings.php',
    'formatSystemDate'     => 'includes/system-settings.php',
    'isGoogleSheetsManualLinkReady' => 'includes/google-sheets-sync.php',
    'getGoogleServiceAccountClientEmail' => 'includes/google-sheets-sync.php',
    'renderRichText'       => 'includes/rich-text.php',
    'renderMessageThread'  => 'includes/components/message-thread.php',
    'getSetting'           => 'includes/settings-repository.php',
    'h'                    => 'includes/helpers.php',
];

$issues = [];

foreach ($iterator as $fi) {
    if (!$fi->isFile() || $fi->getExtension() !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $fi->getPathname());
    if (str_contains($path, '/vendor/')
        || str_contains($path, '/android/')
        || str_contains($path, '/node_modules/')
        || str_contains($path, '/storage/backups/')
        || str_contains($path, '/storage/reports/')) {
        continue;
    }
    $src = (string) file_get_contents($path);
    $reqs = [];
    if (preg_match_all('/require(?:_once)?\s+[^;]+[\'"]([^\'"]+)[\'"]/', $src, $rm)) {
        foreach ($rm[1] as $rel) {
            if (str_starts_with($rel, '/')) {
                continue;
            }
            $resolved = realpath(dirname($path) . '/' . $rel);
            if ($resolved) {
                $reqs[] = str_replace('\\', '/', $resolved);
            }
        }
    }

    foreach ($watch as $fn => $expectedRel) {
        if (!preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/', $src)) {
            continue;
        }
        if (preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(/', $src)) {
            continue;
        }
        $expected = str_replace('\\', '/', realpath($root . '/' . $expectedRel) ?: '');
        $hasDirect = $expected !== '' && in_array($expected, $reqs, true);
        if (!$hasDirect) {
            $issues[] = [
                'file'     => str_replace($root . '/', '', $path),
                'function' => $fn,
                'expected' => $expectedRel,
            ];
        }
    }
}

echo 'Missing direct require scan' . PHP_EOL;
echo str_repeat('=', 50) . PHP_EOL;
echo 'Issues: ' . count($issues) . PHP_EOL . PHP_EOL;

foreach ($issues as $issue) {
    echo $issue['file'] . ' calls ' . $issue['function'] . ' without require of ' . $issue['expected'] . PHP_EOL;
}

exit($issues === [] ? 0 : 1);
