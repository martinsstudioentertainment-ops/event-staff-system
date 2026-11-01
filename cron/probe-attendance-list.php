<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';
require_once dirname(__DIR__) . '/includes/admin-pagination.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
    }

    $eventId = (int) ($_GET['event_id'] ?? 12);
    $page    = max(1, (int) ($_GET['page'] ?? 1));

    $total = countAttendanceList($pdo, $eventId);
    $pageList = getAttendanceList($pdo, $eventId, adminListPerPage(), adminListOffset($page));
    $fullList = getAttendanceList($pdo, $eventId);
    $stats = getAttendanceStats($pdo, $eventId);

    $event = $pdo->prepare('SELECT id, name, event_date, location, start_time, end_time FROM events WHERE id = :id');
    $event->execute(['id' => $eventId]);
    $eventRow = $event->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'event' => $eventRow,
        'stats' => $stats,
        'total_approved_in_list_query' => $total,
        'page' => $page,
        'per_page' => adminListPerPage(),
        'page_list_count' => count($pageList),
        'full_list_count' => count($fullList),
        'page_names' => array_map(static fn ($r) => trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['surname'] ?? '')), $pageList),
        'all_names' => array_map(static fn ($r) => trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['surname'] ?? '')), $fullList),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
