<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/workforce/staff-availability.php';
require_once __DIR__ . '/../includes/staff-repository.php';

requireAdminCapability('staff');

$pdo   = getDB();
$flash = getAdminFlash();
wf_ensure_availability_schema($pdo);

$month = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$staffId = (int) ($_GET['staff_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'set_availability') {
        $sid    = (int) ($_POST['staff_id'] ?? 0);
        $date   = trim((string) ($_POST['avail_date'] ?? ''));
        $status = trim((string) ($_POST['status'] ?? ''));
        $notes  = trim((string) ($_POST['notes'] ?? ''));
        $admin  = getAdminUser();
        $ok     = wf_set_staff_availability($pdo, $sid, $date, $status, $notes, true, $admin ? (int) $admin['id'] : null);
        setAdminFlash($ok ? 'success' : 'error', $ok ? 'Availability updated.' : 'Could not update availability.');
        header('Location: staff-availability.php?month=' . urlencode($month) . ($sid ? '&staff_id=' . $sid : ''));
        exit;
    }

    if ($action === 'review_request') {
        $reqId  = (int) ($_POST['request_id'] ?? 0);
        $approve = ($_POST['decision'] ?? '') === 'approve';
        $admin  = getAdminUser();
        $ok     = wf_review_availability_request($pdo, $reqId, $approve, $admin ? (int) $admin['id'] : null);
        if ($ok) {
            logAdminAudit($pdo, 'status_change', 'staff_availability', $reqId, $approve ? 'Leave/holiday approved' : 'Leave/holiday rejected');
        }
        setAdminFlash($ok ? 'success' : 'error', $ok ? 'Request reviewed.' : 'Could not review request.');
        header('Location: staff-availability.php?month=' . urlencode($month));
        exit;
    }
}

$monthStart = $month . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$entries    = wf_get_availability_range($pdo, $monthStart, $monthEnd, $staffId > 0 ? $staffId : null);
$pending    = wf_get_pending_availability_requests($pdo, 30);
$staffList  = getStaffWithFilters($pdo, [], 500, 0);

$byDate = [];
foreach ($entries as $entry) {
    $byDate[(string) ($entry['avail_date'] ?? '')][] = $entry;
}

$pageTitle  = 'Staff Availability';
$activePage = 'staff-availability';
$erpPageContentClass = 'wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">

<?php if ($flash): ?>
<div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div>
<?php endif; ?>

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Staff Availability System</h1>
        <p class="wf-hero__subtitle">Calendar-based availability — mark available/unavailable, request leave or holiday, approve or override.</p>
    </div>
    <form method="get" class="wf-toolbar">
        <input type="month" name="month" value="<?= h($month) ?>" class="input" onchange="this.form.submit()">
        <select name="staff_id" class="input" onchange="this.form.submit()">
            <option value="">All staff</option>
            <?php foreach ($staffList as $s): ?>
                <option value="<?= (int) ($s['id'] ?? 0) ?>" <?= $staffId === (int) ($s['id'] ?? 0) ? 'selected' : '' ?>>
                    <?= h(trim(((string) ($s['first_name'] ?? '')) . ' ' . ((string) ($s['surname'] ?? '')))) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if ($pending !== []): ?>
<section class="card erp-card wf-panel">
    <h2 class="wf-panel__title">Pending leave / holiday requests</h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Staff</th><th>Date</th><th>Type</th><th>Notes</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($pending as $req): ?>
                <tr>
                    <td><?= h(trim(((string) ($req['first_name'] ?? '')) . ' ' . ((string) ($req['surname'] ?? '')))) ?></td>
                    <td><?= h(formatSystemDate((string) ($req['avail_date'] ?? ''), $pdo)) ?></td>
                    <td><?= h(str_replace('_', ' ', (string) ($req['status'] ?? ''))) ?></td>
                    <td><?= h((string) ($req['notes'] ?? '')) ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="review_request">
                            <input type="hidden" name="request_id" value="<?= (int) ($req['id'] ?? 0) ?>">
                            <button type="submit" name="decision" value="approve" class="btn btn--primary">Approve</button>
                            <button type="submit" name="decision" value="reject" class="btn btn--secondary">Reject</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<section class="card erp-card">
    <h2 class="wf-panel__title">Set availability (management override)</h2>
    <form method="post" class="wf-filters">
        <input type="hidden" name="action" value="set_availability">
        <div>
            <label>Staff</label>
            <select name="staff_id" class="input" required>
                <?php foreach ($staffList as $s): ?>
                    <option value="<?= (int) ($s['id'] ?? 0) ?>"><?= h(trim(((string) ($s['first_name'] ?? '')) . ' ' . ((string) ($s['surname'] ?? '')))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label>Date</label><input type="date" name="avail_date" class="input" required value="<?= h(date('Y-m-d')) ?>"></div>
        <div>
            <label>Status</label>
            <select name="status" class="input">
                <?php foreach (['available', 'unavailable', 'leave_requested', 'holiday_requested', 'leave_approved', 'holiday_approved'] as $st): ?>
                    <option value="<?= h($st) ?>"><?= h(ucwords(str_replace('_', ' ', $st))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label>Notes</label><input type="text" name="notes" class="input" maxlength="500"></div>
        <div style="align-self:end;"><button type="submit" class="btn btn--primary">Save</button></div>
    </form>
</section>

<section class="card erp-card">
    <h2 class="wf-panel__title">Calendar — <?= h(date('F Y', strtotime($monthStart))) ?></h2>
    <div class="wf-cal-grid">
        <?php
        $daysInMonth = (int) date('t', strtotime($monthStart));
        for ($d = 1; $d <= $daysInMonth; $d++):
            $date = sprintf('%s-%02d', $month, $d);
            $dayEntries = $byDate[$date] ?? [];
            $class = '';
            if ($dayEntries !== []) {
                $st = (string) ($dayEntries[0]['status'] ?? '');
                $class = match (true) {
                    str_contains($st, 'requested') => 'pending',
                    in_array($st, ['unavailable'], true) => 'unavailable',
                    default => 'available',
                };
            }
        ?>
            <div class="wf-cal-day wf-cal-day--<?= h($class) ?>">
                <div class="wf-cal-day__num"><?= $d ?></div>
                <?php foreach ($dayEntries as $entry): ?>
                    <div><?= h(substr((string) ($entry['first_name'] ?? ''), 0, 1)) ?>. <?= h(substr((string) ($entry['surname'] ?? ''), 0, 8)) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endfor; ?>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
