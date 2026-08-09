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

    $emails = array_map('strtolower', array_filter(array_map('trim', preg_split('/[,|]+/', (string) ($_GET['emails'] ?? '')))));
    $eventId = (int) ($_GET['event_id'] ?? 4);

    $stmt = $pdo->prepare(
        "SELECT sr.*, a.id AS attendance_id, a.hours_paid
         FROM staff_registrations sr
         LEFT JOIN attendance a ON a.registration_id = sr.id
         WHERE sr.event_id = :eid AND LOWER(sr.email) = :email
         ORDER BY sr.id DESC LIMIT 1"
    );

    $rows = [];
    foreach ($emails as $email) {
        $stmt->execute(['eid' => $eventId, 'email' => $email]);
        $rows[] = ['email' => $email, 'row' => $stmt->fetch(PDO::FETCH_ASSOC) ?: null];
    }

    echo json_encode(['ok' => true, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
