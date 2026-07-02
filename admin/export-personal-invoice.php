<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/job-record-repository.php';
require_once __DIR__ . '/../includes/personal-invoice-export.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('invoices');

$pdo       = getDB();
$invoiceId = (int) ($_GET['id'] ?? 0);
$format    = strtolower(trim((string) ($_GET['format'] ?? 'xlsx')));

$record = $invoiceId > 0 ? getJobRecordById($pdo, $invoiceId) : null;
if ($record === null || !isPersonalJobRecord($record)) {
    setAdminFlash('error', 'Personal invoice not found.');
    header('Location: personal-invoices.php');
    exit;
}

$shiftRows = listPersonalShiftExportRows($pdo, ['invoice_id' => $invoiceId]);
if ($shiftRows === []) {
    setAdminFlash('error', 'No shift lines to export on this invoice.');
    header('Location: personal-invoice-form.php?id=' . $invoiceId);
    exit;
}

$invoiceNumber = preg_replace('/[^a-zA-Z0-9._-]+/', '-', (string) ($record['invoice_number'] ?? 'personal-invoice'));
$basename      = 'personal-shifts-' . $invoiceNumber;

logAdminAudit(
    $pdo,
    'export_personal_invoice',
    'saved_job_record',
    $invoiceId,
    (string) ($record['invoice_number'] ?? '') . ' — ' . count($shiftRows) . ' shifts'
);

if ($format === 'csv') {
    $headers = ['#', 'Date', 'Role', 'Location', 'Hours', 'Rate / hour', 'Amount', 'Invoice #', 'Client', 'Status', 'Type'];
    $matrix  = personalShiftRowsToSheetMatrix($pdo, $shiftRows);
    staffRosterSendCsvDownload($headers, $matrix, $basename);
    exit;
}

sendPersonalShiftsXlsxDownload($pdo, $shiftRows, $basename);
exit;
