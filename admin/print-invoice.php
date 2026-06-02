<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/commission-invoice-repository.php';
require_once __DIR__ . '/../includes/commission-invoice-import.php';
require_once __DIR__ . '/../includes/events-repository.php';
require_once __DIR__ . '/../includes/company.php';

requireAdminCapability('invoices');

$pdo       = getDB();
$invoiceId = (int) ($_GET['id'] ?? 0);
$invoice   = $invoiceId > 0 ? getCommissionInvoiceById($pdo, $invoiceId) : null;

if (!$invoice) {
    setAdminFlash('error', 'Invoice not found.');
    header('Location: invoices.php');
    exit;
}

$lines = getCommissionInvoiceLines($pdo, $invoiceId);
$event = getEventById($pdo, (int) $invoice['event_id']);
$payment = getInvoicePaymentDetails($pdo);
$companyName = getCompanyName($pdo);
$companyEmail = getCompanyEmail($pdo);
$companyPhone = getCompanyPhone($pdo);
$printLayout = normalizeCommissionInvoicePrintLayout((string) ($invoice['print_layout'] ?? 'detailed'));
$summaryLines = buildCommissionInvoiceSummaryPrintLines($invoice);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commission Invoice <?= h((string) $invoice['invoice_number']) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        body { background: #fff; color: #0f172a; padding: 2rem; font-family: system-ui, sans-serif; }
        .print-toolbar { margin-bottom: 1.5rem; }
        .invoice-print { max-width: 960px; margin: 0 auto; }
        .invoice-print__head { display: flex; justify-content: space-between; gap: 2rem; margin-bottom: 1.5rem; }
        .invoice-print__title { font-size: 1.75rem; margin: 0 0 0.25rem; }
        .invoice-print__meta { color: #64748b; font-size: 0.9rem; }
        .invoice-print__totals { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin: 1.5rem 0; }
        .invoice-print__stat { border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.875rem; }
        .invoice-print__stat strong { display: block; font-size: 1.25rem; margin-top: 0.25rem; }
        table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        th, td { border: 1px solid #e2e8f0; padding: 0.5rem 0.625rem; text-align: left; }
        th { background: #f8fafc; }
        tfoot td { font-weight: 700; background: #f1f5f9; }
        .invoice-print__from { font-size: 0.875rem; line-height: 1.5; margin-bottom: 0.5rem; }
        .invoice-print__payment { margin-top: 1.5rem; padding: 1rem; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 0.875rem; }
        .invoice-print__payment h2 { font-size: 1rem; margin: 0 0 0.5rem; }
        @media print { .print-toolbar { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="print-toolbar">
        <button type="button" class="btn btn--primary" onclick="window.print()">Print invoice</button>
        <a href="invoice-form.php?id=<?= (int) $invoiceId ?>" class="btn btn--secondary">← Edit</a>
    </div>

    <article class="invoice-print">
        <header class="invoice-print__head">
            <div>
                <h1 class="invoice-print__title">Commission Invoice</h1>
                <p class="invoice-print__meta"><?= h((string) $invoice['invoice_number']) ?></p>
                <div class="invoice-print__from">
                    <strong><?= h($companyName) ?></strong><br>
                    <?php if ($companyEmail !== ''): ?>Email: <?= h($companyEmail) ?><br><?php endif; ?>
                    <?php if ($companyPhone !== ''): ?>Phone: <?= h($companyPhone) ?><?php endif; ?>
                </div>
            </div>
            <div class="invoice-print__meta" style="text-align:right;">
                <div>Date: <?= h(formatSystemDate((string) $invoice['invoice_date'], $pdo)) ?></div>
                <div>Status: <?= h(getCommissionInvoiceStatusOptions()[(string) $invoice['status']] ?? $invoice['status']) ?></div>
                <?php if (!empty($invoice['client_name'])): ?>
                    <div>Client: <?= h((string) $invoice['client_name']) ?></div>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($event): ?>
            <p><strong>Event:</strong> <?= h($event['name']) ?> · <?= h(formatEventDateLabel((string) $event['event_date'])) ?> · <?= h(formatEventTimeRangeLabel($event)) ?></p>
            <?php if (!empty($event['location'])): ?>
                <p><strong>Venue:</strong> <?= h((string) $event['location']) ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <div class="invoice-print__totals">
            <div class="invoice-print__stat">Staff<strong><?= (int) $invoice['staff_count'] ?></strong></div>
            <div class="invoice-print__stat">Hours worked<strong><?= h(formatHoursDecimal((float) $invoice['total_hours_worked'])) ?></strong></div>
            <div class="invoice-print__stat">Hours billed<strong><?= h(formatHoursDecimal((float) $invoice['total_hours_billed'])) ?></strong></div>
            <div class="invoice-print__stat">Total<strong><?= h(formatSystemCurrencyAmount((float) $invoice['total_amount'], $pdo)) ?></strong></div>
        </div>

        <table>
            <thead>
                <?php if ($printLayout === 'summary'): ?>
                <tr>
                    <th>Description</th>
                    <th>Staff</th>
                    <th>Total hours</th>
                    <th>Rate</th>
                    <th>Amount</th>
                </tr>
                <?php else: ?>
                <tr>
                    <th>Staff</th>
                    <th>Role</th>
                    <th>Hrs worked</th>
                    <th>Hrs billed</th>
                    <th>Rate</th>
                    <th>Amount</th>
                    <th>Note</th>
                </tr>
                <?php endif; ?>
            </thead>
            <tbody>
                <?php if ($printLayout === 'summary'): ?>
                    <?php foreach ($summaryLines as $row): ?>
                        <tr>
                            <td><?= h((string) $row['description']) ?></td>
                            <td><?= (int) $row['quantity'] ?></td>
                            <td><?= h(number_format((float) $row['hours'], 2)) ?></td>
                            <td><?= h(formatSystemCurrencyAmount((float) $row['rate'], $pdo)) ?></td>
                            <td><?= h(formatSystemCurrencyAmount((float) $row['amount'], $pdo)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach ($lines as $line): ?>
                        <tr>
                            <td><?= h((string) $line['staff_name']) ?></td>
                            <td><?= h(formatRoleLabel((string) ($line['staff_role'] ?? ''))) ?></td>
                            <td><?= h(number_format((float) $line['hours_worked'], 2)) ?></td>
                            <td><?= h(number_format((float) $line['hours_billed'], 2)) ?></td>
                            <td><?= h(formatSystemCurrencyAmount((float) $line['hourly_rate'], $pdo)) ?></td>
                            <td><?= h(formatSystemCurrencyAmount((float) $line['line_amount'], $pdo)) ?></td>
                            <td><?= h((string) ($line['note'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <?php if ($printLayout === 'summary'): ?>
                <tr>
                    <td colspan="2"><strong>Event totals</strong></td>
                    <td><?= h(number_format((float) $invoice['total_hours_billed'], 2)) ?></td>
                    <td></td>
                    <td><?= h(formatSystemCurrencyAmount((float) $invoice['total_amount'], $pdo)) ?></td>
                </tr>
                <?php else: ?>
                <tr>
                    <td colspan="2"><strong>Event totals</strong></td>
                    <td><?= h(number_format((float) $invoice['total_hours_worked'], 2)) ?></td>
                    <td><?= h(number_format((float) $invoice['total_hours_billed'], 2)) ?></td>
                    <td></td>
                    <td><?= h(formatSystemCurrencyAmount((float) $invoice['total_amount'], $pdo)) ?></td>
                    <td></td>
                </tr>
                <?php endif; ?>
            </tfoot>
        </table>

        <?php if ($printLayout === 'summary'): ?>
            <p class="invoice-print__meta" style="margin-top:0.75rem;">Individual staff names are kept in admin records only — not shown on this client copy.</p>
        <?php endif; ?>

        <?php if (!empty($invoice['notes'])): ?>
            <p style="margin-top:1.25rem;"><strong>Notes:</strong> <?= h((string) $invoice['notes']) ?></p>
        <?php endif; ?>

        <?php if ($payment['iban'] !== '' || $payment['bank_name'] !== '' || $payment['bic'] !== '' || $payment['vat_number'] !== ''): ?>
            <section class="invoice-print__payment">
                <h2>Payment details</h2>
                <?php if ($payment['bank_name'] !== ''): ?><div><strong>Account:</strong> <?= h($payment['bank_name']) ?></div><?php endif; ?>
                <?php if ($payment['iban'] !== ''): ?><div><strong>IBAN:</strong> <?= h($payment['iban']) ?></div><?php endif; ?>
                <?php if ($payment['bic'] !== ''): ?><div><strong>BIC:</strong> <?= h($payment['bic']) ?></div><?php endif; ?>
                <?php if ($payment['vat_number'] !== ''): ?><div><strong>VAT:</strong> <?= h($payment['vat_number']) ?></div><?php endif; ?>
                <div style="margin-top:0.5rem;color:#64748b;">Reference: <?= h((string) $invoice['invoice_number']) ?></div>
            </section>
        <?php endif; ?>
    </article>
</body>
</html>
