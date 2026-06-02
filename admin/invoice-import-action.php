<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/commission-invoice-import.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('invoices');

if (!in_array(getAdminRole(), ['admin', 'manager'], true)) {
    setAdminFlash('error', 'Only administrators and managers can import commission invoices.');
    header('Location: invoices.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: invoice-import.php');
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: invoice-import.php');
    exit;
}

$file = $_FILES['spreadsheet'] ?? null;
if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    setAdminFlash('error', 'Please choose a CSV file to upload.');
    header('Location: invoice-import.php');
    exit;
}

$tmpPath = (string) ($file['tmp_name'] ?? '');
$name    = strtolower((string) ($file['name'] ?? ''));

if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    setAdminFlash('error', 'Upload failed.');
    header('Location: invoice-import.php');
    exit;
}

if (!str_ends_with($name, '.csv') && !str_ends_with($name, '.txt')) {
    setAdminFlash('error', 'Upload a CSV file. In Excel: File → Save As → CSV (Comma delimited).');
    header('Location: invoice-import.php');
    exit;
}

$csv = readCommissionInvoiceCsvFile($tmpPath);
if ($csv === null) {
    setAdminFlash('error', 'Could not read the spreadsheet.');
    header('Location: invoice-import.php');
    exit;
}

$parsed = parseCommissionInvoiceImportCsv($csv);
if (!$parsed['ok']) {
    setAdminFlash('error', implode(' ', $parsed['errors']));
    header('Location: invoice-import.php');
    exit;
}

$pdo       = getDB();
$adminUser = getAdminUser();
$result    = importCommissionInvoiceFromSpreadsheet($pdo, $parsed, (int) $adminUser['id']);

if (!is_int($result)) {
    setAdminFlash('error', (string) $result);
    header('Location: invoice-import.php');
    exit;
}

logAdminAudit(
    $pdo,
    'commission_invoice_import',
    'commission_invoice',
    $result,
    ($parsed['mode'] ?? 'import') . ' — event ' . ($parsed['header']['event_name'] ?? '')
);

setAdminFlash('success', 'Invoice imported from spreadsheet. Review and save if needed.');
header('Location: invoice-form.php?id=' . $result);
exit;
