<?php

declare(strict_types=1);

/**
 * Lightweight deployment build metadata.
 *
 * @return array{version: string, build: int|string, deployed_at: string|null, git_commit: string, label: string}
 */
function getAppBuildVersion(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $fallback = [
        'version'     => 'unknown',
        'build'       => 'unknown',
        'deployed_at' => null,
        'git_commit'  => '',
        'label'       => 'unknown',
    ];

    $paths = [
        dirname(__DIR__) . '/storage/app/version.json',
        dirname(__DIR__) . '/storage/version.json',
    ];

    foreach ($paths as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            continue;
        }

        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $data = json_decode($raw, true);
        }

        if (!is_array($data)) {
            continue;
        }

        $version = trim((string) ($data['version'] ?? ''));
        $build   = $data['build'] ?? null;
        $deployed = $data['deployed_at'] ?? null;

        $cache = [
            'version'     => $version !== '' ? $version : $fallback['version'],
            'build'       => $build !== null && (string) $build !== '' ? $build : $fallback['build'],
            'deployed_at' => is_string($deployed) && trim($deployed) !== '' ? trim($deployed) : null,
            'git_commit'  => trim((string) ($data['git_commit'] ?? '')),
            'label'       => trim((string) ($data['label'] ?? '')) ?: $fallback['label'],
        ];

        return $cache;
    }

    $cache = $fallback;

    return $cache;
}

/**
 * @return array<string, mixed>
 */
function getAppBuildVersionPublic(): array
{
    $v = getAppBuildVersion();

    return [
        'version'     => $v['version'],
        'build'       => $v['build'],
        'deployed_at' => $v['deployed_at'],
        'git_commit'  => $v['git_commit'] !== '' ? $v['git_commit'] : null,
        'label'       => $v['label'],
    ];
}
