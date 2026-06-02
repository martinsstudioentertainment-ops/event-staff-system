<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/system-cleanup.php';
require_once __DIR__ . '/../includes/google-sheets-sync.php';

requireAdminCapability('settings');

$pdo   = getDB();
$flash = '';
$flashType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? null)) {
    $result = runSystemCleanup($pdo, [
        'clear_logs'                  => !empty($_POST['clear_logs']),
        'purge_google_test_sheets'    => !empty($_POST['purge_google_test_sheets']),
    ]);
    $flash     = $result['message'];
    $flashType = $result['ok'] ? 'success' : 'error';
}

$storageReport = getStorageUsageReport();
$largeLogs     = listLargeLogFiles();
$totalLogBytes = 0;
foreach (glob(dirname(__DIR__) . '/storage/logs/*.log') ?: [] as $f) {
    if (is_file($f)) {
        $totalLogBytes += (int) filesize($f);
    }
}

$pageTitle  = 'System cleanup';
$activePage = 'go-live';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Speed up the site — safe cleanup</h2>
            <p class="card__subtitle">
                Clears heavy <strong>log files</strong> and optional Google test sheets. Does not delete
                <code>config.php</code>, database backups, or your Gmail-connected sheets.
            </p>
        </div>
        <a href="go-live.php" class="btn btn--secondary">← Go live</a>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert alert--<?= h($flashType) ?> alert--visible"><?= h($flash) ?></div>
    <?php endif; ?>

    <div class="alert alert--info alert--visible" style="margin-bottom:1rem">
        <strong>Why it feels slow</strong>
        <ul style="margin:0.5rem 0 0 1.25rem">
            <li>Large <code>storage/logs/*.log</code> files (every error appends to them)</li>
            <li>Shared hosting + many small PHP files in <code>vendor/</code> (normal, ~2 MB)</li>
            <li>Git deploy copying files several times (we reduced this — deploy latest code)</li>
            <li>Not usually “too many user files” — staff data is in MySQL, not big folders</li>
        </ul>
    </div>

    <h3 class="form-section-title">Disk usage (this app)</h3>
    <table class="data-table" style="margin-bottom:1.25rem">
        <thead>
            <tr><th>Area</th><th>Size</th><th>Notes</th></tr>
        </thead>
        <tbody>
            <?php foreach ($storageReport as $row): ?>
                <tr>
                    <td><code><?= h($row['path']) ?></code></td>
                    <td><?= h(formatBytesHuman($row['bytes'])) ?></td>
                    <td><?= h($row['label']) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td><code>storage/logs/*.log</code> (total)</td>
                <td><strong><?= h(formatBytesHuman($totalLogBytes)) ?></strong></td>
                <td><?= $totalLogBytes > 1048576 ? 'Clear logs below if over 1 MB' : 'OK' ?></td>
            </tr>
        </tbody>
    </table>

    <?php if ($largeLogs !== []): ?>
        <p class="form-hint">Large logs:</p>
        <ul class="form-hint" style="margin:0 0 1rem 1rem">
            <?php foreach ($largeLogs as $log): ?>
                <li><?= h($log['file']) ?> — <?= h(formatBytesHuman($log['bytes'])) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" class="erp-settings-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">

        <label class="form-checkbox" style="display:block;margin-bottom:0.75rem">
            <input type="checkbox" name="clear_logs" value="1" checked>
            <span><strong>Clear all log files</strong> (google-sheets.log, mail.log, roster-import.log, etc.)</span>
        </label>

        <label class="form-checkbox" style="display:block;margin-bottom:1rem">
            <input type="checkbox" name="purge_google_test_sheets" value="1">
            <span><strong>Purge test spreadsheets</strong> on the robot Google account (safe — keeps your Gmail sheets)</span>
        </label>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Run cleanup now</button>
            <a href="google-sheets-diagnostic.php" class="btn btn--secondary">Google Sheets diagnostic</a>
        </div>
    </form>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
