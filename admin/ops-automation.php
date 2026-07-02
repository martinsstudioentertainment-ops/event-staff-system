<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/system-settings.php';
require_once __DIR__ . '/../includes/automation/automation-schema.php';
require_once __DIR__ . '/../includes/automation/ops-automation.php';

requireAdminCapability('settings');

$pdo   = getDB();
$flash = getAdminFlash();
auto_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? null)) {
    if (($_POST['action'] ?? '') === 'run') {
        $stats = ops_run_automation($pdo);
        setAdminFlash('success', 'Automation run complete: ' . json_encode($stats));
    } elseif (($_POST['action'] ?? '') === 'ack') {
        ops_acknowledge_alert($pdo, (int) ($_POST['alert_id'] ?? 0));
        setAdminFlash('success', 'Alert acknowledged.');
    }
    header('Location: ops-automation.php');
    exit;
}

$lastRun = getSetting($pdo, 'ops_automation_last_run', 'Never');
$alerts  = ops_recent_alerts($pdo, 80, !empty($_GET['unack']));

$pageTitle  = 'Operations Automation Engine';
$activePage = 'ops-automation';
$erpPageContentClass = 'auto-page wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">

<?php if ($flash): ?><div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div><?php endif; ?>

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Operations Automation Engine</h1>
        <p class="wf-hero__subtitle">Staff shortages, compliance expiry, attendance risk, invoice reminders, event reminders. Last run: <?= h($lastRun) ?></p>
    </div>
    <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="run"><button class="btn btn--primary">Run now</button></form>
</div>

<section class="card erp-card">
    <p class="card__subtitle">Schedule via cron: <code>php cron/operations-automation.php</code> or web: <code>cron/operations-automation.php?key=YOUR_SECRET</code> (uses <code>reminder_cron_key</code>).</p>
    <div class="wf-grid">
        <div class="wf-metric"><div class="wf-metric__label">Automates</div><div style="font-size:0.85rem;margin-top:0.35rem;">Staff shortage · Compliance · Attendance · Invoices · Events</div></div>
    </div>
</section>

<section class="card erp-card">
    <div class="card__header card__header--row">
        <h2 class="card__title">Automation log</h2>
        <a href="ops-automation.php?unack=1" class="btn btn--secondary">Unacknowledged only</a>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>When</th><th>Type</th><th>Severity</th><th>Message</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($alerts as $a): ?>
                <tr>
                    <td><?= h(formatSystemDateTime((string) ($a['created_at'] ?? ''), $pdo)) ?></td>
                    <td><?= h((string) ($a['alert_type'] ?? '')) ?></td>
                    <td><span class="wf-risk wf-risk--<?= ($a['severity'] ?? '') === 'critical' ? 'red' : (($a['severity'] ?? '') === 'warning' ? 'amber' : 'green') ?>"><?= h((string) ($a['severity'] ?? '')) ?></span></td>
                    <td><?= h((string) ($a['message'] ?? '')) ?></td>
                    <td><?php if (empty($a['acknowledged'])): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="ack"><input type="hidden" name="alert_id" value="<?= (int) ($a['id'] ?? 0) ?>"><button class="btn btn--secondary btn--sm">Ack</button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($alerts === []): ?><tr><td colspan="5" class="data-table__empty">No alerts. Run automation or wait for cron.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
