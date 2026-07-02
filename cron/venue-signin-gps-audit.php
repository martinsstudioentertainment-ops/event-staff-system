<?php

declare(strict_types=1);

/**
 * Read-only: staff who checked in at a venue with GPS coordinates.
 *
 * Web: /cron/venue-signin-gps-audit.php?key=REMINDER_CRON_KEY
 *      /cron/venue-signin-gps-audit.php?key=...&date=2026-06-11
 *      /cron/venue-signin-gps-audit.php?key=...&event_id=123
 * CLI: php cron/venue-signin-gps-audit.php [--date=YYYY-MM-DD] [--event_id=N]
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/attendance-gps-phase1-schema.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');

$dateArg = '';
$eventId = 0;

if ($isCli) {
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with($arg, '--date=')) {
            $dateArg = substr($arg, 7);
        } elseif (str_starts_with($arg, '--event_id=')) {
            $eventId = (int) substr($arg, 11);
        }
    }
} else {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $pdo         = getDB();
        $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
        $providedKey = trim((string) ($_GET['key'] ?? ''));

        if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_THROW_ON_ERROR);
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database error'], JSON_THROW_ON_ERROR);
        exit;
    }

    $dateArg = trim((string) ($_GET['date'] ?? ''));
    $eventId = (int) ($_GET['event_id'] ?? 0);
}

$pdo = getDB();
ensureAttendanceGpsPhase1Schema($pdo);

$date = $dateArg !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateArg) === 1
    ? $dateArg
    : date('Y-m-d');

$params = ['check_date' => $date];
$eventSql = '';
if ($eventId > 0) {
    $eventSql = ' AND a.event_id = :event_id';
    $params['event_id'] = $eventId;
}

$sql = "SELECT sr.first_name, sr.surname, sr.email,
               e.id AS event_id, e.name AS event_name, e.event_date,
               a.checked_in_at, a.checked_in_method,
               a.check_in_lat, a.check_in_lng, a.check_in_accuracy_m,
               a.attendance_status, a.activated_at
        FROM attendance a
        INNER JOIN staff_registrations sr ON sr.id = a.registration_id
        INNER JOIN events e ON e.id = a.event_id
        WHERE DATE(a.checked_in_at) = :check_date
          AND a.check_in_lat IS NOT NULL
          AND a.check_in_lng IS NOT NULL"
    . $eventSql . '
        ORDER BY a.checked_in_at ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$staff = [];
foreach ($rows as $row) {
    $staff[] = [
        'name'       => trim((string) $row['first_name'] . ' ' . (string) $row['surname']),
        'email'      => (string) ($row['email'] ?? ''),
        'event'      => (string) ($row['event_name'] ?? ''),
        'event_date' => (string) ($row['event_date'] ?? ''),
        'checked_in' => (string) ($row['checked_in_at'] ?? ''),
        'method'     => (string) ($row['checked_in_method'] ?? ''),
        'gps_lat'    => $row['check_in_lat'],
        'gps_lng'    => $row['check_in_lng'],
        'accuracy_m' => $row['check_in_accuracy_m'],
        'status'     => (string) ($row['attendance_status'] ?? ''),
    ];
}

$allSql = "SELECT sr.first_name, sr.surname, sr.email,
                  e.name AS event_name, e.event_date,
                  a.checked_in_at, a.checked_in_method,
                  a.check_in_lat, a.check_in_lng
           FROM attendance a
           INNER JOIN staff_registrations sr ON sr.id = a.registration_id
           INNER JOIN events e ON e.id = a.event_id
           WHERE DATE(a.checked_in_at) = :check_date" . $eventSql . '
           ORDER BY a.checked_in_at ASC';
$allStmt = $pdo->prepare($allSql);
$allStmt->execute($params);
$allRows = $allStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$allCheckins = [];
foreach ($allRows as $row) {
    $allCheckins[] = [
        'name'       => trim((string) $row['first_name'] . ' ' . (string) $row['surname']),
        'email'      => (string) ($row['email'] ?? ''),
        'event'      => (string) ($row['event_name'] ?? ''),
        'event_date' => (string) ($row['event_date'] ?? ''),
        'checked_in' => (string) ($row['checked_in_at'] ?? ''),
        'method'     => (string) ($row['checked_in_method'] ?? ''),
        'has_gps'    => $row['check_in_lat'] !== null && $row['check_in_lng'] !== null,
    ];
}

$payload = [
    'ok'               => true,
    'generated_at_utc' => gmdate('c'),
    'date'             => $date,
    'event_id_filter'  => $eventId > 0 ? $eventId : null,
    'count_with_gps'   => count($staff),
    'count_all_checkins' => count($allCheckins),
    'note'             => 'Location-only verification (GPS OK but no email/PPS submitted) is not logged. with_gps = completed check-in with coordinates stored.',
    'staff_with_gps'   => $staff,
    'all_checkins'     => $allCheckins,
];

if ($isCli) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
