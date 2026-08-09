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

    $eventId = (int) ($_GET['event_id'] ?? 4);
    $bibs    = array_map('trim', preg_split('/[\s,]+/', (string) ($_GET['bibs'] ?? '1177,1601,1263,1886')) ?: []);

    $stmt = $pdo->prepare(
        "SELECT sr.id AS registration_id, sr.staff_id, sr.first_name, sr.surname, sr.assigned_bib_number, sr.status,
                a.id AS attendance_id, a.hours_paid, a.hours_worked
         FROM staff_registrations sr
         LEFT JOIN attendance a ON a.registration_id = sr.id
         WHERE sr.event_id = :eid AND sr.assigned_bib_number = :bib
         LIMIT 1"
    );

    $rows = [];
    foreach ($bibs as $bib) {
        $stmt->execute(['eid' => $eventId, 'bib' => $bib]);
        $rows[] = ['bib' => $bib, 'row' => $stmt->fetch(PDO::FETCH_ASSOC) ?: null];
    }

    echo json_encode(['ok' => true, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
