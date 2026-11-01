<?php

declare(strict_types=1);

/**
 * List duplicate approved registration pairs (read-only probe).
 * GET: /cron/probe-duplicate-registrations.php?key=...
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';

header('Content-Type: application/json; charset=UTF-8');

$pdo = getDB();
$key = trim((string) ($_GET['key'] ?? ''));
if (!productionHealthAuthorize($pdo, $key)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

$rows = $pdo->query(
    "SELECT sr.staff_id, sr.event_id, e.name AS event_name, e.event_date,
            COUNT(*) AS cnt, GROUP_CONCAT(sr.id ORDER BY sr.id) AS registration_ids,
            GROUP_CONCAT(sr.status ORDER BY sr.id) AS statuses
     FROM staff_registrations sr
     INNER JOIN events e ON e.id = sr.event_id
     WHERE sr.staff_id IS NOT NULL AND sr.staff_id > 0 AND sr.status = 'approved'
     GROUP BY sr.staff_id, sr.event_id
     HAVING cnt > 1
     ORDER BY e.event_date DESC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo json_encode(['ok' => true, 'count' => count($rows), 'pairs' => $rows], JSON_PRETTY_PRINT);
