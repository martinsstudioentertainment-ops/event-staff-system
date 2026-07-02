<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');
if (!$isCli) {
    $pdo = getDB();
    $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $providedKey = trim((string) ($_GET['key'] ?? ''));
    if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
}

$pdo = getDB();
$today = date('Y-m-d');

$total = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
$past = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE event_date < CURDATE()")->fetchColumn();
$upcoming = (int) $pdo->query("SELECT COUNT(*) FROM events WHERE event_date >= CURDATE()")->fetchColumn();
$active = (int) $pdo->query('SELECT COUNT(*) FROM events WHERE is_active = 1')->fetchColumn();
$inactive = (int) $pdo->query('SELECT COUNT(*) FROM events WHERE is_active = 0')->fetchColumn();
$withAttendance = (int) $pdo->query('SELECT COUNT(DISTINCT event_id) FROM attendance')->fetchColumn();

$stmt = $pdo->query(
    "SELECT e.id, e.name, e.event_date, e.is_active,
            COUNT(sr.id) AS registrations,
            SUM(CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END) AS checkins
     FROM events e
     LEFT JOIN staff_registrations sr ON sr.event_id = e.id
     LEFT JOIN attendance a ON a.registration_id = sr.id
     WHERE e.event_date < CURDATE()
     GROUP BY e.id
     ORDER BY e.event_date DESC
     LIMIT 50"
);
$pastEvents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$range = $pdo->query(
    'SELECT MIN(event_date) AS min_date, MAX(event_date) AS max_date FROM events'
)->fetch(PDO::FETCH_ASSOC) ?: [];

$payload = [
    'ok' => true,
    'today' => $today,
    'totals' => [
        'all' => $total,
        'past' => $past,
        'upcoming' => $upcoming,
        'active' => $active,
        'inactive' => $inactive,
        'with_checkins' => $withAttendance,
    ],
    'date_range' => $range,
    'past_events' => $pastEvents,
];

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
