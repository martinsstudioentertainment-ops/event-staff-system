<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!hash_equals('email-encoding-verify-20260606', $key)) {
        http_response_code(403);
        echo json_encode(['ok' => false]);
        exit;
    }

    $event = $pdo->query(
        "SELECT id, name, event_date, start_time, end_time, updated_at
         FROM events WHERE name LIKE '%Kingfishr%' ORDER BY event_date DESC LIMIT 3"
    )->fetchAll(PDO::FETCH_ASSOC);

    $audit = [];
    if ($pdo->query("SHOW TABLES LIKE 'admin_audit_log'")->fetchColumn()) {
        $stmt = $pdo->prepare(
            "SELECT created_at, admin_username, action, target_type, target_id, details
             FROM admin_audit_log
             WHERE (details LIKE '%Kingfishr%' OR details LIKE '%start_time%' OR details LIKE '%end_time%'
                    OR details LIKE '%event%')
               AND created_at >= '2026-06-12'
             ORDER BY created_at DESC LIMIT 40"
        );
        $stmt->execute();
        $audit = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    echo json_encode(['ok' => true, 'events' => $event, 'audit' => $audit], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
