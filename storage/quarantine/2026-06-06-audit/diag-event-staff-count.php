<?php
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/staff-repository.php';

$pdo = getDB();
$eventId = (int) ($argv[1] ?? 1);

echo "=== Events ===\n";
foreach ($pdo->query('SELECT id, name, event_date, is_active FROM events ORDER BY id') as $row) {
    echo sprintf("  #%d %s (%s) active=%s\n", $row['id'], $row['name'], $row['event_date'], $row['is_active']);
}

echo "\n=== Registrations for event_id={$eventId} by status ===\n";
$stmt = $pdo->prepare('SELECT status, COUNT(*) AS cnt FROM staff_registrations WHERE event_id = :eid GROUP BY status ORDER BY status');
$stmt->execute(['eid' => $eventId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo sprintf("  %s: %d\n", $row['status'], $row['cnt']);
}

$stmt2 = $pdo->prepare('SELECT COUNT(*) FROM staff_registrations WHERE event_id = :eid');
$stmt2->execute(['eid' => $eventId]);
echo sprintf("  TOTAL rows: %d\n", (int) $stmt2->fetchColumn());

echo "\n=== Staff list filter (approved + event_id) ===\n";
$filters = ['q' => '', 'status' => 'approved', 'role' => '', 'event_id' => $eventId, 'email' => ''];
echo '  countUniqueStaffRegistrants: ' . countUniqueStaffRegistrants($pdo, $filters) . "\n";
echo '  countStaffRegistrations (raw rows): ' . countStaffRegistrations($pdo, $filters) . "\n";

echo "\n=== Staff list filter (all statuses + event_id) ===\n";
$filtersAll = ['q' => '', 'status' => '', 'role' => '', 'event_id' => $eventId, 'email' => ''];
echo '  countUniqueStaffRegistrants: ' . countUniqueStaffRegistrants($pdo, $filtersAll) . "\n";
echo '  countStaffRegistrations (raw rows): ' . countStaffRegistrations($pdo, $filtersAll) . "\n";
