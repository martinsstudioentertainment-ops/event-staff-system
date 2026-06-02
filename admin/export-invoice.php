<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/commission-invoice-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';

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

logAdminAudit($pdo, 'export_commission_invoice', 'commission_invoice', $invoiceId, (string) $invoice['invoice_number']);

$filename = 'commission-invoice-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $invoice['invoice_number']) . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($output, ['Commission invoice', (string) $invoice['invoice_number']]);
fputcsv($output, ['Event ID', (int) $invoice['event_id']]);
fputcsv($output, ['Invoice date', (string) $invoice['invoice_date']]);
fputcsv($output, ['Client', (string) ($invoice['client_name'] ?? '')]);
fputcsv($output, ['Status', (string) $invoice['status']]);
fputcsv($output, ['Staff count', (int) $invoice['staff_count']]);
fputcsv($output, ['Total hours worked', (float) $invoice['total_hours_worked']]);
fputcsv($output, ['Total hours billed', (float) $invoice['total_hours_billed']]);
fputcsv($output, ['Total amount', (float) $invoice['total_amount'], (string) $invoice['currency']]);
fputcsv($output, []);

fputcsv($output, [
    'Staff', 'Role', 'Hours worked', 'Hours billed', 'Rate per hour', 'Line amount', 'Amount override', 'Note',
]);

foreach ($lines as $line) {
    fputcsv($output, [
        $line['staff_name'],
        formatRoleLabel((string) ($line['staff_role'] ?? '')),
        $line['hours_worked'],
        $line['hours_billed'],
        $line['hourly_rate'],
        $line['line_amount'],
        !empty($line['amount_override']) ? 'yes' : 'no',
        $line['note'] ?? '',
    ]);
}

fputcsv($output, []);
fputcsv($output, [
    'EVENT TOTALS', '', $invoice['total_hours_worked'], $invoice['total_hours_billed'], '', $invoice['total_amount'], '', '',
]);

fclose($output);
exit;
