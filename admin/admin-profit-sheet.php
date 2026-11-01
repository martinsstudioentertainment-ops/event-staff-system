<?php

declare(strict_types=1);

/**
 * Admin-only profit sheet: staff rate vs contractor rate.
 * Confidential — never shown to staff.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/system-settings.php';
require_once __DIR__ . '/../includes/admin-confidential-pay.php';

requireAdminCapability('export');

$pdo = getDB();
ensureAdminConfidentialPaySchema($pdo);

$eventId = (int) ($_GET['event_id'] ?? 0);
$events = getEventsForFilter($pdo);

// Default to Palmerstown Fri if no event chosen and it exists.
if ($eventId < 1) {
    foreach ($events as $ev) {
        if ((int) ($ev['id'] ?? 0) === 49) {
            $eventId = 49;
            break;
        }
    }
}

$rows = $eventId > 0 ? buildAdminProfitSheetRows($pdo, $eventId) : [];
$totals = adminProfitSheetTotals($rows);
$missingRates = count(array_filter($rows, static fn (array $r): bool => empty($r['has_rate'])));

if (!empty($_GET['export']) && $_GET['export'] === 'csv' && $eventId > 0) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="admin-profit-sheet-event-' . $eventId . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Name', 'Mobile', 'Role', 'Event date', 'Hours', 'Hours source',
        'Staff rate (€/hr)', 'Staff pay (€)', 'Contractor rate (€/hr)', 'Contractor paid (€)', 'Profit (€)',
        'Signed in', 'Signed out',
    ]);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['name'],
            $r['mobile'],
            $r['role'],
            $r['event_date'],
            $r['hours'],
            $r['hours_source'],
            $r['staff_hourly_rate'],
            $r['staff_pay'],
            $r['contractor_hourly_rate'],
            $r['contractor_paid'],
            $r['profit'],
            $r['checked_in_at'] ?? '',
            $r['checked_out_at'] ?? '',
        ]);
    }
    fputcsv($out, [
        'TOTALS', '', '', '', $totals['hours'], '', '', $totals['staff_pay'], '', $totals['contractor_paid'], $totals['profit'], '', '',
    ]);
    fclose($out);
    exit;
}

$pageTitle = 'Admin profit sheet';
$activePage = 'admin-profit-sheet';
$erpPageContentClass = 'auto-page wf-page';

include __DIR__ . '/../includes/admin/layout-top.php';
?>
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/platform-ops.css">
<link rel="stylesheet" href="<?= h($assetBase) ?>assets/css/workforce-suite.css">

<div class="wf-hero">
    <div>
        <h1 class="wf-hero__title">Admin profit sheet</h1>
        <p class="wf-hero__subtitle">Confidential admin only — staff rates and profit are never shown to lads or in staff emails.</p>
    </div>
    <?php if ($eventId > 0): ?>
        <a class="btn btn--primary" href="admin-profit-sheet.php?export=csv&amp;event_id=<?= (int) $eventId ?>">Export CSV</a>
    <?php endif; ?>
</div>

<section class="card erp-card">
    <div class="alert alert--warning alert--visible" style="margin-bottom:1rem;">
        <strong>Confidential.</strong> Different rates per lad. Do not share this page, CSV, or screenshots with staff.
    </div>

    <form method="get" class="wf-filters">
        <div>
            <label>Event</label>
            <select name="event_id" class="input" onchange="this.form.submit()">
                <option value="0">Select event…</option>
                <?php foreach ($events as $ev): ?>
                    <option value="<?= (int) $ev['id'] ?>"<?= $eventId === (int) $ev['id'] ? ' selected' : '' ?>>
                        <?= h((string) ($ev['name'] ?? '')) ?> — <?= h(substr((string) ($ev['event_date'] ?? ''), 0, 10)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="align-self:end;">
            <a class="btn btn--secondary" href="admin-profit-sheet.php?event_id=49">Fri Palmerstown</a>
            <a class="btn btn--secondary" href="admin-profit-sheet.php?event_id=50">Sat Palmerstown</a>
        </div>
    </form>

    <?php if ($eventId > 0): ?>
        <div class="wf-grid" style="margin:1rem 0;">
            <div class="wf-metric"><div class="wf-metric__value"><?= h((string) $totals['hours']) ?></div><div class="wf-metric__label">Hours</div></div>
            <div class="wf-metric"><div class="wf-metric__value"><?= h(formatSystemCurrencyAmount($totals['staff_pay'], $pdo)) ?></div><div class="wf-metric__label">Staff pay (out)</div></div>
            <div class="wf-metric"><div class="wf-metric__value"><?= h(formatSystemCurrencyAmount($totals['contractor_paid'], $pdo)) ?></div><div class="wf-metric__label">Contractor in</div></div>
            <div class="wf-metric wf-metric--green"><div class="wf-metric__value"><?= h(formatSystemCurrencyAmount($totals['profit'], $pdo)) ?></div><div class="wf-metric__label">Profit</div></div>
        </div>

        <?php if ($missingRates > 0): ?>
            <p class="form-hint" style="color:#9a5b00;"><?= (int) $missingRates ?> lad(s) still need a confidential rate set.</p>
        <?php endif; ?>

        <p class="form-hint">Hours use <strong>actual</strong> paid/worked hours after gate sign-out when available; otherwise planned shift length. Profit = (contractor €/hr × hours) − (staff €/hr × hours).</p>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Mobile</th>
                        <th>Hours</th>
                        <th>Staff €/hr</th>
                        <th>Staff pay</th>
                        <th>Contractor €/hr</th>
                        <th>Contractor</th>
                        <th>Profit</th>
                        <th>Sign-in / out</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h((string) $r['name']) ?><?= empty($r['has_rate']) ? ' <span class="badge">no rate</span>' : '' ?></td>
                        <td><?= h((string) $r['mobile']) ?></td>
                        <td><?= h((string) $r['hours']) ?> <span class="form-hint">(<?= h((string) $r['hours_source']) ?>)</span></td>
                        <td><?= h(formatSystemCurrencyAmount((float) $r['staff_hourly_rate'], $pdo)) ?></td>
                        <td><?= h(formatSystemCurrencyAmount((float) $r['staff_pay'], $pdo)) ?></td>
                        <td><?= h(formatSystemCurrencyAmount((float) $r['contractor_hourly_rate'], $pdo)) ?></td>
                        <td><?= h(formatSystemCurrencyAmount((float) $r['contractor_paid'], $pdo)) ?></td>
                        <td><strong><?= h(formatSystemCurrencyAmount((float) $r['profit'], $pdo)) ?></strong></td>
                        <td class="form-hint">
                            <?= !empty($r['checked_in_at']) ? h((string) $r['checked_in_at']) : '—' ?>
                            /
                            <?= !empty($r['checked_out_at']) ? h((string) $r['checked_out_at']) : '—' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="9" class="data-table__empty">No approved staff on this event.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/admin/layout-bottom.php'; ?>
