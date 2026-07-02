<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/platform/payroll-intelligence.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/staff-repository.php';

requireAdminCapability('invoices');

$pdo = getDB();
if (!empty($_GET['scan'])) {
    $found = runPayrollIntelligenceScan($pdo);
    setSetting($pdo, 'payroll_intelligence_last_scan', gmdate('Y-m-d H:i:s') . ' UTC');
    setAdminFlash('success', 'Scan complete: ' . array_sum($found) . ' alerts generated.');
    header('Location: payroll-intelligence.php');
    exit;
}

$flash   = getAdminFlash();
$summary = getPayrollIntelligenceSummary($pdo);

$pageTitle  = 'Hours Reconciliation';
$activePage = 'payroll-intelligence';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">

<?php if ($flash): ?>
<div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card erp-card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Hours reconciliation</h2>
            <p class="card__subtitle"><?= (int) $summary['open_count'] ?> open alerts<?php if ($summary['last_scan_at']): ?> · Last scan <?= h((string) $summary['last_scan_at']) ?><?php endif; ?></p>
        </div>
        <a href="payroll-intelligence.php?scan=1" class="btn btn--primary">Run scan now</a>
    </div>

    <div class="platform-ops-grid">
        <?php foreach (['missing_hours' => 'Missing hours', 'duplicate_payments' => 'Duplicate hours rows', 'unpaid_staff' => 'Missing hours log', 'overtime' => 'Overtime', 'attendance_mismatch' => 'Mismatch'] as $key => $label): ?>
        <div class="platform-ops-metric<?= (int) ($summary['by_type'][$key] ?? 0) > 0 ? ' platform-ops-metric--warn' : '' ?>">
            <div class="platform-ops-metric__value"><?= (int) ($summary['by_type'][$key] ?? 0) ?></div>
            <div class="platform-ops-metric__label"><?= h($label) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="table-wrap" style="margin-top:1rem;">
        <table class="data-table">
            <thead><tr><th>Severity</th><th>Type</th><th>Title</th><th>Detail</th><th>When</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($summary['recent'] as $alert): ?>
                <?php
                $regId = (int) ($alert['related_id'] ?? 0);
                $eventIdForHours = 0;
                if ($regId > 0) {
                    $regRow = getStaffRegistrationById($pdo, $regId);
                    $eventIdForHours = (int) ($regRow['event_id'] ?? 0);
                }
                ?>
                <tr>
                    <td><?= h((string) ($alert['severity'] ?? '')) ?></td>
                    <td><?= h(payrollAlertTypeLabel((string) ($alert['alert_type'] ?? ''))) ?></td>
                    <td><?= h((string) ($alert['title'] ?? '')) ?></td>
                    <td><?= h(mb_strimwidth((string) ($alert['body'] ?? ''), 0, 60, '…')) ?></td>
                    <td><?= h(formatSystemDateTime((string) ($alert['created_at'] ?? ''), $pdo)) ?></td>
                    <td class="table-actions">
                        <?php if ($regId > 0): ?>
                        <a href="view-staff.php?id=<?= $regId ?>" class="btn btn--small btn--secondary">Staff</a>
                        <?php endif; ?>
                        <?php if ($eventIdForHours > 0): ?>
                        <a href="work-hours.php?event_id=<?= $eventIdForHours ?>" class="btn btn--small btn--ghost">Hours</a>
                        <?php endif; ?>
                        <form method="post" action="unified-inbox-action.php" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="source_type" value="payroll">
                            <input type="hidden" name="source_id" value="<?= (int) ($alert['id'] ?? 0) ?>">
                            <input type="hidden" name="action" value="read">
                            <button type="submit" class="btn btn--small btn--ghost">Resolve</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($summary['recent'] === []): ?>
                <tr><td colspan="6">No open alerts. Run a scan to detect anomalies.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
