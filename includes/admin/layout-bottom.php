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
<?php if (!empty($enableRichTextEditor)): ?>
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script src="<?= h($assetBase) ?>assets/js/admin-rich-text.js"></script>
<?php endif; ?>
<?php if (!empty($enableAttendanceLive)): ?>
<script src="<?= h($assetBase) ?>assets/js/attendance-live.js"></script>
<?php endif; ?>
</body>
</html>
