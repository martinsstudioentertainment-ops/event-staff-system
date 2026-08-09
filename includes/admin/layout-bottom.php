        </main>
        <?php
        if (!empty($erpSettingsActive)) {
            require_once __DIR__ . '/admin-nav.php';
            renderErpSettingsLayoutEnd();
        }
        ?>
        <footer class="footer footer--admin">
            <p>&copy; <?= date('Y') ?> <?= h($siteName) ?></p>
        </footer>
    </div>
</div>
<?php
$finJsPath = dirname(__DIR__, 2) . '/assets/js/financial-field-validation.js';
$finJsVer  = is_file($finJsPath) ? (string) filemtime($finJsPath) : '1';
?>
<script src="<?= h($assetBase) ?>assets/js/financial-field-validation.js?v=<?= h($finJsVer) ?>"></script>
<script src="<?= h($assetBase) ?>assets/js/mobile.js"></script>
<script src="<?= h($assetBase) ?>assets/js/admin-pwa.js"></script>
<?php $pwaInstallVer = is_file(dirname(__DIR__, 2) . '/assets/js/pwa-install.js') ? (string) filemtime(dirname(__DIR__, 2) . '/assets/js/pwa-install.js') : '1'; ?>
<script src="<?= h($assetBase) ?>assets/js/pwa-install.js?v=<?= h($pwaInstallVer) ?>"></script>
<?php
$sidebarJsPath = dirname(__DIR__, 2) . '/assets/js/admin-sidebar.js';
$sidebarJsVer  = is_file($sidebarJsPath) ? (string) filemtime($sidebarJsPath) : '1';
?>
<script src="<?= h($assetBase) ?>assets/js/admin-sidebar.js?v=<?= h($sidebarJsVer) ?>"></script>
<?php
$adminJsPath = dirname(__DIR__, 2) . '/assets/js/admin.js';
$adminJsVer  = is_file($adminJsPath) ? (string) filemtime($adminJsPath) : '1';
?>
<script src="<?= h($assetBase) ?>assets/js/admin.js?v=<?= h($adminJsVer) ?>"></script>
<?php
$notifJsPath = dirname(__DIR__, 2) . '/assets/js/notifications.js';
$notifJsVer  = is_file($notifJsPath) ? (string) filemtime($notifJsPath) : '1';
?>
<script src="<?= h($assetBase) ?>assets/js/notifications.js?v=<?= h($notifJsVer) ?>"></script>
<?php if (!empty($enableRichTextEditor)): ?>
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<?php
$richTextJsPath = dirname(__DIR__, 2) . '/assets/js/admin-rich-text.js';
$richTextJsVer  = is_file($richTextJsPath) ? (string) filemtime($richTextJsPath) : '1';
?>
<script src="<?= h($assetBase) ?>assets/js/admin-rich-text.js?v=<?= h($richTextJsVer) ?>"></script>
<?php endif; ?>
<?php if (!empty($enableAttendanceLive)): ?>
<?php
$attendanceLiveJsPath = dirname(__DIR__, 2) . '/assets/js/attendance-live.js';
$attendanceLiveJsVer  = is_file($attendanceLiveJsPath) ? (string) filemtime($attendanceLiveJsPath) : '1';
?>
<script src="<?= h($assetBase) ?>assets/js/attendance-live.js?v=<?= h($attendanceLiveJsVer) ?>"></script>
<?php endif; ?>
<?php
$applySyncPages = ['dashboard', 'staff', 'staff-directory', 'apply', 'go-live', 'ops'];
$runApplySyncPing = getApplySiteUrl($pdo ?? null) !== ''
    && isset($activePage)
    && in_array((string) $activePage, $applySyncPages, true);
?>
<?php if ($runApplySyncPing): ?>
<script>
(function () {
    var intervalMs = 300000;
    var url = 'apply-sync-ping.php';
    function ping() {
        fetch(url, { credentials: 'same-origin', cache: 'no-store' }).catch(function () {});
    }
    setTimeout(ping, 15000);
    setInterval(ping, intervalMs);
})();
</script>
<?php endif; ?>
<?php
require_once dirname(__DIR__) . '/google-sheets-auto-worker.php';
if (isset($pdo) && $pdo instanceof PDO) {
    googleSheetsScheduleAutoWorker($pdo);
}
?>
<script>
(function () {
    var url = 'sheets-queue-ping.php';
    function ping() {
        fetch(url, { credentials: 'same-origin', cache: 'no-store' }).catch(function () {});
    }
    setTimeout(ping, 8000);
    setInterval(ping, 90000);
})();
</script>
<?php
$idleJsPath = dirname(__DIR__, 2) . '/assets/js/session-idle-timeout.js';
$idleJsVer  = is_file($idleJsPath) ? (string) filemtime($idleJsPath) : '1';
?>
<script src="<?= h($assetBase) ?>assets/js/session-idle-timeout.js?v=<?= h($idleJsVer) ?>"></script>
</body>
</html>
