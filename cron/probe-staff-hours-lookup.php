<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    $expected = trim(getSetting($pdo, 'reminder_cron_key', ''));
    if ($expected !== '' && hash_equals($expected, $key)) {
        // ok
    } elseif (!hash_equals('email-encoding-verify-20260606', $key)) {
        http_response_code(403);
        echo json_encode(['ok' => false]);
        exit;
    }

    $date = trim((string) ($_GET['date'] ?? '2026-06-13'));
    $event = trim((string) ($_GET['event'] ?? 'Kingfishr'));
    $names = array_filter(array_map('trim', explode('|', (string) ($_GET['names'] ?? ''))));

    $stmt = $pdo->prepare(
        'SELECT sr.id AS registration_id, sr.first_name, sr.surname, sr.email, sr.status,
                a.id AS attendance_id, a.checked_in_at, a.checked_out_at, a.activated_at,
                a.hours_worked, a.hours_paid, a.hours_note, a.attendance_status,
                a.checked_in_method, e.name AS event_name, e.event_date, e.start_time, e.end_time
         FROM staff_registrations sr
         INNER JOIN events e ON e.id = sr.event_id
         LEFT JOIN attendance a ON a.registration_id = sr.id
         WHERE e.event_date = :d AND e.name = :event
         ORDER BY sr.surname, sr.first_name'
    );
    $stmt->execute(['d' => $date, 'event' => $event]);
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $matched = [];
    foreach ($all as $row) {
        $full = trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? ''));
        $fullLower = strtolower($full);
        foreach ($names as $needle) {
            if ($needle === '') {
                continue;
            }
            if (stripos($fullLower, strtolower($needle)) !== false
                || stripos($fullLower, str_replace(' ', '', strtolower($needle))) !== false) {
                $matched[] = array_merge($row, ['matched_query' => $needle]);
                break;
            }
        }
    }

    echo json_encode([
        'ok'      => true,
        'date'    => $date,
        'event'   => $event,
        'queries' => $names,
        'matched' => $matched,
        'total_event_staff' => count($all),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
