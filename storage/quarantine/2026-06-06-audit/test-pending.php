<?php
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/staff-repository.php';

$pdo = getDB();
$filters = ['q' => '', 'status' => 'pending', 'role' => '', 'event_id' => 0, 'email' => ''];

echo "Dashboard stats pending: " . getDashboardStats($pdo)['pending'] . PHP_EOL;
echo "countStaffRegistrations: " . countStaffRegistrations($pdo, $filters) . PHP_EOL;
echo "countUniqueStaffRegistrants: " . countUniqueStaffRegistrants($pdo, $filters) . PHP_EOL;

$rows = getStaffRegistrations($pdo, $filters, 10, 0);
echo "getStaffRegistrations rows: " . count($rows) . PHP_EOL;
foreach ($rows as $r) {
    echo "  id={$r['id']} email={$r['email']} status={$r['status']} event=" . ($r['event_name'] ?? '') . PHP_EOL;
}

$grouped = getUniqueStaffRegistrants($pdo, $filters, 10, 0);
echo "getUniqueStaffRegistrants rows: " . count($grouped) . PHP_EOL;
