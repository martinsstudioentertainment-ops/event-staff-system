<?php

declare(strict_types=1);

/**
 * Read-only: every possible proxy for venue sign-in attempts on a given date.
 *
 * Web: /cron/signin-attempts-audit.php?key=REMINDER_CRON_KEY&date=2026-06-11
 * CLI: php cron/signin-attempts-audit.php --date=2026-06-11
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/website-visitor-tracking.php';
require_once __DIR__ . '/../includes/attendance-gps-phase1-schema.php';
require_once __DIR__ . '/../includes/attendance-gps-phase15-schema.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/production-readiness.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');
$dateArg = '';

if ($isCli) {
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with($arg, '--date=')) {
            $dateArg = substr($arg, 7);
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
}

try {
    $pdo = getDB();
} catch (Throwable $e) {
    $err = ['ok' => false, 'error' => 'Database connection failed'];
    echo json_encode($err, $isCli ? JSON_PRETTY_PRINT : 0);
    exit(1);
}

ensureWebsiteVisitSchema($pdo);
ensureAttendanceGpsPhase1Schema($pdo);
ensureAttendanceGpsPhase15Schema($pdo);

$attCols = [];
try {
    $attCols = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $attCols = [];
}
$hasLastGps = in_array('last_gps_at', $attCols, true);

$date = $dateArg !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateArg) === 1
    ? $dateArg
    : date('Y-m-d');

$dayStart = $date . ' 00:00:00';
$dayEnd   = $date . ' 23:59:59';

$registrationUrl = getRegistrationSiteUrl($pdo);
$gpsFlag         = getSetting($pdo, 'feature_gps_attendance_v2', '0');

// --- Events on this date ---
$eventsStmt = $pdo->prepare(
    'SELECT e.id, e.name, e.event_date, e.signin_token,
            (SELECT COUNT(*) FROM staff_registrations sr
             WHERE sr.event_id = e.id AND sr.status = \'approved\') AS approved_count
     FROM events e
     WHERE e.event_date = :d
     ORDER BY e.name ASC'
);
$eventsStmt->execute(['d' => $date]);
$eventsToday = $eventsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$recentEventsStmt = $pdo->prepare(
    'SELECT e.id, e.name, e.event_date, e.signin_token,
            (SELECT COUNT(*) FROM staff_registrations sr
             WHERE sr.event_id = e.id AND sr.status = \'approved\') AS approved_count
     FROM events e
     WHERE e.event_date BETWEEN DATE_SUB(:d, INTERVAL 3 DAY) AND DATE_ADD(:d2, INTERVAL 1 DAY)
     ORDER BY e.event_date DESC, e.name ASC'
);
$recentEventsStmt->execute(['d' => $date, 'd2' => $date]);
$eventsRecent = $recentEventsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// --- Website visits to sign-in pages (only logged on non-admin hosts) ---
$totalVisitsStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM website_visits WHERE visited_at >= :start AND visited_at <= :end'
);
$totalVisitsStmt->execute(['start' => $dayStart, 'end' => $dayEnd]);
$totalPageViewsDay = (int) $totalVisitsStmt->fetchColumn();

$visitsTableTotal = 0;
$visitsLatest     = null;
try {
    $visitsTableTotal = (int) $pdo->query('SELECT COUNT(*) FROM website_visits')->fetchColumn();
    $visitsLatest     = $pdo->query('SELECT MAX(visited_at) FROM website_visits')->fetchColumn();
} catch (Throwable $e) {
    $visitsTableTotal = -1;
}

$visitStmt = $pdo->prepare(
    "SELECT id, visited_at, http_host, request_path, ip_address, city, region, country, visitor_key
     FROM website_visits
     WHERE visited_at >= :start AND visited_at <= :end
       AND (
            request_path LIKE '%event-sign.php%'
            OR request_path LIKE '%sign-in.php%'
            OR request_path LIKE '%check-in.php%'
       )
     ORDER BY visited_at ASC"
);
$visitStmt->execute(['start' => $dayStart, 'end' => $dayEnd]);
$visits = $visitStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$uniqueVisitors = [];
foreach ($visits as $v) {
    $key = (string) ($v['visitor_key'] ?? $v['ip_address'] ?? ('row-' . $v['id']));
    if (!isset($uniqueVisitors[$key])) {
        $uniqueVisitors[$key] = [
            'first_seen'    => (string) $v['visited_at'],
            'ip'            => (string) ($v['ip_address'] ?? ''),
            'city'          => trim((string) ($v['city'] ?? '') . ', ' . (string) ($v['region'] ?? '') . ', ' . (string) ($v['country'] ?? '')),
            'host'          => (string) ($v['http_host'] ?? ''),
            'paths'         => [],
            'page_views'    => 0,
        ];
    }
    $uniqueVisitors[$key]['page_views']++;
    $path = (string) ($v['request_path'] ?? '');
    if ($path !== '' && !in_array($path, $uniqueVisitors[$key]['paths'], true)) {
        $uniqueVisitors[$key]['paths'][] = $path;
    }
}

// --- Attendance: all statuses ---
$attendanceRows = [];
if ($hasLastGps) {
    $attStmt = $pdo->prepare(
        "SELECT sr.first_name, sr.surname, sr.email,
                e.name AS event_name, e.event_date,
                a.checked_in_at, a.checked_in_method,
                a.attendance_status, a.activated_at,
                a.check_in_lat, a.check_in_lng, a.check_in_accuracy_m, a.check_in_gps_at,
                a.last_gps_lat, a.last_gps_lng, a.last_gps_accuracy_m, a.last_gps_at
         FROM attendance a
         INNER JOIN staff_registrations sr ON sr.id = a.registration_id
         INNER JOIN events e ON e.id = a.event_id
         WHERE DATE(a.checked_in_at) = :d1
            OR (a.last_gps_at IS NOT NULL AND DATE(a.last_gps_at) = :d2)
         ORDER BY COALESCE(a.checked_in_at, a.last_gps_at) ASC"
    );
    $attStmt->execute(['d1' => $date, 'd2' => $date]);
    $attendanceRows = $attStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
    $attStmt = $pdo->prepare(
        "SELECT sr.first_name, sr.surname, sr.email,
                e.name AS event_name, e.event_date,
                a.checked_in_at, a.checked_in_method,
                a.attendance_status, a.activated_at,
                a.check_in_lat, a.check_in_lng, a.check_in_accuracy_m, a.check_in_gps_at,
                NULL AS last_gps_lat, NULL AS last_gps_lng, NULL AS last_gps_accuracy_m, NULL AS last_gps_at
         FROM attendance a
         INNER JOIN staff_registrations sr ON sr.id = a.registration_id
         INNER JOIN events e ON e.id = a.event_id
         WHERE DATE(a.checked_in_at) = :d1
         ORDER BY a.checked_in_at ASC"
    );
    $attStmt->execute(['d1' => $date]);
    $attendanceRows = $attStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$checkedIn      = [];
$preCheckedIn   = [];
$gpsPingOnly    = [];

foreach ($attendanceRows as $row) {
    $name = trim((string) $row['first_name'] . ' ' . (string) $row['surname']);
    $item = [
        'name'       => $name,
        'email'      => (string) ($row['email'] ?? ''),
        'event'      => (string) ($row['event_name'] ?? ''),
        'status'     => (string) ($row['attendance_status'] ?? ''),
        'checked_in' => (string) ($row['checked_in_at'] ?? ''),
        'method'     => (string) ($row['checked_in_method'] ?? ''),
        'has_checkin_gps' => $row['check_in_lat'] !== null && $row['check_in_lng'] !== null,
        'last_gps_at'     => (string) ($row['last_gps_at'] ?? ''),
    ];

    $status = strtolower((string) ($row['attendance_status'] ?? ''));
    $checkedDate = $row['checked_in_at'] ? date('Y-m-d', strtotime((string) $row['checked_in_at'])) : '';
    $gpsDate     = $row['last_gps_at'] ? date('Y-m-d', strtotime((string) $row['last_gps_at'])) : '';

    if ($checkedDate === $date) {
        if ($status === 'pre_checked_in') {
            $preCheckedIn[] = $item;
        } else {
            $checkedIn[] = $item;
        }
    } elseif ($gpsDate === $date && $checkedDate !== $date) {
        $gpsPingOnly[] = $item;
    }
}

// --- Approved staff for today's events (who could have tried) ---
$approvedPool = [];
if ($eventsToday !== []) {
    $ids = array_map(static fn(array $e): int => (int) $e['id'], $eventsToday);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $poolStmt = $pdo->prepare(
        "SELECT sr.first_name, sr.surname, sr.email, e.name AS event_name
         FROM staff_registrations sr
         INNER JOIN events e ON e.id = sr.event_id
         WHERE sr.event_id IN ($placeholders) AND sr.status = 'approved'
         ORDER BY e.name, sr.surname, sr.first_name"
    );
    $poolStmt->execute($ids);
    $approvedPool = $poolStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$payload = [
    'ok'               => true,
    'generated_at_utc' => gmdate('c'),
    'date'             => $date,
    'registration_site_url' => $registrationUrl,
    'feature_gps_attendance_v2' => $gpsFlag,
    'limits'           => [
        'gps_location_verified_only' => 'NOT LOGGED — browser GPS success never hits the server until form submit or GPS ping API.',
        'website_visits'             => 'Only on non-admin hosts (register.olasentra.com etc.). admin.* pages are excluded from visitor tracking.',
        'names_for_location_only'    => 'Cannot recover real names — only page opens (IP) or completed check-ins.',
    ],
    'summary' => [
        'events_today'              => count($eventsToday),
        'approved_staff_today'      => count($approvedPool),
        'website_visits_table_total'  => $visitsTableTotal,
        'website_visits_latest_ever'  => $visitsLatest,
        'total_page_views_any_path' => $totalPageViewsDay,
        'signin_page_views'         => count($visits),
        'unique_visitors_signin_pages' => count($uniqueVisitors),
        'completed_checkins'        => count($checkedIn),
        'pre_checked_in_hibernation'=> count($preCheckedIn),
        'gps_ping_updates_only'     => count($gpsPingOnly),
        'best_estimate_tried'       => max(count($uniqueVisitors), count($checkedIn) + count($preCheckedIn)),
    ],
    'events_today'        => $eventsToday,
    'events_recent_window'=> $eventsRecent,
    'unique_visitors'     => array_values($uniqueVisitors),
    'completed_checkins'  => $checkedIn,
    'pre_checked_in'      => $preCheckedIn,
    'gps_ping_only'       => $gpsPingOnly,
    'approved_staff_pool' => array_map(static fn(array $r): array => [
        'name'  => trim((string) $r['first_name'] . ' ' . (string) $r['surname']),
        'email' => (string) ($r['email'] ?? ''),
        'event' => (string) ($r['event_name'] ?? ''),
    ], $approvedPool),
];

if ($isCli) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
