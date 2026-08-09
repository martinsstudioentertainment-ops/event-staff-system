<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/secure-layout.php';
require_once __DIR__ . '/../includes/apply-friendly.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    apply_render_error_page(
        'Database unavailable',
        (string) ($applyDatabaseError ?? 'The Apply vault database is not available. Check server configuration or try again later.')
    );
}

$totalStaff = $incompleteCount = $pendingCount = $verifiedCount = $expiredCount = 0;

try {
    $totalStaff      = (int) $pdo->query('SELECT COUNT(*) FROM staff_master')->fetchColumn();
    $incompleteCount = (int) $pdo->query("SELECT COUNT(*) FROM staff_master WHERE profile_status = 'Incomplete'")->fetchColumn();
    $pendingCount    = (int) $pdo->query("SELECT COUNT(*) FROM staff_master WHERE profile_status = 'Pending Review'")->fetchColumn();
    $verifiedCount   = (int) $pdo->query("SELECT COUNT(*) FROM staff_master WHERE profile_status = 'Verified'")->fetchColumn();
    $expiredCount    = (int) $pdo->query("SELECT COUNT(*) FROM staff_master WHERE profile_status = 'Expired PSA'")->fetchColumn();
} catch (Exception $e) {
    // stats stay zero
}

secure_layout_start('Command center', 'dashboard', 'Staff profiles, compliance, and payroll — Premium UI X operations overview.');

?>

<div class="secure-stat-grid">
    <div class="secure-stat secure-stat--muted">
        <div class="secure-stat__label">Total staff</div>
        <div class="secure-stat__value"><?= $totalStaff ?></div>
    </div>
    <div class="secure-stat secure-stat--warn">
        <div class="secure-stat__label">Pending review</div>
        <div class="secure-stat__value"><?= $pendingCount ?></div>
    </div>
    <div class="secure-stat secure-stat--ok">
        <div class="secure-stat__label">Verified</div>
        <div class="secure-stat__value"><?= $verifiedCount ?></div>
    </div>
    <div class="secure-stat secure-stat--warn">
        <div class="secure-stat__label">Incomplete</div>
        <div class="secure-stat__value"><?= $incompleteCount ?></div>
    </div>
    <div class="secure-stat secure-stat--danger">
        <div class="secure-stat__label">Expired PSA</div>
        <div class="secure-stat__value"><?= $expiredCount ?></div>
    </div>
</div>

<div class="secure-card secure-card--danger-top">
    <h2 style="margin:0 0 0.75rem;font-size:1rem;">Quick actions</h2>
    <div class="secure-actions">
        <a class="secure-btn secure-btn--purple" href="staff-list.php">Staff directory</a>
        <a class="secure-btn secure-btn--warn" href="psa-compliance.php">PSA compliance</a>
        <a class="secure-btn secure-btn--cyan" href="payroll.php">Payroll vault</a>
        <a class="secure-btn secure-btn--primary" href="import-applicants.php">Import applicants</a>
        <a class="secure-btn secure-btn--success" href="sync-sheets.php">Sync Google Sheets</a>
        <a class="secure-btn secure-btn--ghost" href="settings.php">Security settings</a>
    </div>
</div>

<div class="secure-card">
    <h2 style="margin:0 0 0.5rem;font-size:1rem;">Security notice</h2>
    <p style="margin:0;color:var(--secure-muted);font-size:0.875rem;line-height:1.5;">
        All actions in this zone are logged. Staff PII, PSA credentials, and bank data must only be accessed for authorized workforce management.
        Sign out when leaving this terminal.
    </p>
</div>

<?php secure_layout_end(); ?>
