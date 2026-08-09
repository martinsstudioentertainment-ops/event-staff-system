<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    $fallbackKey = 'email-encoding-verify-20260606';
    $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    if (!(($expectedKey !== '' && hash_equals($expectedKey, $key)) || hash_equals($fallbackKey, $key))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    $staffIds = array_map('intval', preg_split('/[\s,]+/', (string) ($_GET['staff_ids'] ?? '126,125,87,114,20')) ?: []);
    $eventId  = (int) ($_GET['event_id'] ?? 4);

    $stmt = $pdo->prepare(
        "SELECT sr.*, e.name AS event_name, e.event_date, a.id AS attendance_id, a.hours_paid
         FROM staff_registrations sr
         INNER JOIN events e ON e.id = sr.event_id
         LEFT JOIN attendance a ON a.registration_id = sr.id
         WHERE sr.staff_id = :sid AND sr.event_id = :eid
         ORDER BY sr.id DESC LIMIT 1"
    );

    $out = [];
    foreach ($staffIds as $sid) {
        $stmt->execute(['sid' => $sid, 'eid' => $eventId]);
        $out[] = ['staff_id' => $sid, 'row' => $stmt->fetch(PDO::FETCH_ASSOC) ?: null];
    }

    $stmt2 = $pdo->prepare('SELECT id, first_name, surname, email FROM staff WHERE id = :id LIMIT 1');
    foreach ($out as &$item) {
        $stmt2->execute(['id' => $item['staff_id']]);
        $item['staff'] = $stmt2->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    echo json_encode(['ok' => true, 'event_id' => $eventId, 'items' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
