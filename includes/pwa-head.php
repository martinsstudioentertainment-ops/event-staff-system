<?php
/** @var string $assetBase Path prefix e.g. '' or '../' */
/** @var PDO|null $pdo */
/** @var string $themeColor */
/** @var bool|null $enablePwa Load manifest / install meta */
/** @var string|null $pwaManifest Manifest filename relative to site root */

require_once __DIR__ . '/theme.php';
require_once __DIR__ . '/site-urls.php';
require_once __DIR__ . '/settings-repository.php';

$assetBase   = $assetBase ?? '';
$pdo         = $pdo ?? (function_exists('getDB') ? getDB() : null);
$themeColor  = $themeColor ?? ($pdo ? getThemeColor($pdo) : '#2563eb');
$fontUrl     = $pdo ? getThemeFontUrl($pdo) : 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap';
$enablePwa   = $enablePwa ?? !isAdminRequest();
$pwaManifest = $pwaManifest ?? 'manifest.php';
$pwaHeadMinimal = !empty($pwaHeadMinimal);
$pwaShort    = $pwaAppTitle ?? ($pdo ? getSiteName($pdo) : 'Event Staff');
if (mb_strlen($pwaShort) > 12) {
    $pwaShort = mb_substr($pwaShort, 0, 12);
}

?>
<?php if ($enablePwa): ?>
<link rel="manifest" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') . htmlspecialchars($pwaManifest, ENT_QUOTES, 'UTF-8') ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($pwaShort, ENT_QUOTES, 'UTF-8') ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>api/pwa-icon.php?size=180">
<link rel="apple-touch-icon" sizes="192x192" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>api/pwa-icon.php?size=192">
<?php endif; ?>
<meta name="theme-color" content="<?= htmlspecialchars($themeColor, ENT_QUOTES, 'UTF-8') ?>">
<?php if ($fontUrl !== ''): ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="<?= htmlspecialchars($fontUrl, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
<?php endif; ?>
<?php if (!$pwaHeadMinimal): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>assets/css/variables.css">
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>assets/theme.css.php">
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>assets/css/style.css">
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>assets/css/public-front.css">
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>assets/css/mobile.css">
<?php endif; ?>
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>assets/css/pwa-install.css">
