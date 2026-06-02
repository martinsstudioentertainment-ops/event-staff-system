<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/staff-blacklist-schema.php';

$pdo = getDB();

try {
    staffBlacklistCreateTable($pdo);
    echo "Phase 30 staff_blacklist table ready.\n";
} catch (Throwable $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
