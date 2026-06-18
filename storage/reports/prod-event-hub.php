<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/platform/platform-schema.php';
require_once __DIR__ . '/../includes/platform/event-hub.php';

requireAdminCapability('events');
requirePlatformFeature(getDB(), 'event_hub', 'Event Hub');

$pdo     = getDB();
$flash   = getAdminFlash();
$eventId = (int) ($_GET['event_id'] ?? 0);
$events  = listEventsForHubPicker($pdo, 40);
$hub     = $eventId > 0 ? getEventHubSnapshot($pdo, $eventId) : null;

if ($hub === null && $events !== []) {
    $eventId = (int) ($events[0]['id'] ?? 0);
    $hub     = getEventHubSnapshot($pdo, $eventId);
}

$pageTitle  = 'Event Hub';
$activePage = 'event-hub';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">

<?php if ($flash): ?>
<div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<section class="card erp-card">
    <div class="card__header card__header--row">
        <div>
            <h2 class="card__title">Event operations hub</h2>
            <p class="card__subtitle">Single-screen control for staffing, check-ins, GPS, hours, and sheets.</p>
        </div>
        <form method="get">
            <select name="event_id" class="form-select" onchange="this.form.submit()">
                <?php foreach ($events as $ev): ?>
                <option value="<?= (int) $ev['id'] ?>"<?= (int) $ev['id'] === $eventId ? ' selected' : '' ?>>
                    <?= h((string) ($ev['name'] ?? '')) ?> — <?= h(formatSystemDate((string) ($ev['event_date'] ?? ''), $pdo)) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if ($hub !== null): ?>
  <?php $ev = $hub['event']; ?>
    <div class="platform-ops-grid">
        <div class="platform-ops-metric"><div class="platform-ops-metric__value"><?= (int) $hub['assigned_count'] ?> / <?= (int) $hub['staff_needed'] ?></div><div class="platform-ops-metric__label">Staff assigned / needed</div></div>
        <div class="platform-ops-metric<?= (int) $hub['coverage_gap'] > 0 ? ' platform-ops-metric--warn' : ' platform-ops-metric--ok' ?>"><div class="platform-ops-metric__value"><?= (int) $hub['coverage_gap'] ?></div><div class="platform-ops-metric__label">Coverage gap</div></div>
        <div class="platform-ops-metric platform-ops-metric--warn"><div class="platform-ops-metric__value"><?= (int) $hub['pending_count'] ?></div><div class="platform-ops-metric__label">Pending applications</div></div>
        <div class="platform-ops-metric platform-ops-metric--ok"><div class="platform-ops-metric__value"><?= (int) $hub['checkins'] ?></div><div class="platform-ops-metric__label">Check-ins</div></div>
        <div class="platform-ops-metric<?= (int) $hub['gps_issues'] > 0 ? ' platform-ops-metric--danger' : '' ?>"><div class="platform-ops-metric__value"><?= (int) $hub['gps_issues'] ?></div><div class="platform-ops-metric__label">GPS / attendance issues</div></div>
        <div class="platform-ops-metric"><div class="platform-ops-metric__value"><?= number_format((float) $hub['work_hours'], 1) ?>h</div><div class="platform-ops-metric__label">Work hours logged</div></div>
        <div class="platform-ops-metric"><div class="platform-ops-metric__value"><?= !empty($hub['sheet_linked']) ? 'Linked' : 'None' ?></div><div class="platform-ops-metric__label">Google Sheet</div></div>
    </div>

    <div class="toolbar">
        <a href="staff.php?event_id=<?= $eventId ?>&status=pending" class="btn btn--primary btn--small">Pending queue</a>
        <a href="attendance.php" class="btn btn--secondary btn--small">Attendance</a>
        <a href="scan-checkin.php" class="btn btn--secondary btn--small">Scan check-in</a>
        <a href="event-form.php?id=<?= $eventId ?>" class="btn btn--secondary btn--small">Edit event</a>
        <?php if (!empty($hub['sheet_url'])): ?>
        <a href="<?= h((string) $hub['sheet_url']) ?>" target="_blank" rel="noopener" class="btn btn--secondary btn--small">Open sheet</a>
        <?php endif; ?>
    </div>

    <h3 class="form-section-title" style="margin-top:1.25rem;">Assigned staff</h3>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Check-in / GPS</th></tr></thead>
            <tbody>
            <?php foreach ($hub['assigned_staff'] as $s): ?>
                <tr>
                    <td><a href="view-staff.php?id=<?= (int) ($s['id'] ?? 0) ?>"><?= h(trim(($s['first_name'] ?? '') . ' ' . ($s['surname'] ?? ''))) ?></a></td>
                    <td><?= h((string) ($s['email'] ?? '')) ?></td>
                    <td><?= h((string) ($s['staff_role'] ?? '')) ?></td>
                    <td>
                        <?php if (!empty($s['checked_in_at'])): ?>
                            <?= h(formatSystemDateTime((string) $s['checked_in_at'], $pdo)) ?>
                            <?php if (!empty($s['attendance_status'])): ?>
                                <span class="badge badge--small"><?= h((string) $s['attendance_status']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($hub['assigned_staff'] === []): ?>
                <tr><td colspan="4">No approved staff yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($hub['payroll_alerts'] !== []): ?>
    <h3 class="form-section-title" style="margin-top:1.25rem;">Hours alerts</h3>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Title</th><th>Detail</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($hub['payroll_alerts'] as $alert): ?>
                <tr>
                    <td><?= h((string) ($alert['title'] ?? '')) ?></td>
                    <td><?= h((string) ($alert['body'] ?? '')) ?></td>
                    <td><?= h(formatSystemDateTime((string) ($alert['created_at'] ?? ''), $pdo)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($hub['sheet_sync_log'] !== []): ?>
    <h3 class="form-section-title" style="margin-top:1.25rem;">Sheet sync log</h3>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>When</th><th>Action</th><th>Status</th><th>Detail</th></tr></thead>
            <tbody>
            <?php foreach ($hub['sheet_sync_log'] as $log): ?>
                <tr>
                    <td><?= h(formatSystemDateTime((string) ($log['created_at'] ?? ''), $pdo)) ?></td>
                    <td><?= h((string) ($log['action'] ?? '')) ?></td>
                    <td><?= h((string) ($log['status'] ?? '')) ?></td>
                    <td><?= h(mb_strimwidth((string) ($log['detail'] ?? ''), 0, 80, '…')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <p>No active events found.</p>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
