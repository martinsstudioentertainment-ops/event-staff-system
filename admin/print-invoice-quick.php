<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/commission-invoice-quick.php';
require_once __DIR__ . '/../includes/company.php';
require_once __DIR__ . '/../includes/events-repository.php';

requireAdminCapability('invoices');

if (!in_array(getAdminRole(), ['admin', 'manager'], true)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: invoice-quick.php');
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body><p>Invalid request.</p><p><a href="invoice-quick.php">← Back</a></p></body></html>';
    exit;
}

$pdo    = getDB();
$parsed = parseQuickCommissionInvoiceFromPost($_POST);

if (!$parsed['ok']) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Quick invoice error</title>';
    echo '<link rel="stylesheet" href="../assets/css/admin.css"></head><body style="padding:2rem;">';
    echo '<div class="alert alert--error alert--visible"><ul style="margin:0;padding-left:1.25rem;">';
    foreach ($parsed['errors'] as $error) {
        echo '<li>' . h($error) . '</li>';
    }
    echo '</ul></div><p><a href="invoice-quick.php" class="btn btn--secondary">← Fix and try again</a></p></body></html>';
    exit;
}

$invoice      = $parsed['invoice'];
$summaryLines = $parsed['summary_lines'];
$payment      = getInvoicePaymentDetails($pdo);
$companyName  = getCompanyName($pdo);
$companyEmail = getCompanyEmail($pdo);
$companyPhone = getCompanyPhone($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quick Invoice <?= h((string) $invoice['invoice_number']) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        body { background: #fff; color: #0f172a; padding: 2rem; font-family: system-ui, sans-serif; }
        .print-toolbar { margin-bottom: 1.5rem; display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .invoice-print { max-width: 960px; margin: 0 auto; }
        .invoice-print__head { display: flex; justify-content: space-between; gap: 2rem; margin-bottom: 1.5rem; }
        .invoice-print__title { font-size: 1.75rem; margin: 0 0 0.25rem; }
        .invoice-print__meta { color: #64748b; font-size: 0.9rem; }
        .invoice-print__badge { display: inline-block; margin-bottom: 0.75rem; padding: 0.25rem 0.625rem; border-radius: 999px; background: #fef3c7; color: #92400e; font-size: 0.75rem; font-weight: 600; }
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
        @media print { .print-toolbar, .invoice-print__badge { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="print-toolbar no-print">
        <button type="button" class="btn btn--primary" onclick="window.print()">Print / Save as PDF</button>
        <a href="invoice-quick.php" class="btn btn--secondary">← New quick invoice</a>
        <a href="invoices.php" class="btn btn--secondary">All invoices</a>
    </div>

    <article class="invoice-print">
        <p class="invoice-print__badge no-print">Print only — not saved to the system</p>

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
                <?php if ($invoice['client_name'] !== ''): ?>
                    <div>Client: <?= h((string) $invoice['client_name']) ?></div>
                <?php endif; ?>
            </div>
        </header>

        <p><strong>Event:</strong> <?= h(formatQuickInvoiceEventLabel($invoice)) ?></p>
        <?php if ($invoice['venue'] !== ''): ?>
            <p><strong>Venue:</strong> <?= h((string) $invoice['venue']) ?></p>
        <?php endif; ?>

        <div class="invoice-print__totals">
            <div class="invoice-print__stat">Staff<strong><?= (int) $invoice['staff_count'] ?></strong></div>
            <div class="invoice-print__stat">Hours worked<strong><?= h(formatHoursDecimal((float) $invoice['total_hours_worked'])) ?></strong></div>
            <div class="invoice-print__stat">Hours billed<strong><?= h(formatHoursDecimal((float) $invoice['total_hours_billed'])) ?></strong></div>
            <div class="invoice-print__stat">Total<strong><?= h(formatSystemCurrencyAmount((float) $invoice['total_amount'], $pdo)) ?></strong></div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Staff</th>
                    <th>Total hours</th>
                    <th>Rate</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summaryLines as $row): ?>
                    <tr>
                        <td><?= h((string) $row['description']) ?></td>
                        <td><?= (int) $row['quantity'] ?></td>
                        <td><?= h(number_format((float) $row['hours'], 2)) ?></td>
                        <td><?= h(formatSystemCurrencyAmount((float) $row['rate'], $pdo)) ?></td>
                        <td><?= h(formatSystemCurrencyAmount((float) $row['amount'], $pdo)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2"><strong>Total</strong></td>
                    <td><?= h(number_format((float) $invoice['total_hours_billed'], 2)) ?></td>
                    <td></td>
                    <td><?= h(formatSystemCurrencyAmount((float) $invoice['total_amount'], $pdo)) ?></td>
                </tr>
            </tfoot>
        </table>

        <?php if ($invoice['notes'] !== ''): ?>
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
