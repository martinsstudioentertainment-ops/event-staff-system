<?php
/** @var string $assetBase Path prefix e.g. '' or '../' */
/** @var bool|null $enablePwaPush Show push subscribe UI (status page) */
/** @var bool|null $enablePwaInstall Show install banner */

$assetBase       = $assetBase ?? '';
$enablePwaPush   = $enablePwaPush ?? false;
$enablePwaInstall = $enablePwaInstall ?? false;
?>
<script src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>assets/js/app.js"></script>
<script src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>assets/js/mobile.js"></script>
<script src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>assets/js/pwa.js"></script>
<?php if ($enablePwaInstall): ?>
<script src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>assets/js/pwa-install.js"></script>
<?php endif; ?>
<?php if ($enablePwaPush): ?>
<script src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>assets/js/pwa-push.js"></script>
<?php endif; ?>
