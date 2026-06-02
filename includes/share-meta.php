<?php

require_once __DIR__ . '/brand-logo.php';
require_once __DIR__ . '/site-urls.php';
require_once __DIR__ . '/rich-text.php';

function getAbsolutePublicUrl(string $path, ?PDO $pdo = null): string
{
    $base = normalizePublicSiteUrl(getRegistrationSiteUrl($pdo));
    $path = '/' . ltrim($path, '/');

    return $base . $path;
}

function getShareImageRelativePath(?PDO $pdo): string
{
    if ($pdo === null) {
        return 'assets/img/share-default.svg';
    }

    $relative = getCompanyLogoRelativePath($pdo);

    if ($relative !== '' && getCompanyLogoFilesystemPath($pdo) !== '') {
        return ltrim(str_replace('\\', '/', $relative), '/');
    }

    return 'assets/img/share-default.svg';
}

function getShareImageAbsoluteUrl(?PDO $pdo): string
{
    if ($pdo === null) {
        return '';
    }

    $base = normalizePublicSiteUrl(getRegistrationSiteUrl($pdo));

    return $base . '/' . getShareImageRelativePath($pdo);
}

/**
 * Same-origin URL for admin previews (avoids wrong host when registration URL differs).
 */
function getShareImagePreviewUrl(?PDO $pdo, string $assetBase = ''): string
{
    $path = getShareImageRelativePath($pdo);

    return $assetBase . $path;
}

/**
 * @param array{
 *     title?: string,
 *     description?: string,
 *     url?: string,
 *     image?: string,
 *     type?: string,
 *     site_name?: string
 * } $options
 */
function renderShareMeta(array $options, ?PDO $pdo = null): void
{
    $title       = trim((string) ($options['title'] ?? ''));
    $description = trim((string) ($options['description'] ?? ''));
    $url         = trim((string) ($options['url'] ?? ''));
    $image       = trim((string) ($options['image'] ?? ''));
    $type        = trim((string) ($options['type'] ?? 'website'));
    $siteName    = trim((string) ($options['site_name'] ?? ''));

    if ($description !== '') {
        $description = plainTextFromRich($description, 300);
    }

    if ($url === '' && !empty($_SERVER['REQUEST_URI'])) {
        $url = getAbsolutePublicUrl((string) $_SERVER['REQUEST_URI'], $pdo);
    }

    if ($image === '') {
        $image = getShareImageAbsoluteUrl($pdo);
    } elseif (!preg_match('#^https?://#i', $image)) {
        $image = getAbsolutePublicUrl(ltrim($image, '/'), $pdo);
    }

    if ($title === '') {
        $title = $siteName !== '' ? $siteName : 'Event Staff';
    }

    ?>
    <meta property="og:type" content="<?= h($type) ?>">
    <meta property="og:title" content="<?= h($title) ?>">
    <?php if ($description !== ''): ?>
    <meta property="og:description" content="<?= h($description) ?>">
    <meta name="description" content="<?= h($description) ?>">
    <?php endif; ?>
    <?php if ($url !== ''): ?>
    <meta property="og:url" content="<?= h($url) ?>">
    <link rel="canonical" href="<?= h($url) ?>">
    <?php endif; ?>
    <?php if ($image !== ''): ?>
    <meta property="og:image" content="<?= h($image) ?>">
    <meta property="og:image:alt" content="<?= h($siteName !== '' ? $siteName . ' logo' : 'Event Staff') ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="<?= h($image) ?>">
    <?php else: ?>
    <meta name="twitter:card" content="summary">
    <?php endif; ?>
    <?php if ($siteName !== ''): ?>
    <meta property="og:site_name" content="<?= h($siteName) ?>">
    <?php endif; ?>
    <?php if ($description !== ''): ?>
    <meta name="twitter:description" content="<?= h($description) ?>">
    <?php endif; ?>
    <meta name="twitter:title" content="<?= h($title) ?>">
    <?php
}
