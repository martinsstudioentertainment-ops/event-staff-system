<?php
require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/commission-invoice-schema.php';

$pdo = getDB();
ensureCommissionInvoiceSchema($pdo);
$pdo->exec("UPDATE commission_invoices SET print_layout = 'summary' WHERE id = 1");
echo "Schema updated. Invoice #1 set to summary print layout.\n";
