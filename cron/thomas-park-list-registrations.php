<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=UTF-8');

$key = trim((string) ($_GET['key'] ?? ''));
if ($key !== 'email-encoding-verify-20260606') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$eventId = (int) ($_GET['event_id'] ?? 38);

try {
    $pdo = getDB();

    $event = $pdo->prepare('SELECT * FROM events WHERE id = :id');
    $event->execute(['id' => $eventId]);
    $eventRow = $event->fetch(PDO::FETCH_ASSOC);

    $regs = $pdo->prepare(
        "SELECT sr.id, sr.staff_id, sr.first_name, sr.surname, sr.email, sr.mobile,
                sr.staff_role, sr.status,
                a.id AS attendance_id, a.hours_paid, a.checked_in_at
         FROM staff_registrations sr
         LEFT JOIN attendance a ON a.registration_id = sr.id
         WHERE sr.event_id = :event_id
         ORDER BY sr.surname, sr.first_name"
    );
    $regs->execute(['event_id' => $eventId]);
    $rows = $regs->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'event' => $eventRow,
        'count' => count($rows),
        'registrations' => $rows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
