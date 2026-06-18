<?php

declare(strict_types=1);

/**
 * Background Google Sheets queue worker ping (admin session).
 * Keeps the queue draining while the admin console is open — no cPanel cron required.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/google-sheets-auto-worker.php';

requireAdmin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $pdo     = getDB();
    $payload = googleSheetsAutoWorkerPing($pdo);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Worker ping failed']);
}
