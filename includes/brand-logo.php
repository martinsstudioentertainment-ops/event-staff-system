<?php

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/theme.php';

function getCompanyLogoRelativePath(PDO $pdo): string
{
    return trim(getSetting($pdo, 'company_logo', ''));
}

function getCompanyLogoFilesystemPath(PDO $pdo): string
{
    $relative = getCompanyLogoRelativePath($pdo);
    if ($relative === '') {
        return '';
    }

    $full = dirname(__DIR__) . '/' . str_replace(['\\', '..'], ['/', ''], $relative);
    return is_file($full) ? $full : '';
}

function getCompanyLogoUrl(?PDO $pdo, string $assetBase = ''): string
{
    if ($pdo === null) {
        return '';
    }

    $relative = getCompanyLogoRelativePath($pdo);
    if ($relative === '' || getCompanyLogoFilesystemPath($pdo) === '') {
        return '';
    }

    return $assetBase . $relative;
}

function hasCompanyLogo(?PDO $pdo): bool
{
    return getCompanyLogoUrl($pdo) !== '';
}

/** @return true|string true on success, error message otherwise */
function handleCompanyLogoUpload(PDO $pdo, array $file): bool|string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return true;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return 'Logo upload failed. Please try again.';
    }

    $maxBytes = 2 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        return 'Logo must be 2 MB or smaller.';
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name'] ?? '') ?: '';
    $allowed = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    if (!isset($allowed[$mime])) {
        return 'Logo must be PNG, JPG, WebP, or GIF.';
    }

    $dir = dirname(__DIR__) . '/storage/branding';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return 'Unable to create logo storage folder.';
    }

    deleteCompanyLogoFile($pdo);

    $filename = 'logo-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $target   = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return 'Unable to save uploaded logo.';
    }

    saveSettings($pdo, ['company_logo' => 'storage/branding/' . $filename]);

    return true;
}

function deleteCompanyLogoFile(PDO $pdo): void
{
    $path = getCompanyLogoFilesystemPath($pdo);
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
    saveSettings($pdo, ['company_logo' => '']);
}

/**
 * @param 'header'|'footer'|'hero' $variant
 */
function renderSiteBrandLogo(?PDO $pdo, string $variant = 'header', string $assetBase = '', string $alt = ''): void
{
    $url = getCompanyLogoUrl($pdo, $assetBase);
    $class = 'site-brand-logo site-brand-logo--' . preg_replace('/[^a-z]/', '', $variant);

    if ($url !== '') {
        echo '<img class="' . h($class) . '" src="' . h($url) . '" alt="' . h($alt !== '' ? $alt : 'Company logo') . '" decoding="async">';
        return;
    }

    echo '<span class="' . h($class) . ' site-brand-logo--fallback" aria-hidden="true">';
    echo $pdo ? renderThemeCategoryIcon(getThemeCategory($pdo)) : renderThemeCategoryIcon('security');
    echo '</span>';
}
