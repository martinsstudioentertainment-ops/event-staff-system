<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/go-live.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('settings');

$pdo      = getDB();
$flash    = getAdminFlash();
$dashboard  = getGoLiveDashboard($pdo);
$summary    = $dashboard['summary'];
$sections   = getGoLiveChecklistSections($pdo);
$backups    = listDatabaseBackups(8);

$pageTitle  = 'Go live';
$activePage = 'go-live';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Go live checklist</h2>
            <p class="card__subtitle">
                Complete every item before running real events.
                <?= (int) $summary['pass'] ?> automated passed ·
                <?= (int) $summary['warn'] ?> warnings ·
                <?= (int) $summary['fail'] ?> must fix ·
                <?= (int) $summary['manual_done'] ?>/<?= (int) $summary['manual_total'] ?> manual tasks done
            </p>
        </div>
        <?php if ($summary['ready']): ?>
            <span class="badge badge--approved">Ready for go live</span>
        <?php else: ?>
            <span class="badge badge--pending">Not ready yet</span>
        <?php endif; ?>
    </div>

    <?php if ($summary['ready']): ?>
        <div class="alert alert--success alert--visible">All automated and manual checks are complete. You can go live when your first real event is scheduled.</div>
    <?php elseif ($summary['fail'] > 0): ?>
        <div class="alert alert--error alert--visible">
            Fix all <strong>FAIL</strong> items below before go-live.
            Use <strong>Fix automated FAIL items</strong> for schema, storage, cron secret, from email, and backups.
            You must still set <code>APP_ENV=production</code> in server <code>config.php</code>, SMTP, invoice bank details, and change the default admin password manually.
        </div>
    <?php else: ?>
        <div class="alert alert--warning alert--visible">Resolve warnings and tick all manual tasks.</div>
    <?php endif; ?>

    <div class="toolbar">
        <?php if ($summary['fail'] > 0): ?>
            <form method="post" action="go-live-action.php" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="fix_failures">
                <button type="submit" class="btn btn--primary">Fix automated FAIL items</button>
            </form>
        <?php endif; ?>
        <form method="post" action="go-live-action.php" class="inline-form" onsubmit="return confirm('Import all summer events (date, location, staff needed, times, 1Plus Security)?');">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="sync_roster">
            <button type="submit" class="btn btn--secondary">Import summer event roster</button>
        </form>
        <form method="post" action="go-live-action.php" class="inline-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="schema">
            <button type="submit" class="btn btn--secondary">Apply safe schema updates</button>
        </form>
        <form method="post" action="go-live-action.php" class="inline-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="backup">
            <button type="submit" class="btn btn--primary">Run weekly full backup now</button>
        </form>
        <form method="post" action="go-live-action.php" class="inline-form" onsubmit="return confirm('Remove demo Aviva invoice(s)?');">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="purge_demo">
            <button type="submit" class="btn btn--danger">Remove demo invoices</button>
        </form>
        <a href="backups.php" class="btn btn--secondary">All backups</a>
        <a href="settings-production.php" class="btn btn--secondary">Production settings</a>
    </div>
</section>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Complete checklist</h2>
        <p class="card__subtitle">Automated checks run on each page load. Tick manual items when done on your server or hosting panel.</p>
    </div>

    <form method="post" action="go-live-action.php" class="go-live-unified">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="manual_save">

        <?php foreach ($sections as $section): ?>
            <h3 class="go-live-section__title" id="go-live-<?= h($section['key']) ?>"><?= h($section['title']) ?></h3>
            <div class="readiness-list">
                <?php foreach ($section['items'] as $item): ?>
                    <?php if (($item['kind'] ?? '') === 'manual'): ?>
                        <article class="readiness-item readiness-item--<?= h($item['status']) ?> readiness-item--manual">
                            <label class="form-checkbox go-live-manual__label readiness-item__manual-label">
                                <input type="checkbox" name="manual[<?= h($item['key']) ?>]" value="1"<?= ($item['status'] ?? '') === 'pass' ? ' checked' : '' ?>>
                                <span>
                                    <span class="readiness-item__head">
                                        <span class="readiness-item__badge"><?= ($item['status'] ?? '') === 'pass' ? 'DONE' : 'TODO' ?></span>
                                        <span class="readiness-item__title"><?= h($item['label']) ?></span>
                                    </span>
                                    <span class="readiness-item__detail"><?= h($item['detail']) ?></span>
                                </span>
                            </label>
                            <?php if (!empty($item['fix_url'])): ?>
                                <a href="<?= h($item['fix_url']) ?>" class="readiness-item__link">Open →</a>
                            <?php endif; ?>
                        </article>
                    <?php else: ?>
                        <article class="readiness-item readiness-item--<?= h($item['status']) ?>">
                            <div class="readiness-item__head">
                                <span class="readiness-item__badge"><?= h(strtoupper((string) ($item['status'] ?? ''))) ?></span>
                                <h4 class="readiness-item__title"><?= h($item['label']) ?></h4>
                            </div>
                            <p class="readiness-item__detail"><?= h($item['detail']) ?></p>
                            <?php if (!empty($item['fix_url'])): ?>
                                <a href="<?= h($item['fix_url']) ?>" class="readiness-item__link">Fix →</a>
                            <?php endif; ?>
                        </article>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Save manual checklist</button>
        </div>
    </form>
</section>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Weekly backup files</h2>
        <p class="card__subtitle">Stored in <code>storage/backups/weekly/</code> — overwritten each run (DB + settings + site zip). Download via <a href="backups.php">Backups</a> or SFTP.</p>
    </div>
    <?php if ($backups === []): ?>
        <p class="form-hint">No backups yet. Click <strong>Run weekly full backup now</strong> above.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>Type</th>
                        <th>Size</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $backup): ?>
                        <tr>
                            <td><code><?= h($backup['filename']) ?></code></td>
                            <td><?= h($backup['label'] ?? 'Backup') ?></td>
                            <td><?= h(formatBackupBytes((int) $backup['size'])) ?></td>
                            <td><?= h(date('d.m.Y H:i', (int) $backup['modified'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h3 class="form-section-title" style="margin-top:1.5rem;">Cron jobs to schedule on server</h3>
    <dl class="detail-list detail-list--compact">
        <div class="detail-list__row">
            <dt>Daily (required)</dt>
            <dd><code>php cron/daily-reminders.php</code> — email reminders + no-show blacklist</dd>
        </div>
        <div class="detail-list__row">
            <dt>Weekly (recommended)</dt>
            <dd><code>php cron/weekly-backup.php</code> — full DB + site zip (overwrites previous). Enable <strong>Weekly auto backup</strong> in Settings.</dd>
        </div>
    </dl>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
