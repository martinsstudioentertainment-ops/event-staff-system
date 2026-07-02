<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$key = trim((string) ($_GET['key'] ?? ''));
$rel = trim((string) ($_GET['path'] ?? ''));
if ($key !== 'email-encoding-verify-20260606' || $rel === '' || str_contains($rel, '..')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$full = dirname(__DIR__) . '/includes/' . ltrim($rel, '/');
if (!is_file($full)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'missing', 'path' => $rel]);
    exit;
}

try {
    require_once dirname(__DIR__) . '/config.php';
    require_once $full;
    echo json_encode(['ok' => true, 'path' => $rel]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'path'  => $rel,
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
}
