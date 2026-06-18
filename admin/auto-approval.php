<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/platform/auto-approval-engine.php';
require_once __DIR__ . '/../includes/feature-flags.php';

requireAdminCapability('staff');

$pdo      = getDB();
$flash    = getAdminFlash();
$settings = getAutoApprovalSettings($pdo);
$mode     = getAutoApprovalMode($pdo);
$log      = getRecentAutoApprovalLog($pdo, 40);
$summary  = summarizeAutoApprovalLog($pdo, 30);

$pageTitle  = 'Auto Approval';
$activePage = 'auto-approval';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">

<?php if ($flash): ?>
<div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card erp-card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Auto approval engine</h2>
            <p class="card__subtitle">Mode: <strong><?= match ($mode) { 2 => 'Live', 1 => 'Shadow (log only)', default => 'Off' } ?></strong> — toggle in <a href="feature-flags.php">Feature flags</a> (0/1/2).</p>
        </div>
    </div>

    <div class="platform-ops-grid">
        <div class="platform-ops-metric platform-ops-metric--ok"><div class="platform-ops-metric__value"><?= (int) ($summary['approve_live'] ?? 0) ?></div><div class="platform-ops-metric__label">Live approvals (30d)</div></div>
        <div class="platform-ops-metric platform-ops-metric--danger"><div class="platform-ops-metric__value"><?= (int) ($summary['reject_live'] ?? 0) ?></div><div class="platform-ops-metric__label">Live rejections (30d)</div></div>
        <div class="platform-ops-metric"><div class="platform-ops-metric__value"><?= (int) ($summary['shadow'] ?? 0) ?></div><div class="platform-ops-metric__label">Shadow evaluations (30d)</div></div>
    </div>

    <form method="post" action="auto-approval-action.php" class="form-stack" style="margin-top:1rem;">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save_settings">
        <h3 class="form-section-title">Approval rules</h3>
        <div class="form-grid form-grid--2">
            <?php
            $ruleFields = [
                'rule_returning_staff'     => 'Approve returning staff (prior registration)',
                'rule_previously_approved' => 'Approve previously approved staff',
                'rule_complete_profile'    => 'Require complete profile',
                'rule_verified_psa'        => 'Require verified PSA documents',
                'rule_reject_blacklist'    => 'Auto-reject blacklisted',
                'rule_reject_duplicate'    => 'Auto-reject duplicates',
            ];
            foreach ($ruleFields as $key => $label):
            ?>
            <label class="form-check"><input type="checkbox" name="<?= h($key) ?>" value="1"<?= ($settings[$key] ?? '0') === '1' ? ' checked' : '' ?>> <?= h($label) ?></label>
            <?php endforeach; ?>
        </div>
        <label class="form-field">
            <span class="form-label">Minimum confidence (%)</span>
            <input type="number" name="min_confidence" min="0" max="100" value="<?= (int) ($settings['min_confidence'] ?? 35) ?>" class="form-input" style="max-width:6rem;">
        </label>
        <label class="form-field">
            <span class="form-label">Event overrides (JSON)</span>
            <textarea name="event_overrides_json" class="form-input" rows="3" placeholder='{"123":"off","456":"strict"}'><?= h((string) ($settings['event_overrides_json'] ?? '{}')) ?></textarea>
            <span class="form-hint">Per event_id: off = skip, strict = 90% threshold.</span>
        </label>
        <button type="submit" class="btn btn--primary">Save settings</button>
    </form>

    <form method="post" action="auto-approval-action.php" class="form-stack" style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--border-subtle, #e5e7eb);">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="evaluate_pending">
        <h3 class="form-section-title">Evaluate pending queue</h3>
        <p class="form-hint">Batch-run approval rules on all pending registrations. Shadow mode logs decisions only unless live mode is enabled and shadow is unchecked.</p>
        <label class="form-check"><input type="checkbox" name="dry_run" value="1" checked> Shadow / log only (recommended)</label>
        <button type="submit" class="btn btn--secondary" onclick="return confirm('Evaluate all pending registrations now?');">Evaluate pending queue</button>
    </form>
</section>

<section class="card erp-card">
    <h3 class="card__title">Recent decisions</h3>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>When</th><th>Email</th><th>Event</th><th>Decision</th><th>Confidence</th><th>Applied</th></tr></thead>
            <tbody>
            <?php foreach ($log as $row): ?>
                <tr>
                    <td><?= h(formatSystemDateTime((string) ($row['created_at'] ?? ''), $pdo)) ?></td>
                    <td><?= h((string) ($row['email'] ?? '')) ?></td>
                    <td><?= h((string) ($row['event_name'] ?? '')) ?></td>
                    <td><?= h((string) ($row['decision'] ?? '')) ?></td>
                    <td><?= (int) ($row['confidence'] ?? 0) ?>%</td>
                    <td><?= !empty($row['applied']) ? 'Yes' : 'No' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($log === []): ?>
                <tr><td colspan="6">No log entries yet. Enable shadow or live mode to evaluate registrations.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
