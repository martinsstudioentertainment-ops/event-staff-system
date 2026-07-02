<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
initSecureSession();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/staff-broadcast.php';
require_once __DIR__ . '/../includes/staff-messages.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

requireAdminApiSession();

if (!adminCan('staff')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$query = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($query) < 2) {
    echo json_encode(['ok' => true, 'results' => []]);
    exit;
}

try {
    $pdo     = getDB();
    $results = searchStaffForAdminMessaging($pdo, $query, 20);
    $items   = [];

    foreach ($results as $row) {
        $staffId = (int) ($row['id'] ?? 0);
        if ($staffId < 1) {
            continue;
        }

        $items[] = [
            'id'    => $staffId,
            'name'  => getStaffDisplayNameFromRow($row),
            'email' => (string) ($row['email'] ?? ''),
        ];
    }

    echo json_encode(['ok' => true, 'results' => $items]);
} catch (Throwable $e) {
    error_log('[EventStaff] admin-staff-search: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Search failed']);
}
