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
$action    = trim((string) ($_POST['action'] ?? 'save'));

if ($action === 'void' && $invoiceId > 0) {
    $existing = getCommissionInvoiceById($pdo, $invoiceId);
    if (!$existing) {
        setAdminFlash('error', 'Invoice not found.');
        header('Location: invoices.php');
        exit;
    }

    $result = voidCommissionInvoice($pdo, $invoiceId);
    if (!is_int($result)) {
        setAdminFlash('error', (string) $result);
        header('Location: invoice-form.php?id=' . $invoiceId);
        exit;
    }

    logAdminAudit(
        $pdo,
        'commission_invoice_void',
        'commission_invoice',
        $result,
        (string) ($existing['invoice_number'] ?? 'Invoice') . ' — event #' . (int) $existing['event_id']
    );

    setAdminFlash('success', 'Commission invoice marked as void. It is hidden from normal lists but still linked to this event.');
    header('Location: invoices.php');
    exit;
}

if ($action === 'delete' && $invoiceId > 0) {
    $existing = getCommissionInvoiceById($pdo, $invoiceId);
    if (!$existing) {
        setAdminFlash('error', 'Invoice not found.');
        header('Location: invoices.php');
        exit;
    }

    $result = deleteCommissionInvoice($pdo, $invoiceId);
    if (!is_int($result)) {
        setAdminFlash('error', (string) $result);
        header('Location: invoice-form.php?id=' . $invoiceId);
        exit;
    }

    logAdminAudit(
        $pdo,
        'commission_invoice_delete',
        'commission_invoice',
        $result,
        (string) ($existing['invoice_number'] ?? 'Invoice') . ' — event #' . (int) $existing['event_id']
    );

    setAdminFlash('success', 'Commission invoice deleted. You can create a new invoice for this event if needed.');
    header('Location: invoices.php');
    exit;
}

if ($action === 'reload_checked_in') {
    if ($invoiceId < 1) {
        setAdminFlash('error', 'Invoice not found.');
        header('Location: invoices.php');
        exit;
    }

    $result = rebuildCommissionInvoiceLinesFromEvent($pdo, $invoiceId, (int) $adminUser['id']);
    if (!is_int($result)) {
        setAdminFlash('error', (string) $result);
        header('Location: invoice-form.php?id=' . $invoiceId);
        exit;
    }

    logAdminAudit(
        $pdo,
        'commission_invoice_reload_checked_in',
        'commission_invoice',
        $result,
        'Reloaded lines from checked-in staff — event #' . $eventId
    );

    $lineCount = count(getCommissionInvoiceLines($pdo, $result));
    setAdminFlash(
        'success',
        $lineCount === 0
            ? 'Invoice cleared — no checked-in staff for this event.'
            : 'Invoice lines updated to checked-in staff only.'
    );
    header('Location: invoice-form.php?id=' . $result);
    exit;
}

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
