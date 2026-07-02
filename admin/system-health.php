<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/admin/system-health.php';
require_once __DIR__ . '/../includes/platform/production-health.php';
require_once __DIR__ . '/../includes/app-build-version.php';

requireAdminCapability('settings');

$pdo     = getDB();
$summary = summarizeSystemHealth($pdo);
$health  = getProductionHealthSnapshot($pdo);
$build   = getAppBuildVersion();
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
            <div class="erp-dash-kpi__icon erp-dash-kpi__icon--<?= ($health['badge'] ?? '') === 'healthy' ? 'green' : 'amber' ?>" aria-hidden="true"><?= ($health['badge'] ?? '') === 'healthy' ? '🟢' : '🔴' ?></div>
            <div class="erp-dash-kpi__body">
                <p class="erp-dash-kpi__value"><?= h($health['badge_display'] ?? 'Unknown') ?></p>
                <p class="erp-dash-kpi__label">Production status · <?= (int) ($health['issue_count'] ?? 0) ?> issue(s)</p>
            </div>
        </div>
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

    <p class="form-hint" style="margin-bottom:0">
        Deployed build: <strong><?= h((string) ($build['version'] ?? '0.0.0')) ?></strong>
        (#<?= (int) ($build['build'] ?? 0) ?>)
        <?php if (($build['deployed_at'] ?? '') !== ''): ?>
            · <?= h((string) $build['deployed_at']) ?>
        <?php endif; ?>
        <?php if (($build['git_commit'] ?? '') !== ''): ?>
            · commit <?= h(substr((string) $build['git_commit'], 0, 8)) ?>
        <?php endif; ?>
    </p>
</section>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Production database</h2>
        <p class="card__subtitle">Live counts from production ERP.</p>
    </div>
    <div class="erp-dash-kpis">
        <?php
        $db = $health['database'] ?? [];
        $dbMetrics = [
            'Staff' => $db['staff'] ?? 0,
            'Active staff' => $db['active_staff'] ?? 0,
            'Approved staff' => $db['approved_staff'] ?? 0,
            'PSA holders' => $db['psa_holders'] ?? 0,
            'Events' => $db['events'] ?? 0,
            'Registrations' => $db['registrations'] ?? 0,
            'Attendance' => $db['attendance'] ?? 0,
            'Commission' => $db['commission_lines'] ?? 0,
            'Recruitment' => $db['recruitment'] ?? 0,
        ];
        foreach ($dbMetrics as $label => $value):
        ?>
        <div class="erp-dash-kpi">
            <div class="erp-dash-kpi__body">
                <p class="erp-dash-kpi__value"><?= (int) $value ?></p>
                <p class="erp-dash-kpi__label"><?= h($label) ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Integrity &amp; synchronisation</h2>
            <p class="card__subtitle">Duplicate, orphan, and sync checks from the latest production health snapshot.</p>
        </div>
        <a href="data-integrity.php" class="btn btn--secondary btn--small">Data integrity center</a>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>Check</th><th>Count</th></tr>
            </thead>
            <tbody>
                <?php foreach (($health['integrity'] ?? []) as $label => $count): ?>
                <tr>
                    <td><?= h(ucwords(str_replace('_', ' ', (string) $label))) ?></td>
                    <td><span class="badge badge--<?= (int) $count === 0 ? 'approved' : 'pending' ?>"><?= (int) $count ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="table-wrap" style="margin-top:1rem">
        <table class="data-table">
            <thead>
                <tr><th>Sync event</th><th>Last run (UTC)</th></tr>
            </thead>
            <tbody>
                <?php foreach (($health['synchronisation'] ?? []) as $label => $when): ?>
                <tr>
                    <td><?= h(ucwords(str_replace('_', ' ', (string) $label))) ?></td>
                    <td><?= h((string) $when) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Staff data quality</h2>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>Category</th><th>Total</th></tr>
            </thead>
            <tbody>
                <?php foreach (($health['staff_data_quality'] ?? []) as $label => $count): ?>
                <tr>
                    <td><?= h(ucwords(str_replace('_', ' ', (string) $label))) ?></td>
                    <td><?= (int) $count ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
