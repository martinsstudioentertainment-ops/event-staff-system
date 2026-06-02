<?php

/** @var string $pageTitle */
/** @var string $activePage */

require_once __DIR__ . '/../settings-repository.php';
require_once __DIR__ . '/../theme.php';
require_once __DIR__ . '/../site-urls.php';
require_once __DIR__ . '/../admin-ui-settings.php';
require_once __DIR__ . '/../system-settings.php';

$pageTitle  = $pageTitle ?? 'Admin';
$activePage = $activePage ?? '';
$adminUser  = getAdminUser();
$assetBase  = '../';
$pdo        = getDB();
$siteName   = getSiteName($pdo);
$themeColor = getThemeColor($pdo);
$layoutTheme = getSystemLayoutTheme($pdo);

$registrationFormUrl = getRegistrationFormUrl($pdo);
$homePageUrl         = normalizePublicSiteUrl(getAppBaseUrl()) . '/home.php';
$enablePwa           = false;

?>
<!DOCTYPE html>
<html lang="<?= h(getAppLocale()) ?>" data-theme="<?= h($layoutTheme) ?>" class="erp-admin">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= h($pageTitle) ?> | Admin · <?= h($siteName) ?></title>
    <?php include __DIR__ . '/../pwa-head.php'; ?>
    <link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/admin.css">
</head>
<body <?= renderAdminUiBodyAttributes($pdo) ?>>
<div class="app-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="main-content">
        <?php include __DIR__ . '/header-bar.php'; ?>
        <main class="page-content">
            <?php
            if (!empty($erpSettingsActive)) {
                require_once __DIR__ . '/admin-nav.php';
                renderErpSettingsLayoutStart((string) $erpSettingsActive);
            } elseif (!empty($adminSectionNav) && is_array($adminSectionNav)) {
                require_once __DIR__ . '/admin-nav.php';
                renderAdminSectionNav($adminSectionNav, (string) ($adminSectionActive ?? ''));
            }
            ?>
