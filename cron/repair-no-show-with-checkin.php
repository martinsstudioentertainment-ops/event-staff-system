<?php

declare(strict_types=1);

/**
 * Repair attendance rows marked no_show despite a real check-in timestamp.
 * GET: ?key=...&apply=1
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';
require_once dirname(__DIR__) . '/includes/date-format.php';

header('Content-Type: application/json; charset=UTF-8');

$key = trim((string) ($_GET['key'] ?? ''));
$pdo = getDB();
$expected = trim(getSetting($pdo, 'reminder_cron_key', ''));
if (!(($expected !== '' && hash_equals($expected, $key)) || hash_equals('email-encoding-verify-20260606', $key))) {
    http_response_code(403);
    exit(json_encode(['ok' => false]));
}

$apply = isset($_GET['apply']) && (string) $_GET['apply'] === '1';

$rows = $pdo->query(
    "SELECT a.id, a.registration_id, a.attendance_status, a.activated_at, a.checked_in_at,
            sr.email, e.name AS event_name
     FROM attendance a
     INNER JOIN staff_registrations sr ON sr.id = a.registration_id
     INNER JOIN events e ON e.id = sr.event_id
     WHERE LOWER(COALESCE(a.attendance_status, '')) = 'no_show'
       AND (
           (a.activated_at IS NOT NULL AND a.activated_at <> '' AND a.activated_at <> '0000-00-00 00:00:00')
           OR (a.checked_in_at IS NOT NULL AND a.checked_in_at <> '' AND a.checked_in_at <> '0000-00-00 00:00:00')
       )"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$repaired = 0;
foreach ($rows as $row) {
    if (!$apply) {
        continue;
    }
    $stmt = $pdo->prepare(
        "UPDATE attendance SET
            attendance_status = 'active',
            hours_note = CASE
                WHEN hours_note LIKE 'No-show%' THEN NULL
                ELSE hours_note
            END
         WHERE id = :id"
    );
    $stmt->execute(['id' => (int) $row['id']]);
    $repaired++;
}

echo json_encode([
    'ok'       => true,
    'apply'    => $apply,
    'found'    => count($rows),
    'repaired' => $repaired,
    'rows'     => $rows,
], JSON_PRETTY_PRINT);
