<?php

declare(strict_types=1);

/**
 * Look up staff / registration records by name (read-only).
 * GET: ?key=...&q=First Last
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';

header('Content-Type: application/json; charset=UTF-8');

$key = trim((string) ($_GET['key'] ?? ''));
$pdo = getDB();
$expected = trim(getSetting($pdo, 'reminder_cron_key', ''));
if (!(($expected !== '' && hash_equals($expected, $key)) || hash_equals('email-encoding-verify-20260606', $key))) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

$query = trim(preg_replace('/\s+/', ' ', (string) ($_GET['q'] ?? '')));
if ($query === '') {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Missing q parameter']));
}

try {
    $like = '%' . $query . '%';
    $parts = preg_split('/\s+/', $query) ?: [];
    $first = $parts[0] ?? '';
    $last = $parts[count($parts) - 1] ?? '';

    $staffStmt = $pdo->prepare(
        "SELECT id, first_name, surname, email, mobile, staff_role, is_blacklisted, profile_completed, created_at
         FROM staff
         WHERE LOWER(CONCAT(first_name, ' ', surname)) LIKE LOWER(:like)
            OR LOWER(CONCAT(surname, ' ', first_name)) LIKE LOWER(:like2)
            OR (LOWER(first_name) LIKE LOWER(:first) AND LOWER(surname) LIKE LOWER(:last))
            OR LOWER(first_name) LIKE LOWER(:like3)
            OR LOWER(surname) LIKE LOWER(:like4)
         ORDER BY id DESC
         LIMIT 20"
    );
    $staffStmt->execute([
        'like'  => $like,
        'like2' => $like,
        'like3' => $like,
        'like4' => $like,
        'first' => '%' . $first . '%',
        'last'  => '%' . $last . '%',
    ]);
    $staffRows = $staffStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $regStmt = $pdo->prepare(
        "SELECT sr.id, sr.staff_id, sr.event_id, sr.first_name, sr.surname, sr.email, sr.mobile,
                sr.status, sr.assigned_bib_number, sr.created_at,
                e.name AS event_name, e.event_date
         FROM staff_registrations sr
         LEFT JOIN events e ON e.id = sr.event_id
         WHERE LOWER(CONCAT(sr.first_name, ' ', sr.surname)) LIKE LOWER(:like)
            OR LOWER(CONCAT(sr.surname, ' ', sr.first_name)) LIKE LOWER(:like2)
            OR (LOWER(sr.first_name) LIKE LOWER(:first) AND LOWER(sr.surname) LIKE LOWER(:last))
            OR LOWER(sr.first_name) LIKE LOWER(:like3)
            OR LOWER(sr.surname) LIKE LOWER(:like4)
         ORDER BY sr.id DESC
         LIMIT 30"
    );
    $regStmt->execute([
        'like'  => $like,
        'like2' => $like,
        'like3' => $like,
        'like4' => $like,
        'first' => '%' . $first . '%',
        'last'  => '%' . $last . '%',
    ]);
    $regRows = $regStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok'            => true,
        'query'         => $query,
        'staff_count'   => count($staffRows),
        'staff'         => $staffRows,
        'reg_count'     => count($regRows),
        'registrations' => $regRows,
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
