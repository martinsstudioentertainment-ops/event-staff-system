<?php



require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../includes/job-record-repository.php';

require_once __DIR__ . '/../includes/commission-invoice-quick.php';

require_once __DIR__ . '/../includes/commission-invoice-import.php';

require_once __DIR__ . '/../includes/company.php';



requireAdminCapability('invoices');



$id     = (int) ($_GET['id'] ?? 0);

$pdo    = getDB();

$record = $id > 0 ? getJobRecordById($pdo, $id) : null;



if ($record === null) {

    setAdminFlash('error', 'Job record not found.');

    header('Location: job-records.php');

    exit;

}



$isPersonal   = isPersonalJobRecord($record);

$printData    = jobRecordToPrintPayload($record);

$invoice      = $printData['invoice'];

$summaryLines = $printData['summary_lines'];

$payment      = getInvoicePaymentDetails($pdo);

$companyName  = getCompanyName($pdo);

$companyEmail = getCompanyEmail($pdo);

$companyPhone = getCompanyPhone($pdo);

$listUrl      = $isPersonal ? 'personal-invoices.php' : 'job-records.php';

$formUrl      = $isPersonal ? 'personal-invoice-form.php?id=' . $id : 'job-record-form.php?id=' . $id;

$title        = $isPersonal ? 'Invoice' : 'Commission Invoice';

$jobLabel     = $isPersonal ? 'Job' : 'Event / job';

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= h($title) ?> <?= h((string) $invoice['invoice_number']) ?></title>

    <link rel="stylesheet" href="../assets/css/admin.css">

    <style>

        body { background: #fff; color: #0f172a; padding: 2rem; font-family: system-ui, sans-serif; }

        .print-toolbar { margin-bottom: 1.5rem; display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }

        .invoice-print { max-width: 960px; margin: 0 auto; }

        .invoice-print__head { display: flex; justify-content: space-between; gap: 2rem; margin-bottom: 1.5rem; flex-wrap: wrap; }

        .invoice-print__title { font-size: 1.75rem; margin: 0 0 0.25rem; }

        .invoice-print__meta { color: #64748b; font-size: 0.9rem; }

        .invoice-print__totals { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 1rem; margin: 1.5rem 0; }

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

    <div class="print-toolbar no-print">

        <button type="button" class="btn btn--primary" onclick="window.print()">Print / Save as PDF</button>

        <a href="<?= h($formUrl) ?>" class="btn btn--secondary">← Edit</a>

        <a href="<?= h($listUrl) ?>" class="btn btn--secondary">← All invoices</a>

    </div>



    <article class="invoice-print">

        <header class="invoice-print__head">

            <div>

                <h1 class="invoice-print__title"><?= h($title) ?></h1>

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

                    <div><?= $isPersonal ? 'Bill to' : 'Client' ?>: <?= h((string) $invoice['client_name']) ?></div>

                <?php endif; ?>

            </div>

        </header>



        <p><strong><?= h($jobLabel) ?>:</strong> <?= h(formatQuickInvoiceEventLabel($invoice)) ?></p>

        <?php if ($invoice['venue'] !== ''): ?>

            <p><strong><?= $isPersonal ? 'Location' : 'Venue' ?>:</strong> <?= h((string) $invoice['venue']) ?></p>

        <?php endif; ?>



        <div class="invoice-print__totals">

            <?php if (!$isPersonal): ?>

                <div class="invoice-print__stat">Staff<strong><?= (int) $invoice['staff_count'] ?></strong></div>

            <?php endif; ?>

            <div class="invoice-print__stat">Hours<?= $isPersonal ? '' : ' worked' ?><strong><?= h(formatHoursDecimal((float) $invoice['total_hours_worked'])) ?></strong></div>

            <?php if (!$isPersonal): ?>

                <div class="invoice-print__stat">Hours billed<strong><?= h(formatHoursDecimal((float) $invoice['total_hours_billed'])) ?></strong></div>

            <?php endif; ?>

            <div class="invoice-print__stat">Total<strong><?= h(formatSystemCurrencyAmount((float) $invoice['total_amount'], $pdo)) ?></strong></div>

        </div>



        <table>

            <thead>

                <tr>

                    <th>Description</th>

                    <?php if (!$isPersonal): ?>

                        <th>Staff</th>

                    <?php endif; ?>

                    <th><?= $isPersonal ? 'Hours' : 'Total hours' ?></th>

                    <th>Rate</th>

                    <th>Amount</th>

                </tr>

            </thead>

            <tbody>

                <?php foreach ($summaryLines as $row): ?>
                    <?php
                    $lineDesc = (string) $row['description'];
                    if ($isPersonal && !empty($row['job_date'])) {
                        $lineDesc .= ' (' . formatSystemDate((string) $row['job_date'], $pdo) . ')';
                    }
                    if ($isPersonal && !empty($row['venue'])) {
                        $lineDesc .= ' — ' . (string) $row['venue'];
                    }
                    ?>
                    <tr>

                        <td><?= h($lineDesc) ?></td>

                        <?php if (!$isPersonal): ?>

                            <td><?= (int) $row['quantity'] ?></td>

                        <?php endif; ?>

                        <td><?= h(number_format((float) $row['hours'], 2)) ?></td>

                        <td><?= h(formatSystemCurrencyAmount((float) $row['rate'], $pdo)) ?></td>

                        <td><?= h(formatSystemCurrencyAmount((float) $row['amount'], $pdo)) ?></td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

            <tfoot>

                <tr>

                    <td colspan="<?= $isPersonal ? 1 : 2 ?>"><strong>Total</strong></td>

                    <td><?= h(number_format((float) $invoice['total_hours_billed'], 2)) ?></td>

                    <td></td>

                    <td><?= h(formatSystemCurrencyAmount((float) $invoice['total_amount'], $pdo)) ?></td>

                </tr>

            </tfoot>

        </table>



        <?php if ($invoice['notes'] !== ''): ?>

            <p style="margin-top:1.25rem;"><strong>Notes:</strong> <?= nl2br(h((string) $invoice['notes'])) ?></p>

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

