<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/platform/platform-schema.php';
require_once __DIR__ . '/../includes/platform/command-center.php';
require_once __DIR__ . '/../includes/platform/ai-ops.php';
require_once __DIR__ . '/../includes/feature-flags.php';

requireAdminCapability('dashboard');
requirePlatformFeature(getDB(), 'command_center_v2', 'Command Center');

$pdo           = getDB();
$flash         = getAdminFlash();
$cc            = getCommandCenterSnapshot($pdo);
$suggestions   = getAiOpsRecommendations($pdo);
$lastUpdated   = gmdate('Y-m-d H:i:s') . ' UTC';
$refreshActive = (int) ($cc['active_events'] ?? 0) > 0;

$pageTitle  = 'Command Center';
$activePage = 'command-center';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<?php if ($refreshActive): ?>
<meta http-equiv="refresh" content="60">
<?php endif; ?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">

<?php if ($flash): ?>
<div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card erp-card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Operations command center</h2>
            <p class="card__subtitle">Live snapshot — pending staff, events, attendance, hours, and sheets. Last updated <?= h($lastUpdated) ?>.</p>
        </div>
    </div>

    <div class="platform-ops-grid">
        <div class="platform-ops-metric platform-ops-metric--warn">
            <div class="platform-ops-metric__value"><?= (int) $cc['pending_registrations'] ?></div>
            <div class="platform-ops-metric__label">Pending registrations</div>
        </div>
        <div class="platform-ops-metric">
            <div class="platform-ops-metric__value"><?= (int) $cc['staff_online'] ?></div>
            <div class="platform-ops-metric__label">Staff checked in (4h)</div>
        </div>
        <div class="platform-ops-metric platform-ops-metric--ok">
            <div class="platform-ops-metric__value"><?= (int) $cc['active_events'] ?></div>
            <div class="platform-ops-metric__label">Active events</div>
        </div>
        <div class="platform-ops-metric">
            <div class="platform-ops-metric__value"><?= (int) $cc['checkins_today'] ?></div>
            <div class="platform-ops-metric__label">Check-ins today</div>
        </div>
        <div class="platform-ops-metric<?= (int) $cc['attendance_issues'] > 0 ? ' platform-ops-metric--danger' : '' ?>">
            <div class="platform-ops-metric__value"><?= (int) $cc['attendance_issues'] ?></div>
            <div class="platform-ops-metric__label">Attendance issues</div>
        </div>
        <div class="platform-ops-metric<?= (int) $cc['payroll_alerts'] > 0 ? ' platform-ops-metric--warn' : '' ?>">
            <div class="platform-ops-metric__value"><?= (int) $cc['payroll_alerts'] ?></div>
            <div class="platform-ops-metric__label">Hours alerts</div>
        </div>
        <div class="platform-ops-metric<?= (int) $cc['sheets_failed_24h'] > 0 ? ' platform-ops-metric--danger' : '' ?>">
            <div class="platform-ops-metric__value"><?= (int) $cc['sheets_connected'] ?></div>
            <div class="platform-ops-metric__label">Sheets linked · <?= (int) $cc['sheets_failed_24h'] ?> fail/24h</div>
        </div>
    </div>

    <div class="toolbar">
        <a href="staff.php?status=pending" class="btn btn--primary btn--small">Review pending</a>
        <a href="attendance.php" class="btn btn--secondary btn--small">Attendance</a>
        <a href="payroll-intelligence.php" class="btn btn--secondary btn--small">Hours reconciliation</a>
        <a href="google-sheets-control.php" class="btn btn--secondary btn--small">Sheets</a>
    </div>
</section>

<section class="card erp-card">
    <h3 class="card__title">Upcoming events</h3>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Event</th><th>Date</th><th>Approved / needed</th><th>Coverage gap</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($cc['upcoming_events'] as $event): ?>
                <tr>
                    <td><?= h((string) ($event['name'] ?? '')) ?></td>
                    <td><?= h(formatSystemDate((string) ($event['event_date'] ?? ''), $pdo)) ?></td>
                    <td><?= (int) ($event['approved_count'] ?? 0) ?> / <?= (int) ($event['staff_needed'] ?? 0) ?></td>
                    <td><?= (int) ($event['coverage_gap'] ?? 0) ?></td>
                    <td><a href="event-hub.php?event_id=<?= (int) ($event['id'] ?? 0) ?>" class="btn btn--small btn--secondary">Hub</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($cc['upcoming_events'] === []): ?>
                <tr><td colspan="5">No upcoming active events.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card erp-card">
    <h3 class="card__title">Smart suggestions</h3>
    <?php foreach ($suggestions as $rec): ?>
    <div class="ai-rec-card ai-rec-card--<?= h((string) ($rec['priority'] ?? 'low')) ?>">
        <strong><?= h((string) ($rec['title'] ?? '')) ?></strong>
        <p style="margin:0.35rem 0;"><?= h((string) ($rec['detail'] ?? '')) ?></p>
        <?php if (!empty($rec['action'])): ?>
        <a href="<?= h((string) $rec['action']) ?>" class="btn btn--small btn--secondary"><?= h((string) ($rec['action_label'] ?? 'Open')) ?></a>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if ($suggestions === []): ?>
    <p>All clear — no suggestions right now.</p>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
