<?php
require_once __DIR__ . '/../config.php';

$pdo = getDB();
$eventId = (int) $pdo->query('SELECT id FROM events LIMIT 1')->fetchColumn();
if ($eventId < 1) {
    fwrite(STDERR, "No events in DB\n");
    exit(1);
}

$exists = (int) $pdo->query("SELECT COUNT(*) FROM staff_registrations WHERE email = 'pendingtest@example.com' AND status = 'pending'")->fetchColumn();
if ($exists === 0) {
    $stmt = $pdo->prepare("INSERT INTO staff_registrations (surname, first_name, email, mobile, staff_role, event_id, status, created_at) VALUES ('Test','Pending','pendingtest@example.com','0871234567','steward', ?, 'pending', NOW())");
    $stmt->execute([$eventId]);
    echo "Inserted test pending registration\n";
} else {
    echo "Test pending registration already exists\n";
}
