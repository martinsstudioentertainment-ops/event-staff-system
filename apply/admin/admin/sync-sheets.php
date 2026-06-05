<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure-layout.php';
require_once __DIR__ . '/../includes/staff-import.php';
require_once __DIR__ . '/../includes/google-sheets-sync.php';
require_once __DIR__ . '/../includes/cron-auth.php';

$result    = null;
$import    = null;
$syncError = '';
$cronUrl   = apply_cron_url();

if (($_GET['action'] ?? '') === 'run' || $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $eventPdo = getMainAdminPdo();
        if ($eventPdo instanceof PDO) {
            $import = apply_import_approved_from_main($eventPdo, $pdo);
        } else {
            $syncError = 'Main ERP database is not connected. Check apply/admin/config/eventstaff-database.php on the server.';
        }

        $result = run_apply_google_sheets_sync($pdo, $eventPdo instanceof PDO ? $eventPdo : null);
    } catch (Throwable $e) {
        error_log('[ApplySync] sync-sheets: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $syncError = $e->getMessage();
        $result    = [
            'ok'           => false,
            'message'      => 'Sync failed: ' . $e->getMessage(),
            'rows'         => 0,
            'payroll_rows' => 0,
            'master_rows'  => 0,
        ];
    }
}

secure_layout_start('Google Sheets sync', 'dashboard', 'Auto-sync runs every ~2 minutes while you use Apply admin or the main ERP console.');
?>

<?php if ($syncError !== '' && $import === null): ?>
    <div class="secure-alert secure-alert--error"><?= secure_h($syncError) ?></div>
<?php endif; ?>

<?php if ($import !== null): ?>
    <div class="secure-alert secure-alert--success">
        Main ERP: <?= (int) $import['imported'] ?> new, <?= (int) $import['updated'] ?> updated, <?= (int) $import['skipped'] ?> skipped<?php if (!empty($import['psa_synced'])): ?>, <?= (int) $import['psa_synced'] ?> PSA records refreshed<?php endif; ?>.
        <?php if (!empty($import['approved_registrations']) && (int) $import['approved_registrations'] > (int) ($import['unique_people'] ?? 0)): ?>
            <br><span style="font-size:0.85rem;opacity:0.95;">
                <?= (int) $import['approved_registrations'] ?> approved <em>registrations</em> →
                <?= (int) ($import['unique_people'] ?? 0) ?> unique people in vault (<?= (int) ($import['unique_touched'] ?? 0) ?> updated this run).
            </span>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($result !== null): ?>
    <div class="secure-alert <?= $result['ok'] ? 'secure-alert--success' : 'secure-alert--error' ?>">
        <?= secure_h($result['message']) ?>
    </div>
<?php endif; ?>

<div class="secure-card secure-card--danger-top">
    <h2 style="margin:0 0 0.75rem;font-size:1rem;">Payroll Staff sheet</h2>
    <p style="margin:0 0 1rem;color:var(--secure-muted);font-size:0.875rem;line-height:1.5;">
        Imports <strong style="color:var(--secure-text);">approved</strong> staff from main ERP into the apply vault,
        then updates <strong style="color:var(--secure-text);">Payroll Staff</strong> (10 payroll columns)
        and <strong style="color:var(--secure-text);">Master Sheet / Overall</strong> (full roster with event + status).
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:1rem;">
        <a href="?action=run" class="secure-btn secure-btn--success">Run sync now</a>
        <a href="payroll.php" class="secure-btn secure-btn--ghost">Payroll vault</a>
        <a href="dashboard.php" class="secure-btn secure-btn--ghost">Command center</a>
    </div>
</div>

<?php
$vaultStats = apply_payroll_sync_stats($pdo);
$mainStats  = null;
if (getMainAdminPdo() instanceof PDO) {
    $mainStats = apply_main_erp_import_stats(getMainAdminPdo());
}
?>
<div class="secure-card">
    <h2 style="margin:0 0 0.75rem;font-size:1rem;">Payroll vs other lists</h2>
    <ul style="margin:0;padding-left:1.2rem;color:var(--secure-muted);font-size:0.875rem;line-height:1.6;">
        <?php if ($mainStats !== null): ?>
            <li><strong style="color:var(--secure-text);">Payroll Staff sheet</strong> — <?= $mainStats['unique_emails'] ?> people (every approved registrant with an email).</li>
            <?php if ($mainStats['unique_pending_emails'] > 0): ?>
                <li><strong style="color:var(--secure-text);">Not on payroll yet</strong> — <?= $mainStats['unique_pending_emails'] ?> people still <em>pending approval</em> on main ERP.</li>
            <?php endif; ?>
            <li><strong style="color:var(--secure-text);">Master Sheet</strong> — <?= $mainStats['approved_registrations'] ?> approved registration rows<?php if ($mainStats['approved_registrations'] > $mainStats['unique_emails']): ?> (same person can appear for multiple events)<?php endif; ?>.</li>
        <?php endif; ?>
        <li><strong style="color:var(--secure-text);">Apply vault</strong> — <?= $vaultStats['vault_total'] ?> staff (PSA verification; does not limit payroll).</li>
    </ul>
</div>

<div class="secure-card">
    <h2 style="margin:0 0 0.75rem;font-size:1rem;">Automatic sync</h2>
    <p style="margin:0 0 0.75rem;color:var(--secure-muted);font-size:0.875rem;line-height:1.5;">
        No cPanel cron required. Sync runs automatically while <strong style="color:var(--secure-text);">Apply admin</strong>
        or <strong style="color:var(--secure-text);">main ERP admin</strong> is open, and immediately when staff are approved on the main site.
        Use <strong style="color:var(--secure-text);">Run sync now</strong> above for an instant refresh.
    </p>
    <p style="margin:0;color:var(--secure-muted);font-size:0.8rem;line-height:1.5;">
        Optional server cron (if no one is logged in): <code style="font-size:0.75rem;">*/2 * * * * curl -fsS "<?= secure_h($cronUrl) ?>"</code>
    </p>
</div>

<?php secure_layout_end(); ?>
