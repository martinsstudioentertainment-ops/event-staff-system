<?php

declare(strict_types=1);

/** Web path prefix when doc root is the apply folder (apply.olasentra.com/admin/...). */
function apply_admin_base_path(): string
{
    return '/admin';
}

function apply_url(string $relative = ''): string
{
    $base     = rtrim(apply_admin_base_path(), '/');
    $relative = ltrim(str_replace('\\', '/', $relative), '/');

    return $relative === '' ? $base : $base . '/' . $relative;
}

function apply_absolute_url(string $relative = ''): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'apply.olasentra.com';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    return ($https ? 'https' : 'http') . '://' . $host . apply_url($relative);
}

function apply_asset_url(string $storedPath): string
{
    $storedPath = ltrim(str_replace('\\', '/', $storedPath), '/');

    return apply_url($storedPath);
}

function apply_safe_redirect_path(string $path, string $default = 'dashboard.php'): string
{
    $path = trim($path);
    if ($path === '' || str_contains($path, '://') || str_starts_with($path, '//')) {
        return $default;
    }

    if (preg_match('/^[a-zA-Z0-9_./?=&%-]+$/', $path) !== 1) {
        return $default;
    }

    return ltrim($path, '/');
}
