<?php

declare(strict_types=1);

/**
 * Verify system-health include path does not leak JSON Content-Type.
 * GET: ?key=...
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/admin/system-health.php';

    ob_start();
    getProductionHealthSnapshot($pdo);
    ob_end_clean();

    $leakedJson = false;
    foreach (headers_list() as $headerLine) {
        if (stripos($headerLine, 'Content-Type: application/json') === 0) {
            $leakedJson = true;
            break;
        }
    }

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok'                       => !$leakedJson,
        'json_header_leaked'       => $leakedJson,
        'headers_before_response'  => headers_list(),
        'simulated_script'         => (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
