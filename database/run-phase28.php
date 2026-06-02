<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/commission-invoice-schema.php';

$pdo = getDB();

try {
    commissionInvoiceCreateTables($pdo);
    echo "Phase 28 tables created.\n";
    $tables = $pdo->query("SHOW TABLES LIKE 'commission%'")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables: " . implode(', ', $tables) . "\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
