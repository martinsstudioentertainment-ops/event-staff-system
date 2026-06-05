<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure-layout.php';
require_once __DIR__ . '/../includes/staff-import.php';
require_once __DIR__ . '/../includes/google-sheets-sync.php';

$imported = $updated = $skipped = 0;
$errors   = [];
$syncNote = '';

$eventPdo = getMainAdminPdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    try {
        if (!$eventPdo instanceof PDO) {
            throw new RuntimeException('Main ERP database is not connected.');
        }

        $result   = apply_import_approved_from_main($eventPdo, $pdo);
        $imported = $result['imported'];
        $updated  = $result['updated'];
        $skipped  = $result['skipped'];
        $errors   = $result['errors'];

        $sync     = run_apply_google_sheets_sync($pdo, $eventPdo);
        $syncNote = $sync['message'];
        if (!$sync['ok']) {
            $errors[] = $syncNote;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

secure_layout_start(
    'Import applicants',
    'applicants',
    'Copy approved main ERP registrations into the apply staff vault.'
);
?>

<?php if ($errors !== []): ?>
    <div class="secure-alert secure-alert--error"><?= secure_h(implode(' ', $errors)) ?></div>
<?php endif; ?>

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors === []): ?>
    <div class="secure-alert secure-alert--success">
        Sync complete — <?= $imported ?> new, <?= $updated ?> updated, <?= $skipped ?> skipped.
        <?php if ($syncNote !== ''): ?> <?= secure_h($syncNote) ?><?php endif; ?>
    </div>
<?php endif; ?>

<div class="secure-card secure-card--danger-top">
    <h2 style="margin:0 0 0.75rem;font-size:1rem;">Import approved registrations</h2>
    <p style="margin:0 0 1rem;color:var(--secure-muted);font-size:0.875rem;line-height:1.5;">
        Pulls <strong style="color:var(--secure-text);">approved</strong> rows from main ERP, updates existing vault records,
        then refreshes the Google Payroll Staff sheet.
    </p>
    <form method="post">
        <input type="hidden" name="action" value="import">
        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
            <button type="submit" class="secure-btn secure-btn--primary">Run import now</button>
            <a href="applicants.php" class="secure-btn secure-btn--ghost">Main registrations</a>
            <a href="sync-sheets.php" class="secure-btn secure-btn--ghost">Sync settings</a>
        </div>
    </form>
</div>

<?php secure_layout_end(); ?>
