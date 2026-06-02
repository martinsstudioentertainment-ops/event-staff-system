<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/commission-invoice-repository.php';

requireAdminCapability('invoices');

$pdo     = getDB();
$eventId = (int) ($_GET['event_id'] ?? 0);
$status  = trim((string) ($_GET['status'] ?? ''));
$month   = trim((string) ($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

$list   = getCommissionInvoicesList($pdo, $eventId, $status, $month);
$totals = getCommissionInvoiceAggregates($pdo, $eventId, $status, $month);
$label  = $totals['month_label'] !== '' ? $totals['month_label'] : date('F Y', strtotime($month . '-01'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly commission summary — <?= h($label) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        body { background: #fff; color: #0f172a; padding: 2rem; font-family: system-ui, sans-serif; }
        .print-toolbar { margin-bottom: 1.5rem; }
        .summary-print { max-width: 960px; margin: 0 auto; }
        .summary-print__totals { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin: 1.5rem 0; }
        .summary-print__stat { border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.875rem; }
        .summary-print__stat strong { display: block; font-size: 1.2rem; margin-top: 0.25rem; }
        .summary-print__grand { font-size: 1.5rem; font-weight: 700; margin: 1rem 0 1.5rem; padding: 1rem; background: #f1f5f9; border-radius: 10px; }
        table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        th, td { border: 1px solid #e2e8f0; padding: 0.5rem 0.625rem; text-align: left; }
        th { background: #f8fafc; }
        tfoot td { font-weight: 700; background: #f1f5f9; }
        @media print { .print-toolbar { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="print-toolbar">
        <button type="button" class="btn btn--primary" onclick="window.print()">Print</button>
        <a href="invoices.php?month=<?= h(urlencode($month)) ?>" class="btn btn--secondary">← Invoices</a>
    </div>

    <article class="summary-print">
        <h1>Monthly commission summary</h1>
        <p><?= h($label) ?><?= $status !== '' ? ' · Status: ' . h(getCommissionInvoiceStatusOptions()[$status] ?? $status) : '' ?></p>

        <div class="summary-print__totals">
            <div class="summary-print__stat">Invoices<strong><?= (int) $totals['invoice_count'] ?></strong></div>
            <div class="summary-print__stat">Staff shifts<strong><?= (int) $totals['staff_count'] ?></strong></div>
            <div class="summary-print__stat">Hours billed<strong><?= h(formatHoursDecimal($totals['total_hours_billed'])) ?></strong></div>
        </div>

        <p class="summary-print__grand">Total commission for <?= h($label) ?>: <?= h(formatSystemCurrencyAmount($totals['total_amount'], $pdo)) ?></p>

        <table>
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Event</th>
                    <th>Invoice date</th>
                    <th>Staff</th>
                    <th>Hours billed</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($list === []): ?>
                    <tr><td colspan="7">No invoices this month.</td></tr>
                <?php else: ?>
                    <?php foreach ($list as $row): ?>
                        <tr>
                            <td><?= h((string) $row['invoice_number']) ?></td>
                            <td><?= h((string) $row['event_name']) ?></td>
                            <td><?= h(formatSystemDate((string) $row['invoice_date'], $pdo)) ?></td>
                            <td><?= (int) $row['staff_count'] ?></td>
                            <td><?= h(formatHoursDecimal((float) $row['total_hours_billed'])) ?></td>
                            <td><?= h(formatSystemCurrencyAmount((float) $row['total_amount'], $pdo)) ?></td>
                            <td><?= h(getCommissionInvoiceStatusOptions()[(string) $row['status']] ?? $row['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if ($list !== []): ?>
            <tfoot>
                <tr>
                    <td colspan="3">Month total</td>
                    <td><?= (int) $totals['staff_count'] ?></td>
                    <td><?= h(formatHoursDecimal($totals['total_hours_billed'])) ?></td>
                    <td><?= h(formatSystemCurrencyAmount($totals['total_amount'], $pdo)) ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </article>
</body>
</html>
