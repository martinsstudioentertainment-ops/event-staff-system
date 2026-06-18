<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/automation/automation-schema.php';
require_once __DIR__ . '/../includes/automation/training-repository.php';
require_once __DIR__ . '/../includes/staff-repository.php';

requireAdminCapability('staff');

$pdo   = getDB();
$flash = getAdminFlash();
auto_ensure_schema($pdo);
training_refresh_expired($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? null)) {
    training_save_record(
        $pdo,
        (int) ($_POST['staff_id'] ?? 0),
        (string) ($_POST['course_key'] ?? 'custom'),
        (string) ($_POST['course_name'] ?? ''),
        (string) ($_POST['record_status'] ?? 'pending'),
        trim((string) ($_POST['completed_at'] ?? '')) ?: null,
        trim((string) ($_POST['expiry_date'] ?? '')) ?: null,
        trim((string) ($_POST['scheduled_date'] ?? '')) ?: null,
        (string) ($_POST['notes'] ?? '')
    );
    setAdminFlash('success', 'Training record saved.');
    header('Location: training-centre.php');
    exit;
}

$filter  = trim((string) ($_GET['status'] ?? ''));
$summary = training_summary($pdo);
$alerts  = training_alerts($pdo);
$records = training_list_records($pdo, $filter !== '' ? $filter : null, 150);
$staffList = getStaffWithFilters($pdo, [], 300, 0);

$pageTitle  = 'Training Management';
$activePage = 'training-centre';
$erpPageContentClass = 'auto-page wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">

<?php if ($flash): ?><div class="alert alert--<?= h($flash['type']) ?> alert--visible"><?= h($flash['message']) ?></div><?php endif; ?>

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Training Management</h1>
        <p class="wf-hero__subtitle">Induction, manual handling, customer service, safety, venue training, and custom courses.</p>
    </div>
</div>

<div class="wf-grid">
    <?php foreach (['completed', 'pending', 'expired', 'upcoming', 'scheduled'] as $st): ?>
        <a href="training-centre.php?status=<?= h($st) ?>" class="wf-metric" style="text-decoration:none;color:inherit;">
            <div class="wf-metric__value"><?= (int) ($summary[$st] ?? 0) ?></div>
            <div class="wf-metric__label"><?= h(ucfirst($st)) ?></div>
        </a>
    <?php endforeach; ?>
</div>

<div class="wf-grid">
    <div class="wf-metric wf-metric--amber"><div class="wf-metric__value"><?= count($alerts['expiring']) ?></div><div class="wf-metric__label">Training expiry (30d)</div></div>
    <div class="wf-metric"><div class="wf-metric__value"><?= count($alerts['due']) ?></div><div class="wf-metric__label">Training due</div></div>
    <div class="wf-metric wf-metric--red"><div class="wf-metric__value"><?= count($alerts['missing']) ?></div><div class="wf-metric__label">Missing induction</div></div>
</div>

<section class="card erp-card">
    <h2 class="wf-panel__title">Add training record</h2>
    <form method="post" class="wf-filters"><?= csrfField() ?>
        <div><label>Staff</label><select name="staff_id" class="input" required><?php foreach ($staffList as $s): ?><option value="<?= (int) $s['id'] ?>"><?= h(trim($s['first_name'] . ' ' . $s['surname'])) ?></option><?php endforeach; ?></select></div>
        <div><label>Course</label><select name="course_key" class="input"><?php foreach (training_course_catalog() as $c): ?><option value="<?= h($c['key']) ?>"><?= h($c['label']) ?></option><?php endforeach; ?></select></div>
        <div><label>Custom name</label><input name="course_name" class="input" placeholder="Optional override"></div>
        <div><label>Status</label><select name="record_status" class="input"><option value="pending">Pending</option><option value="completed">Completed</option><option value="upcoming">Upcoming</option><option value="scheduled">Scheduled</option></select></div>
        <div><label>Completed</label><input type="date" name="completed_at" class="input"></div>
        <div><label>Expiry</label><input type="date" name="expiry_date" class="input"></div>
        <div style="align-self:end;"><button class="btn btn--primary">Save</button></div>
    </form>
</section>

<section class="card erp-card">
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Staff</th><th>Course</th><th>Status</th><th>Completed</th><th>Expiry</th></tr></thead>
            <tbody>
            <?php foreach ($records as $r): ?>
                <tr>
                    <td><?= h(trim(($r['first_name'] ?? '') . ' ' . ($r['surname'] ?? ''))) ?></td>
                    <td><?= h((string) ($r['course_name'] ?? '')) ?></td>
                    <td><?= h(ucfirst((string) ($r['record_status'] ?? ''))) ?></td>
                    <td><?= ($r['completed_at'] ?? '') ? h(formatSystemDate((string) $r['completed_at'], $pdo)) : '—' ?></td>
                    <td><?= ($r['expiry_date'] ?? '') ? h(formatSystemDate((string) $r['expiry_date'], $pdo)) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($records === []): ?><tr><td colspan="5" class="data-table__empty">No training records.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
