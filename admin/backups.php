<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/global-public-site.php';
require_once __DIR__ . '/../includes/system-settings.php';
require_once __DIR__ . '/../includes/weekly-backup.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('backups');

$pdo           = getDB();
$brand         = getGlobalPublicSiteConfig($pdo);
$flash         = getAdminFlash();
$weeklyFiles   = listWeeklyBackupFiles();
$lastWeekly    = getLastWeeklyBackupAt($pdo);

$pageTitle  = 'Backup';
$activePage = 'backups';
$erpSettingsActive = 'backup';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card erp-card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Weekly full backup</h2>
            <p class="card__subtitle">
                One database dump, one settings/CMS JSON, and one site ZIP — saved to
                <code>storage/backups/weekly/</code> and <strong>overwritten each run</strong> (no pile-up).
            </p>
        </div>
        <form method="post" action="backup-run.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <button type="submit" class="btn btn--primary">Run full backup now</button>
        </form>
    </div>

    <?php if ($lastWeekly): ?>
        <p class="form-hint">Last full backup: <strong><?= h(date('d.m.Y H:i', strtotime($lastWeekly))) ?></strong></p>
    <?php endif; ?>

    <div class="table-wrap" style="margin-top:1rem;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>File</th>
                    <th>Size</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($weeklyFiles as $file): ?>
                    <tr>
                        <td>
                            <strong><?= h($file['label']) ?></strong><br>
                            <code><?= h($file['filename']) ?></code>
                        </td>
                        <td><?= $file['exists'] ? h(formatBackupBytes((int) $file['size'])) : '—' ?></td>
                        <td><?= $file['exists'] ? h(date('d.m.Y H:i', (int) $file['modified'])) : 'Not created yet' ?></td>
                        <td>
                            <?php if ($file['exists']): ?>
                                <a href="backup-download.php?file=<?= urlencode($file['filename']) ?>" class="btn btn--small btn--secondary">Download</a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h3 class="form-section-title" style="margin-top:1.5rem;">Schedule on server (once per week)</h3>
    <pre class="code-block">0 3 * * 0 php /path/to/event-staff-system/cron/weekly-backup.php</pre>
    <p class="form-hint">Sunday 03:00 — or enable <strong>Weekly auto backup</strong> in Settings → System and use the same cron path with <code>auto-backup.php</code>.</p>

    <?php if (isAutoBackupEnabled($pdo)): ?>
        <p class="form-hint">Weekly auto backup is <strong>enabled</strong> in system settings.</p>
    <?php endif; ?>
</section>

<section class="card erp-card">
    <div class="card__header">
        <h2 class="card__title">Extra JSON exports</h2>
        <p class="card__subtitle">Download copies to your PC (does not replace the weekly files on server).</p>
    </div>

    <div class="toolbar">
        <a href="backups-export.php?type=settings" class="btn btn--secondary">Settings JSON</a>
        <a href="backups-export.php?type=website" class="btn btn--secondary">Website CMS JSON</a>
        <a href="backups-export.php?type=full" class="btn btn--secondary">Full site pack JSON</a>
        <a href="go-live.php" class="btn btn--secondary">Go live checklist</a>
    </div>

    <dl class="detail-list detail-list--compact" style="margin-top:1.25rem;">
        <div class="detail-list__row"><dt>Public company name</dt><dd><?= h($brand['companyName']) ?></dd></div>
        <div class="detail-list__row"><dt>Registration URL</dt><dd><?= h($brand['registrationUrl']) ?></dd></div>
    </dl>

    <p class="form-hint" style="margin-top:1rem;">Copy weekly files offsite via SFTP/FTP — do not rely on the server as the only copy.</p>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
