<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/workforce/workforce-analytics.php';

requireAdminCapability('staff');

$pdo    = getDB();
$period = in_array($_GET['period'] ?? '', ['90d', '12m'], true) ? (string) $_GET['period'] : '30d';
$range  = wf_period_range($period);
$q      = trim((string) ($_GET['q'] ?? ''));

$filters = ['q' => $q];
$staff   = wf_list_staff_performance($pdo, $period, $filters, 100, 0);

$pageTitle  = 'Staff Performance Centre';
$activePage = 'workforce-performance';
$erpPageContentClass = 'wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Staff Performance Centre</h1>
        <p class="wf-hero__subtitle">Workforce intelligence — reliability, attendance, GPS compliance, and shift completion for <?= h($range['label']) ?>.</p>
    </div>
    <div class="wf-toolbar">
        <div class="wf-period-tabs">
            <?php foreach (['30d' => '30 days', '90d' => '90 days', '12m' => '12 months'] as $key => $label): ?>
                <a href="workforce-performance.php?period=<?= h($key) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="<?= $period === $key ? 'is-active' : '' ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<section class="card erp-card">
    <form method="get" class="wf-filters">
        <input type="hidden" name="period" value="<?= h($period) ?>">
        <div>
            <label for="q">Search staff</label>
            <input type="search" id="q" name="q" value="<?= h($q) ?>" placeholder="Name, email, mobile" class="input">
        </div>
        <div style="align-self:end;">
            <button type="submit" class="btn btn--primary">Filter</button>
        </div>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Staff</th>
                    <th>Reliability</th>
                    <th>Attendance %</th>
                    <th>GPS %</th>
                    <th>Shift completion %</th>
                    <th>Late</th>
                    <th>No-shows</th>
                    <th>Risk</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($staff === []): ?>
                <tr><td colspan="9" class="data-table__empty">No staff with approved shifts in this period.</td></tr>
            <?php else: ?>
                <?php foreach ($staff as $row): ?>
                    <?php
                    $scoreClass = ($row['score'] ?? 0) >= 70 ? 'high' : (($row['score'] ?? 0) >= 50 ? 'mid' : 'low');
                    $risk = (string) ($row['risk'] ?? 'green');
                    ?>
                    <tr>
                        <td>
                            <strong><?= h((string) ($row['name'] ?? '')) ?></strong><br>
                            <span class="text-muted"><?= h((string) ($row['email'] ?? '')) ?></span>
                        </td>
                        <td><span class="wf-score wf-score--<?= h($scoreClass) ?>"><?= (int) ($row['score'] ?? 0) ?></span></td>
                        <td><?= (int) ($row['attendance_pct'] ?? 0) ?>%</td>
                        <td><?= (int) ($row['gps_pct'] ?? 0) ?>%</td>
                        <td><?= (int) ($row['completion_pct'] ?? 0) ?>%</td>
                        <td><?= (int) ($row['late_cnt'] ?? 0) ?></td>
                        <td><?= (int) ($row['missed_cnt'] ?? 0) ?></td>
                        <td><span class="wf-risk wf-risk--<?= h($risk) ?>"><?= h(wf_risk_label($risk)) ?></span></td>
                        <td>
                            <div class="wf-actions">
                                <a class="btn btn--secondary" href="view-staff.php?id=<?= (int) ($row['id'] ?? 0) ?>">Profile</a>
                                <a class="btn btn--secondary" href="attendance.php?q=<?= urlencode((string) ($row['email'] ?? '')) ?>">Attendance</a>
                                <a class="btn btn--secondary" href="work-hours.php?q=<?= urlencode((string) ($row['email'] ?? '')) ?>">Work hours</a>
                                <a class="btn btn--secondary" href="staff.php?q=<?= urlencode((string) ($row['email'] ?? '')) ?>">Assignments</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
