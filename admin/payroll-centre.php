<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/system-settings.php';
require_once __DIR__ . '/../includes/automation/automation-schema.php';
require_once __DIR__ . '/../includes/automation/payroll-repository.php';

requireAdminCapability('export');

$pdo      = getDB();
auto_ensure_schema($pdo);
auto_ensure_phase67_schema($pdo);

$eventId  = (int) ($_GET['event_id'] ?? 0);
$workDate = trim((string) ($_GET['work_date'] ?? ''));
$events   = getEventsForFilter($pdo);
$rows     = payroll_build_preview($pdo, $eventId, $workDate);
$totals   = payroll_totals($rows);

if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payroll-preview-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Email', 'Role', 'Event', 'Date', 'Hours', 'Rate', 'Gross', 'Adjustments', 'Deductions', 'Net']);
    foreach ($rows as $r) {
        fputcsv($out, [
            trim(($r['first_name'] ?? '') . ' ' . ($r['surname'] ?? '')),
            $r['email'] ?? '',
            $r['staff_role'] ?? '',
            $r['event_name'] ?? '',
            $r['event_date'] ?? '',
            $r['hours_paid'] ?? $r['hours_worked'] ?? 0,
            $r['hourly_rate'] ?? 0,
            $r['gross_pay'] ?? 0,
            $r['adjustments'] ?? 0,
            $r['deductions'] ?? 0,
            $r['net_pay'] ?? 0,
        ]);
    }
    fputcsv($out, ['TOTALS', '', '', '', '', $totals['hours'], '', $totals['gross'], '', $totals['deductions'], $totals['net']]);
    fclose($out);
    exit;
}

$pageTitle  = 'Payroll Preparation Centre';
$activePage = 'payroll-centre';
$erpPageContentClass = 'auto-page wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Payroll Preparation Centre</h1>
        <p class="wf-hero__subtitle">Generate payroll from approved shifts, attendance, and hours worked. Export only — no payment processing.</p>
    </div>
    <a class="btn btn--primary" href="payroll-centre.php?export=csv&amp;event_id=<?= $eventId ?>&amp;work_date=<?= h(urlencode($workDate)) ?>">Export CSV</a>
    <a class="btn btn--secondary" href="payroll-centre.php?export=csv&amp;event_id=<?= $eventId ?>&amp;work_date=<?= h(urlencode($workDate)) ?>">Excel (via CSV)</a>
</div>

<section class="card erp-card">
    <form method="get" class="wf-filters">
        <div><label>Event</label><select name="event_id" class="input"><option value="0">All events</option><?php foreach ($events as $ev): ?><option value="<?= (int) $ev['id'] ?>" <?= $eventId === (int) $ev['id'] ? 'selected' : '' ?>><?= h($ev['name'] ?? '') ?></option><?php endforeach; ?></select></div>
        <div><label>Work date</label><input type="date" name="work_date" value="<?= h($workDate) ?>" class="input"></div>
        <div style="align-self:end;"><button class="btn btn--primary">Apply</button></div>
    </form>

    <div class="wf-grid">
        <div class="wf-metric"><div class="wf-metric__value"><?= h((string) $totals['hours']) ?></div><div class="wf-metric__label">Hours</div></div>
        <div class="wf-metric wf-metric--green"><div class="wf-metric__value"><?= h(formatSystemCurrencyAmount((float) $totals['gross'], $pdo)) ?></div><div class="wf-metric__label">Gross pay</div></div>
        <div class="wf-metric"><div class="wf-metric__value"><?= h(formatSystemCurrencyAmount((float) $totals['deductions'], $pdo)) ?></div><div class="wf-metric__label">Deductions</div></div>
        <div class="wf-metric"><div class="wf-metric__value"><?= h(formatSystemCurrencyAmount((float) $totals['net'], $pdo)) ?></div><div class="wf-metric__label">Net pay</div></div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Staff</th><th>Event</th><th>Hours</th><th>Rate</th><th>Gross</th><th>Adjustments</th><th>Net</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= h(trim(($r['first_name'] ?? '') . ' ' . ($r['surname'] ?? ''))) ?></td>
                    <td><?= h((string) ($r['event_name'] ?? '')) ?></td>
                    <td><?= h((string) ($r['hours_paid'] ?? $r['hours_worked'] ?? 0)) ?></td>
                    <td><?= h(formatSystemCurrencyAmount((float) ($r['hourly_rate'] ?? 0), $pdo)) ?></td>
                    <td><?= h(formatSystemCurrencyAmount((float) ($r['gross_pay'] ?? 0), $pdo)) ?></td>
                    <td><?= h(formatSystemCurrencyAmount((float) ($r['adjustments'] ?? 0), $pdo)) ?></td>
                    <td><?= h(formatSystemCurrencyAmount((float) ($r['net_pay'] ?? 0), $pdo)) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?><tr><td colspan="7" class="data-table__empty">No payable hours for this filter. Set hourly rates in staff rate cards or invoice lines.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="card__subtitle">Excel: open CSV in Excel. Payroll summary: use totals above or <a href="export-work-hours.php">work hours export</a>.</p>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
