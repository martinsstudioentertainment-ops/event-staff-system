<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/admin/system-health.php';

requireAdminCapability('settings');

$pdo     = getDB();
$summary = summarizeSystemHealth($pdo);
$flash   = getAdminFlash();

$byCategory = [];
foreach ($summary['checks'] as $check) {
    $byCategory[$check['category']][] = $check;
}

$pageTitle  = 'System health';
$activePage = 'system-health';

include __DIR__ . '/../includes/admin/layout-top.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Production health dashboard</h2>
            <p class="card__subtitle">Database, cron, GPS, notifications, email, backups, and feature flags in one view.</p>
        </div>
        <div class="toolbar">
            <a href="go-live.php" class="btn btn--secondary btn--small">Go live checklist</a>
            <a href="feature-flags.php" class="btn btn--secondary btn--small">Feature flags</a>
            <a href="ops-checklist.php" class="btn btn--secondary btn--small">Ops checklist</a>
        </div>
    </div>

    <div class="erp-dash-kpis" style="margin-bottom:1.25rem">
        <div class="erp-dash-kpi">
            <div class="erp-dash-kpi__icon erp-dash-kpi__icon--green" aria-hidden="true">✓</div>
            <div class="erp-dash-kpi__body">
                <p class="erp-dash-kpi__value"><?= (int) $summary['pass'] ?></p>
                <p class="erp-dash-kpi__label">Passing</p>
            </div>
        </div>
        <div class="erp-dash-kpi">
            <div class="erp-dash-kpi__icon erp-dash-kpi__icon--amber" aria-hidden="true">!</div>
            <div class="erp-dash-kpi__body">
                <p class="erp-dash-kpi__value"><?= (int) $summary['warn'] ?></p>
                <p class="erp-dash-kpi__label">Warnings</p>
            </div>
        </div>
        <div class="erp-dash-kpi">
            <div class="erp-dash-kpi__icon erp-dash-kpi__icon--blue" aria-hidden="true">×</div>
            <div class="erp-dash-kpi__body">
                <p class="erp-dash-kpi__value"><?= (int) $summary['fail'] ?></p>
                <p class="erp-dash-kpi__label">Failures</p>
            </div>
        </div>
        <div class="erp-dash-kpi">
            <div class="erp-dash-kpi__icon erp-dash-kpi__icon--indigo" aria-hidden="true">%</div>
            <div class="erp-dash-kpi__body">
                <p class="erp-dash-kpi__value"><?= (int) $summary['score'] ?>%</p>
                <p class="erp-dash-kpi__label">Health score</p>
            </div>
        </div>
    </div>
</section>

<?php foreach ($byCategory as $category => $items): ?>
<section class="card">
    <div class="card__header">
        <h2 class="card__title"><?= h($category) ?></h2>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Check</th>
                    <th>Status</th>
                    <th>Detail</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $row): ?>
                    <tr>
                        <td><?= h($row['label']) ?></td>
                        <td>
                            <span class="badge badge--<?= $row['status'] === 'pass' ? 'approved' : ($row['status'] === 'fail' ? 'rejected' : 'pending') ?>">
                                <?= h(strtoupper($row['status'])) ?>
                            </span>
                        </td>
                        <td><?= h($row['detail']) ?></td>
                        <td>
                            <?php if (!empty($row['fix_url'])): ?>
                                <a href="<?= h($row['fix_url']) ?>" class="btn btn--small btn--secondary">Fix</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endforeach; ?>

<p class="form-hint" style="margin-top:1rem">
    Cron last-run times are not stored in-app — confirm schedules in cPanel.
    GPS web verification: <code>/cron/gps-readiness-report.php?key=…</code> on the registration host.
</p>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
