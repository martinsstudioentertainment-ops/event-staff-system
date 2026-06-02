<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/commission-invoice-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';

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

logAdminAudit($pdo, 'export_commission_invoices_month', 'commission_invoice', null, $label . ' — ' . count($list) . ' invoices');

$filename = 'commission-invoices-' . $month . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($output, ['Commission invoices — monthly summary']);
fputcsv($output, ['Month', $label]);
fputcsv($output, ['Invoice count', $totals['invoice_count']]);
fputcsv($output, ['Staff shifts billed', $totals['staff_count']]);
fputcsv($output, ['Total hours worked', $totals['total_hours_worked']]);
fputcsv($output, ['Total hours billed', $totals['total_hours_billed']]);
fputcsv($output, ['Total commission', $totals['total_amount'], getSystemCurrency($pdo)]);
fputcsv($output, []);

fputcsv($output, ['Invoice #', 'Event', 'Event date', 'Invoice date', 'Staff', 'Hours worked', 'Hours billed', 'Amount', 'Currency', 'Status', 'Client']);

foreach ($list as $row) {
    fputcsv($output, [
        $row['invoice_number'],
        $row['event_name'],
        $row['event_date'],
        $row['invoice_date'],
        $row['staff_count'],
        $row['total_hours_worked'],
        $row['total_hours_billed'],
        $row['total_amount'],
        $row['currency'],
        $row['status'],
        $row['client_name'] ?? '',
    ]);
}

fputcsv($output, []);
fputcsv($output, ['MONTH TOTAL', '', '', '', $totals['staff_count'], $totals['total_hours_worked'], $totals['total_hours_billed'], $totals['total_amount'], getSystemCurrency($pdo), '', '']);

fclose($output);
exit;
