<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure-layout.php';
require_once __DIR__ . '/../includes/staff-import.php';
require_once __DIR__ . '/../includes/import-precheck.php';
require_once __DIR__ . '/../includes/main-admin-bridge.php';
require_once __DIR__ . '/../includes/google-sheets-sync.php';

$imported  = $updated = $skipped = 0;
$errors    = [];
$syncNote  = '';
$precheck  = null;
$showPrecheck = false;
$lastAction = '';

$eventPdo = getMainAdminPdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lastAction = (string) ($_POST['action'] ?? '');

    try {
        if (!$eventPdo instanceof PDO) {
            throw new RuntimeException('Main ERP database is not connected.');
        }

        if ($lastAction === 'precheck') {
            $precheck     = apply_run_import_precheck($eventPdo, $pdo);
            $showPrecheck = true;
        } elseif ($lastAction === 'import') {
            if (empty($_POST['confirm_import'])) {
                $precheck     = apply_run_import_precheck($eventPdo, $pdo);
                $showPrecheck = true;
            } else {
                $result   = apply_import_approved_from_main($eventPdo, $pdo);
                $imported = $result['imported'];
                $updated  = $result['updated'];
                $skipped  = $result['skipped'];
                $errors   = $result['errors'];

                $syncNote = 'Payroll vault updated. Google Sheets sync runs in the background.';
                register_shutdown_function(static function () use ($pdo, $eventPdo): void {
                    try {
                        run_apply_google_sheets_sync($pdo, $eventPdo);
                    } catch (Throwable $e) {
                        error_log('[ApplySync] deferred sheets sync after import: ' . $e->getMessage());
                    }
                });
            }
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
} elseif ($eventPdo instanceof PDO) {
    $precheck = apply_run_import_precheck($eventPdo, $pdo);
}

secure_layout_start(
    'Import applicants',
    'applicants',
    'Copy approved main ERP registrations into the apply staff vault.'
);
?>

<?php if ($errors !== []): ?>
    <div class="secure-alert secure-alert--error">
        <strong>Import notes (<?= count($errors) ?>)</strong>
        <ul style="margin:0.5rem 0 0;padding-left:1.2rem;font-size:0.875rem;line-height:1.5;">
            <?php foreach (array_slice($errors, 0, 40) as $err): ?>
                <li><?= secure_h($err) ?></li>
            <?php endforeach; ?>
            <?php if (count($errors) > 40): ?>
                <li>… and <?= count($errors) - 40 ?> more</li>
            <?php endif; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && $lastAction === 'import' && !empty($_POST['confirm_import']) && $errors === []): ?>
    <div class="secure-alert secure-alert--success">
        Sync complete — <?= $imported ?> new, <?= $updated ?> updated, <?= $skipped ?> skipped.
        <?php if ($syncNote !== ''): ?> <?= secure_h($syncNote) ?><?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($precheck !== null): ?>
<div class="secure-card">
    <h2 style="margin:0 0 0.75rem;font-size:1rem;">Import pre-check</h2>
    <p style="margin:0 0 0.75rem;color:var(--secure-muted);font-size:0.875rem;">
        <?= (int) ($precheck['counts']['total'] ?? 0) ?> unique approved people —
        <strong style="color:var(--secure-text);"><?= (int) ($precheck['counts']['ok'] ?? 0) ?> clear</strong>,
        <?= (int) ($precheck['counts']['warn'] ?? 0) ?> phone warnings,
        <?= (int) ($precheck['counts']['block'] ?? 0) ?> PSA conflicts.
    </p>
    <?php if (($precheck['warnings'] ?? []) !== []): ?>
        <ul style="margin:0 0 1rem;padding-left:1.2rem;font-size:0.8125rem;line-height:1.55;color:var(--secure-muted);max-height:16rem;overflow:auto;">
            <?php foreach (array_slice($precheck['warnings'], 0, 50) as $w): ?>
                <li><strong style="color:var(--secure-text);"><?= secure_h((string) ($w['email'] ?? '')) ?></strong>
                    — <?= secure_h((string) ($w['message'] ?? '')) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p style="margin:0 0 1rem;color:var(--secure-muted);font-size:0.875rem;">No conflicts detected. Safe to import.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="secure-card secure-card--danger-top">
    <h2 style="margin:0 0 0.75rem;font-size:1rem;">Import approved registrations</h2>
    <p style="margin:0 0 1rem;color:var(--secure-muted);font-size:0.875rem;line-height:1.5;">
        Step 1: run pre-check. Step 2: import with clear skip messages when phone or PSA already belongs to someone else.
    </p>
    <form method="post" style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:0.75rem;">
        <input type="hidden" name="action" value="precheck">
        <button type="submit" class="secure-btn secure-btn--ghost">Run pre-check</button>
    </form>
    <form method="post">
        <input type="hidden" name="action" value="import">
        <input type="hidden" name="confirm_import" value="1">
        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
            <button type="submit" class="secure-btn secure-btn--primary"<?= ($precheck !== null && (int) ($precheck['counts']['block'] ?? 0) > 0) ? ' onclick="return confirm(\'PSA conflicts detected. Import will skip those rows. Continue?\');"' : '' ?>>Run import now</button>
            <a href="applicants.php" class="secure-btn secure-btn--ghost">Main registrations</a>
            <a href="sync-sheets.php" class="secure-btn secure-btn--ghost">Sync settings</a>
        </div>
    </form>
</div>

<?php secure_layout_end(); ?>
