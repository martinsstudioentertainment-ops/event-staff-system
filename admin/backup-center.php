<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/platform/backup-center.php';
require_once __DIR__ . '/../includes/weekly-backup.php';

requireAdminCapability('settings');

$pdo   = getDB();
$flash = getAdminFlash();
$snap  = getBackupCenterSnapshot($pdo);
$steps = getDisasterRecoveryPlaybookSteps();

$pageTitle  = 'Backup Center';
$activePage = 'backup-center';
$erpSettingsActive = 'backup';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">

<?php if ($flash): ?>
<div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card erp-card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">System backup center</h2>
            <p class="card__subtitle">Track backup health and restore readiness.</p>
        </div>
        <form method="post" action="backup-run.php">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <button type="submit" class="btn btn--primary">Run backup now</button>
        </form>
    </div>

    <div class="platform-ops-grid">
        <div class="platform-ops-metric<?= !empty($snap['restore_ready']) ? ' platform-ops-metric--ok' : ' platform-ops-metric--warn' ?>">
            <div class="platform-ops-metric__value"><?= !empty($snap['restore_ready']) ? 'Ready' : 'Review' ?></div>
            <div class="platform-ops-metric__label">Restore readiness</div>
        </div>
        <div class="platform-ops-metric">
            <div class="platform-ops-metric__value"><?= $snap['last_backup_at'] ? h(formatSystemDateTime((string) $snap['last_backup_at'], $pdo)) : 'Never' ?></div>
            <div class="platform-ops-metric__label">Last backup<?php if ($snap['days_since_backup'] !== null): ?> (<?= (int) $snap['days_since_backup'] ?>d ago)<?php endif; ?></div>
        </div>
        <div class="platform-ops-metric">
            <div class="platform-ops-metric__value"><?= h(formatBackupBytes((int) $snap['total_bytes'])) ?></div>
            <div class="platform-ops-metric__label">Total backup size</div>
        </div>
        <div class="platform-ops-metric">
            <div class="platform-ops-metric__value"><?= (int) $snap['readiness_score'] ?>%</div>
            <div class="platform-ops-metric__label">Production readiness</div>
        </div>
    </div>

    <div class="table-wrap" style="margin-top:1rem;">
        <table class="data-table">
            <thead><tr><th>Asset</th><th>Size</th><th>Updated</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($snap['files'] as $file): ?>
                <tr>
                    <td>
                        <strong><?= h((string) ($file['label'] ?? '')) ?></strong><br>
                        <code><?= h((string) ($file['filename'] ?? '')) ?></code>
                    </td>
                    <td><?= !empty($file['exists']) ? h(formatBackupBytes((int) $file['size'])) : '—' ?></td>
                    <td><?= !empty($file['exists']) ? h(formatSystemDateTime(date('Y-m-d H:i:s', (int) $file['modified']), $pdo)) : 'Not created' ?></td>
                    <td>
                        <?php if (!empty($file['exists'])): ?>
                            OK · <a href="backup-download.php?file=<?= urlencode((string) ($file['filename'] ?? '')) ?>" class="btn btn--small btn--secondary">Download</a>
                        <?php else: ?>
                            Missing
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card erp-card">
    <h3 class="card__title">Disaster recovery playbook</h3>
    <ol class="platform-ops-playbook">
        <?php foreach ($steps as $step): ?>
        <li><?= h($step) ?></li>
        <?php endforeach; ?>
    </ol>
    <p class="form-hint" style="margin-top:1rem;"><a href="go-live.php">Go live checklist</a></p>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
