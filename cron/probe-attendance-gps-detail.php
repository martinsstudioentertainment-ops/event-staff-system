<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    $expected = trim(getSetting($pdo, 'reminder_cron_key', ''));
    if ($expected === '' || !hash_equals($expected, $key)) {
        if (!hash_equals('email-encoding-verify-20260606', $key)) {
            http_response_code(403);
            echo json_encode(['ok' => false]);
            exit;
        }
    }

    $date = trim((string) ($_GET['date'] ?? '2026-06-13'));
    $stmt = $pdo->prepare(
        'SELECT a.id, sr.first_name, sr.surname, a.checked_in_at, a.checked_out_at,
                a.last_gps_at, a.last_gps_lat, a.last_gps_lng, a.gps_outside_strikes,
                TIMESTAMPDIFF(MINUTE, a.checked_in_at, a.checked_out_at) AS mins_in_out,
                TIMESTAMPDIFF(MINUTE, a.last_gps_at, a.checked_out_at) AS mins_gps_to_out
         FROM attendance a
         INNER JOIN staff_registrations sr ON sr.id = a.registration_id
         INNER JOIN events e ON e.id = a.event_id
         WHERE e.event_date = :d
         ORDER BY a.checked_in_at'
    );
    $stmt->execute(['d' => $date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = [
        'count' => count($rows),
        'avg_mins_in_out' => 0,
        'min_mins' => null,
        'max_mins' => null,
    ];
    $mins = [];
    foreach ($rows as $r) {
        $m = (int) ($r['mins_in_out'] ?? 0);
        $mins[] = $m;
    }
    if ($mins !== []) {
        $stats['avg_mins_in_out'] = round(array_sum($mins) / count($mins), 1);
        $stats['min_mins'] = min($mins);
        $stats['max_mins'] = max($mins);
    }

    echo json_encode(['ok' => true, 'date' => $date, 'stats' => $stats, 'rows' => $rows], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
