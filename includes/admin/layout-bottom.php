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
<script src="<?= h($assetBase) ?>assets/js/admin.js"></script>
<?php
$notifJsPath = dirname(__DIR__, 2) . '/assets/js/notifications.js';
$notifJsVer  = is_file($notifJsPath) ? (string) filemtime($notifJsPath) : '1';
?>
<script src="<?= h($assetBase) ?>assets/js/notifications.js?v=<?= h($notifJsVer) ?>"></script>
<?php if (!empty($enableRichTextEditor)): ?>
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script src="<?= h($assetBase) ?>assets/js/admin-rich-text.js"></script>
<?php endif; ?>
<?php if (!empty($enableAttendanceLive)): ?>
<script src="<?= h($assetBase) ?>assets/js/attendance-live.js"></script>
<?php endif; ?>
<?php if (getApplySiteUrl($pdo ?? null) !== ''): ?>
<script>
(function () {
    var intervalMs = 120000;
    var url = 'apply-sync-ping.php';
    function ping() {
        fetch(url, { credentials: 'same-origin', cache: 'no-store' }).catch(function () {});
    }
    setTimeout(ping, 5000);
    setInterval(ping, intervalMs);
})();
</script>
<?php endif; ?>
</body>
</html>
