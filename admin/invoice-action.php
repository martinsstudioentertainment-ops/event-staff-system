<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/commission-invoice-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('invoices');

if (!in_array(getAdminRole(), ['admin', 'manager'], true)) {
    setAdminFlash('error', 'Only administrators and managers can edit commission invoices.');
    header('Location: invoices.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: invoices.php');
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: invoices.php');
    exit;
}

$pdo       = getDB();
$adminUser = getAdminUser();
$eventId   = (int) ($_POST['event_id'] ?? 0);
$invoiceId = (int) ($_POST['invoice_id'] ?? 0);

$header = [
    'invoice_number' => trim((string) ($_POST['invoice_number'] ?? '')),
    'invoice_date'   => trim((string) ($_POST['invoice_date'] ?? '')),
    'client_name'    => trim((string) ($_POST['client_name'] ?? '')),
    'status'         => trim((string) ($_POST['status'] ?? 'draft')),
    'print_layout'   => trim((string) ($_POST['print_layout'] ?? 'detailed')),
    'notes'          => trim((string) ($_POST['notes'] ?? '')),
    'currency'       => getSystemCurrency($pdo),
];

$lines  = parseCommissionInvoiceLinesFromPost($_POST);
$result = saveCommissionInvoice(
    $pdo,
    $eventId,
    $header,
    $lines,
    (int) $adminUser['id'],
    $invoiceId > 0 ? $invoiceId : null
);

if (!is_int($result)) {
    setAdminFlash('error', (string) $result);
    $redirect = $invoiceId > 0
        ? 'invoice-form.php?id=' . $invoiceId
        : 'invoice-form.php?event_id=' . $eventId;
    header('Location: ' . $redirect);
    exit;
}

logAdminAudit(
    $pdo,
    $invoiceId > 0 ? 'commission_invoice_update' : 'commission_invoice_create',
    'commission_invoice',
    $result,
    ($header['invoice_number'] !== '' ? $header['invoice_number'] : 'Invoice') . ' — event #' . $eventId
);

setAdminFlash('success', 'Commission invoice saved.');
header('Location: invoice-form.php?id=' . $result);
exit;
