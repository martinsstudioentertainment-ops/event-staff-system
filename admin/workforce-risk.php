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

$high   = wf_list_staff_by_risk($pdo, $period, 'red', 50);
$medium = wf_list_staff_by_risk($pdo, $period, 'amber', 50);
$low    = wf_list_staff_by_risk($pdo, $period, 'green', 50);

$pageTitle  = 'Staff Risk Management';
$activePage = 'workforce-risk';
$erpPageContentClass = 'wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Staff Risk Management</h1>
        <p class="wf-hero__subtitle">Automatic classification from absences, late arrivals, GPS failures, blacklist history, and missed shifts — <?= h($range['label']) ?>.</p>
    </div>
    <div class="wf-toolbar">
        <div class="wf-period-tabs">
            <?php foreach (['30d' => '30 days', '90d' => '90 days', '12m' => '12 months'] as $key => $label): ?>
                <a href="workforce-risk.php?period=<?= h($key) ?>" class="<?= $period === $key ? 'is-active' : '' ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="wf-grid">
    <div class="wf-metric wf-metric--red"><div class="wf-metric__value"><?= count($high) ?></div><div class="wf-metric__label">High risk</div></div>
    <div class="wf-metric wf-metric--amber"><div class="wf-metric__value"><?= count($medium) ?></div><div class="wf-metric__label">Medium risk</div></div>
    <div class="wf-metric wf-metric--green"><div class="wf-metric__value"><?= count($low) ?></div><div class="wf-metric__label">Low risk</div></div>
</div>

<?php
$sections = [
    ['title' => 'High Risk Staff', 'risk' => 'red', 'rows' => $high],
    ['title' => 'Medium Risk Staff', 'risk' => 'amber', 'rows' => $medium],
    ['title' => 'Low Risk Staff', 'risk' => 'green', 'rows' => $low],
];
foreach ($sections as $section):
?>
<section class="card erp-card wf-panel">
    <h2 class="wf-panel__title"><span class="wf-risk wf-risk--<?= h($section['risk']) ?>"><?= h($section['title']) ?></span></h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>Staff</th><th>Score</th><th>Late</th><th>Missed</th><th>GPS issues</th><th>Blacklist</th><th></th></tr>
            </thead>
            <tbody>
            <?php if ($section['rows'] === []): ?>
                <tr><td colspan="7" class="data-table__empty">None in this category.</td></tr>
            <?php else: ?>
                <?php foreach ($section['rows'] as $row): ?>
                    <tr>
                        <td><?= h((string) ($row['name'] ?? '')) ?></td>
                        <td><?= (int) ($row['score'] ?? 0) ?></td>
                        <td><?= (int) ($row['late_cnt'] ?? 0) ?></td>
                        <td><?= (int) ($row['missed_cnt'] ?? 0) ?></td>
                        <td><?= (int) ($row['gps_cnt'] ?? 0) ?></td>
                        <td><?= !empty($row['is_blacklisted']) ? 'Yes' : '—' ?></td>
                        <td><a class="btn btn--secondary" href="view-staff.php?id=<?= (int) ($row['id'] ?? 0) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endforeach; ?>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
