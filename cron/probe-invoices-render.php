<?php

declare(strict_types=1);

/**
 * Simulate logged-in admin render of invoices.php (diagnostic only).
 */

require_once dirname(__DIR__) . '/config.php';
initSecureSession();
require_once dirname(__DIR__) . '/includes/platform/production-health.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
    }

    $stmt = $pdo->query('SELECT id, username, full_name, email, role FROM admin_users WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$admin) {
        exit(json_encode(['ok' => false, 'error' => 'No active admin user']));
    }

    $_SESSION['admin_id']       = (int) $admin['id'];
    $_SESSION['admin_username'] = (string) $admin['username'];
    $_SESSION['admin_name']     = (string) ($admin['full_name'] ?? $admin['username']);
    $_SESSION['admin_role']     = (string) ($admin['role'] ?? 'admin');
    $_SESSION['admin_email']    = (string) ($admin['email'] ?? '');
    $_SESSION['app_session_last_activity'] = time();

    ob_start();
    $cwd = getcwd();
    chdir(dirname(__DIR__) . '/admin');
    try {
        include 'invoices.php';
        $html = ob_get_clean();
        chdir($cwd);
        echo json_encode([
            'ok'         => true,
            'html_len'   => strlen($html),
            'has_table'  => str_contains($html, 'data-table'),
            'has_error'  => str_contains(strtolower($html), 'fatal error'),
        ], JSON_PRETTY_PRINT);
    } catch (Throwable $inner) {
        ob_end_clean();
        chdir($cwd);
        throw $inner;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
        'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 8),
    ], JSON_PRETTY_PRINT);
}
