<?php
/** @var string $assetBase Path prefix e.g. '' or '../' */
/** @var bool|null $enablePwaPush Show push subscribe UI (status page) */
/** @var bool|null $enablePwaInstall Show install banner */

$assetBase       = $assetBase ?? '';
$enablePwaPush   = $enablePwaPush ?? false;
$enablePwaInstall = $enablePwaInstall ?? false;
$rootDir         = dirname(__DIR__);
?>
<?php
$appJsPath = $rootDir . '/assets/js/app.js';
$appJsVer  = is_file($appJsPath) ? (string) filemtime($appJsPath) : '1';
$finJsPath = $rootDir . '/assets/js/financial-field-validation.js';
$finJsVer  = is_file($finJsPath) ? (string) filemtime($finJsPath) : '1';
$pwaJsPath = $rootDir . '/assets/js/pwa.js';
$pwaJsVer  = is_file($pwaJsPath) ? (string) filemtime($pwaJsPath) : '1';
$pwaInstallPath = $rootDir . '/assets/js/pwa-install.js';
$pwaInstallVer  = is_file($pwaInstallPath) ? (string) filemtime($pwaInstallPath) : '1';
$pwaAnalyticsPath = $rootDir . '/assets/js/pwa-install-analytics.js';
$pwaAnalyticsVer  = is_file($pwaAnalyticsPath) ? (string) filemtime($pwaAnalyticsPath) : '1';
$pwaPushPath = $rootDir . '/assets/js/pwa-push.js';
$pwaPushVer  = is_file($pwaPushPath) ? (string) filemtime($pwaPushPath) : '1';
$ab = htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8');
?>
<script src="<?= $ab ?>assets/js/financial-field-validation.js?v=<?= htmlspecialchars($finJsVer, ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= $ab ?>assets/js/app.js?v=<?= htmlspecialchars($appJsVer, ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= $ab ?>assets/js/mobile.js"></script>
<script src="<?= $ab ?>assets/js/pwa.js?v=<?= htmlspecialchars($pwaJsVer, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php if ($enablePwaInstall): ?>
<script src="<?= $ab ?>assets/js/pwa-install-analytics.js?v=<?= htmlspecialchars($pwaAnalyticsVer, ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= $ab ?>assets/js/pwa-install.js?v=<?= htmlspecialchars($pwaInstallVer, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>
<?php if ($enablePwaPush): ?>
<script src="<?= $ab ?>assets/js/pwa-push.js?v=<?= htmlspecialchars($pwaPushVer, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>
