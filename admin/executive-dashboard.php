<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/workforce/executive-snapshot.php';
require_once __DIR__ . '/../includes/workforce/workforce-analytics.php';
require_once __DIR__ . '/../includes/system-settings.php';

requireAdminCapability('dashboard');

if (!isAdminSuperUser() && getAdminRole() !== 'manager') {
    setAdminFlash('error', 'Executive dashboard is for administrators and managers.');
    header('Location: dashboard.php');
    exit;
}

$pdo  = getDB();
$snap = wf_get_executive_snapshot($pdo);
$highRisk = wf_list_staff_by_risk($pdo, '30d', 'red', 10);

try {
    $upcoming = $pdo->query(
        "SELECT id, name, event_date, location, staff_needed FROM events
         WHERE is_active = 1 AND event_date >= CURDATE()
         ORDER BY event_date ASC LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $upcoming = [];
}

$pageTitle  = 'Executive Dashboard';
$activePage = 'executive-dashboard';
$erpPageContentClass = 'wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Executive Dashboard</h1>
        <p class="wf-hero__subtitle">Director view — revenue, attendance, utilization, compliance, risk, and operational alerts.</p>
    </div>
    <a href="dashboard.php" class="btn btn--secondary">Operations dashboard</a>
</div>

<div class="wf-grid">
    <?php if (adminCan('invoices')): ?>
        <div class="wf-metric wf-metric--green">
            <div class="wf-metric__value"><?= h(formatSystemCurrencyAmount((float) $snap['revenue_month'], $pdo)) ?></div>
            <div class="wf-metric__label">Revenue (month)</div>
        </div>
        <div class="wf-metric wf-metric--amber">
            <div class="wf-metric__value"><?= h(formatSystemCurrencyAmount((float) $snap['outstanding_amount'], $pdo)) ?></div>
            <div class="wf-metric__label">Outstanding (<?= (int) $snap['outstanding_count'] ?>)</div>
        </div>
    <?php endif; ?>
    <div class="wf-metric">
        <div class="wf-metric__value"><?= (int) $snap['attendance_rate'] ?>%</div>
        <div class="wf-metric__label">Attendance rate (30d)</div>
    </div>
    <div class="wf-metric">
        <div class="wf-metric__value"><?= (int) $snap['staff_utilization'] ?>%</div>
        <div class="wf-metric__label">Staff utilization</div>
    </div>
    <div class="wf-metric">
        <div class="wf-metric__value"><?= (int) $snap['upcoming_events'] ?></div>
        <div class="wf-metric__label">Upcoming events</div>
    </div>
    <div class="wf-metric wf-metric--amber">
        <div class="wf-metric__value"><?= (int) $snap['compliance_alerts'] ?></div>
        <div class="wf-metric__label">Compliance issues</div>
    </div>
    <div class="wf-metric wf-metric--red">
        <div class="wf-metric__value"><?= (int) $snap['high_risk_staff'] ?></div>
        <div class="wf-metric__label">High risk staff</div>
    </div>
</div>

<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
    <section class="card erp-card">
        <h2 class="wf-panel__title">Operational alerts</h2>
        <?php if (($snap['operational_alerts'] ?? []) === []): ?>
            <p class="card__subtitle">No critical alerts.</p>
        <?php else: ?>
            <ul class="wf-alert-list">
                <?php foreach ($snap['operational_alerts'] as $alert): ?>
                    <li><?= h((string) $alert) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card erp-card">
        <h2 class="wf-panel__title">High risk staff</h2>
        <?php if ($highRisk === []): ?>
            <p class="card__subtitle">None flagged.</p>
        <?php else: ?>
            <ul class="wf-alert-list">
                <?php foreach ($highRisk as $row): ?>
                    <li>
                        <a href="view-staff.php?id=<?= (int) ($row['id'] ?? 0) ?>"><?= h((string) ($row['name'] ?? '')) ?></a>
                        — score <?= (int) ($row['score'] ?? 0) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p style="margin-top:0.75rem;"><a href="workforce-risk.php">View all risk categories →</a></p>
    </section>
</div>

<section class="card erp-card" style="margin-top:1rem;">
    <h2 class="wf-panel__title">Upcoming events</h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Event</th><th>Date</th><th>Venue</th><th>Staff needed</th></tr></thead>
            <tbody>
            <?php if ($upcoming === []): ?>
                <tr><td colspan="4" class="data-table__empty">No upcoming events.</td></tr>
            <?php else: ?>
                <?php foreach ($upcoming as $ev): ?>
                    <tr>
                        <td><a href="event-staffing.php?event_id=<?= (int) ($ev['id'] ?? 0) ?>"><?= h((string) ($ev['name'] ?? '')) ?></a></td>
                        <td><?= h(formatSystemDate((string) ($ev['event_date'] ?? ''), $pdo)) ?></td>
                        <td><?= h((string) ($ev['location'] ?? '—')) ?></td>
                        <td><?= (int) ($ev['staff_needed'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
