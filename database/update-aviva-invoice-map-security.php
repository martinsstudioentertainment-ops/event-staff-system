<?php
/**
 * Update Aviva Stadium demo invoice: Map Security client, €1/hr, fake company IBAN.
 */
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/commission-invoice-repository.php';

$pdo = getDB();

saveSettings($pdo, [
    'invoice_bank_name'  => 'Map Security Ltd',
    'invoice_bank_iban'  => 'IE64IRCE99007012345678',
    'invoice_bank_bic'   => 'IRCEIE2D',
    'invoice_vat_number' => '',
    'commission_rate_steward' => '1.00',
    'commission_rate_dsp'     => '1.00',
    'commission_rate_static'  => '1.00',
    'commission_rate_default' => '1.00',
]);

$stmt = $pdo->prepare("SELECT id FROM events WHERE name = 'Aviva Stadium' AND event_date = '2026-05-28' LIMIT 1");
$stmt->execute();
$eventId = (int) ($stmt->fetchColumn() ?: 0);

$invoice = $eventId > 0 ? getCommissionInvoiceByEventId($pdo, $eventId) : null;
if (!$invoice) {
    $invoice = getCommissionInvoiceById($pdo, 1);
}
if (!$invoice) {
    echo "Invoice not found.\n";
    exit(1);
}

$eventId   = (int) $invoice['event_id'];
$invoiceId = (int) $invoice['id'];
$lines     = getCommissionInvoiceLines($pdo, $invoiceId);

foreach ($lines as &$line) {
    $line['hourly_rate']     = 1.00;
    $line['hours_billed']    = 6.00;
    $line['hours_worked']    = 6.00;
    $line['line_amount']     = calculateCommissionLineAmount(6.00, 1.00);
    $line['amount_override'] = 0;
}
unset($line);

$result = saveCommissionInvoice($pdo, $eventId, [
    'invoice_date'   => (string) $invoice['invoice_date'],
    'client_name'    => 'Map Security',
    'status'         => (string) ($invoice['status'] ?? 'draft'),
    'notes'          => (string) ($invoice['notes'] ?? ''),
    'invoice_number' => (string) $invoice['invoice_number'],
], $lines, 1, $invoiceId);

if (!is_int($result)) {
    echo "Error: {$result}\n";
    exit(1);
}

$inv = getCommissionInvoiceById($pdo, $result);

echo "Updated invoice {$inv['invoice_number']}\n";
echo "Client: Map Security\n";
echo "Rate: EUR 1.00 / hour\n";
echo "Lads: {$inv['staff_count']} × 6 h = {$inv['total_hours_billed']} h\n";
echo "Total: EUR " . number_format((float) $inv['total_amount'], 2) . "\n";
echo "Payment IBAN (fake): IE64IRCE99007012345678\n";
echo "Print: admin/print-invoice.php?id={$result}\n";
