<?php

/** @var string $pageTitle */
/** @var string $activePage */

require_once __DIR__ . '/../settings-repository.php';
require_once __DIR__ . '/../theme.php';
require_once __DIR__ . '/../site-urls.php';
require_once __DIR__ . '/../admin-ui-settings.php';
require_once __DIR__ . '/../system-settings.php';
require_once __DIR__ . '/../app-environment.php';

$pageTitle  = $pageTitle ?? 'Admin';
$activePage = $activePage ?? '';
$adminUser  = getAdminUser();
$assetBase  = '../';
$pdo        = getDB();
$siteName   = getSiteName($pdo);
$themeColor = getThemeColor($pdo);
$layoutTheme = getSystemLayoutTheme($pdo);

$registrationFormUrl = getRegistrationFormUrl($pdo);
$homePageUrl         = getMarketingSiteUrl($pdo ?? null) . '/home.php';
$enablePwa           = true;
$pwaManifest         = 'admin-manifest.php';
$pwaAppTitle         = 'Admin';

?>
<!DOCTYPE html>
<html lang="<?= h(getAppLocale()) ?>" data-theme="<?= h($layoutTheme) ?>" class="erp-admin">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= h($pageTitle) ?> | Admin · <?= h($siteName) ?></title>
    <?php include __DIR__ . '/../pwa-head.php'; ?>
    <link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/admin.css">
    <?php
    $sidebarCssPath = dirname(__DIR__, 2) . '/assets/css/admin-sidebar.css';
    $sidebarCssVer  = is_file($sidebarCssPath) ? (string) filemtime($sidebarCssPath) : '1';
    ?>
    <link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/admin-sidebar.css?v=<?= h($sidebarCssVer) ?>">
    <?php
    $v3CssPath = dirname(__DIR__, 2) . '/assets/css/admin-v3.css';
    $v3CssVer  = is_file($v3CssPath) ? (string) filemtime($v3CssPath) : '1';
    ?>
    <link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/admin-v3.css?v=<?= h($v3CssVer) ?>">
</head>
<body <?= renderAdminUiBodyAttributes($pdo) ?> data-pwa-install="1" data-pwa-context="admin" data-pwa-sw="<?= h($assetBase) ?>sw.js" data-pwa-scope="/" data-session-idle-timeout="<?= (int) (defined('ADMIN_SESSION_IDLE_TTL') ? ADMIN_SESSION_IDLE_TTL : APP_SESSION_IDLE_TTL) ?>" data-session-signout-url="<?= h($assetBase) ?>admin/logout.php?timeout=1">
<div class="app-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="main-content">
        <?php include __DIR__ . '/header-bar.php'; ?>
        <main class="page-content<?= !empty($erpPageContentClass) ? ' ' . h((string) $erpPageContentClass) : '' ?>">
            <?php
            if (!empty($erpSettingsActive)) {
                require_once __DIR__ . '/admin-nav.php';
                renderErpSettingsLayoutStart((string) $erpSettingsActive);
            } elseif (!empty($adminSectionNav) && is_array($adminSectionNav)) {
                require_once __DIR__ . '/admin-nav.php';
                renderAdminSectionNav($adminSectionNav, (string) ($adminSectionActive ?? ''));
            }
            ?>
