<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/commission-invoice-import.php';

requireAdminCapability('invoices');

$mode = ($_GET['mode'] ?? 'summary') === 'detailed' ? 'detailed' : 'summary';
$headers = getCommissionInvoiceImportTemplateHeaders($mode);

$filename = 'commission-invoice-import-' . $mode . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($output, $headers);

if ($mode === 'summary') {
    fputcsv($output, [
        'Aviva Stadium',
        '2026-05-28',
        'Aviva Stadium, Dublin',
        '2026-05-28',
        'Map Security',
        '30',
        '6',
        '1',
        'Six hour shift',
    ]);
} else {
    fputcsv($output, [
        'Aviva Stadium',
        '2026-05-28',
        'Aviva Stadium, Dublin',
        '2026-05-28',
        'Map Security',
        'Demo Lad 1',
        'steward',
        '6',
        '6',
        '1',
        '6',
        '',
    ]);
    fputcsv($output, [
        'Aviva Stadium',
        '2026-05-28',
        'Aviva Stadium, Dublin',
        '2026-05-28',
        'Map Security',
        'Demo Lad 2',
        'steward',
        '6',
        '6',
        '1',
        '6',
        '',
    ]);
}

fclose($output);
exit;
