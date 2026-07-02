<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/job-record-repository.php';
require_once __DIR__ . '/../includes/personal-invoice-export.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('invoices');

$pdo    = getDB();
$month  = trim((string) ($_GET['month'] ?? ''));
$format = strtolower(trim((string) ($_GET['format'] ?? 'xlsx')));

if ($month !== '' && !preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = '';
}

$filters   = $month !== '' ? ['month' => $month] : [];
$shiftRows = listPersonalShiftExportRows($pdo, $filters);

if ($shiftRows === []) {
    setAdminFlash('error', 'No personal shifts found to export.');
    header('Location: personal-invoices.php');
    exit;
}

$basename = $month !== '' ? 'personal-shifts-' . $month : 'personal-shifts-all';

logAdminAudit(
    $pdo,
    'export_personal_shifts',
    'saved_job_record',
    null,
    ($month !== '' ? $month : 'all') . ' — ' . count($shiftRows) . ' shifts'
);

if ($format === 'csv') {
    $headers = ['#', 'Date', 'Role', 'Location', 'Hours', 'Rate / hour', 'Amount', 'Invoice #', 'Client', 'Status', 'Type'];
    $matrix  = personalShiftRowsToSheetMatrix($pdo, $shiftRows);
    staffRosterSendCsvDownload($headers, $matrix, $basename);
    exit;
}

sendPersonalShiftsXlsxDownload($pdo, $shiftRows, $basename);
exit;
