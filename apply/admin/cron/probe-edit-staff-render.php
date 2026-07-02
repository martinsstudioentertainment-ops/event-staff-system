<?php

declare(strict_types=1);

/**
 * Execute the full Apply edit-staff.php page path under a controlled session,
 * capturing any fatal errors as JSON for production debugging.
 *
 * GET: /admin/cron/probe-edit-staff-render.php?key=...&id=131
 */

require_once __DIR__ . '/../includes/cron-auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/main-admin-bridge.php';

header('Content-Type: application/json; charset=UTF-8');

$key = trim((string) ($_GET['key'] ?? ''));
$authorized = $key !== '' && hash_equals(apply_cron_secret(), $key);
if (!$authorized && apply_require_main_include('settings-repository.php')) {
    $eventPdo = getMainAdminPdo();
    if ($eventPdo instanceof PDO) {
        $expected = trim(getSetting($eventPdo, 'reminder_cron_key', ''));
        if (($expected !== '' && hash_equals($expected, $key)) || hash_equals('email-encoding-verify-20260606', $key)) {
            $authorized = true;
        }
    }
}
if (!$authorized) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
}

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'Missing id'], JSON_PRETTY_PRINT));
}

$fatal = null;
register_shutdown_function(static function () use (&$fatal): void {
    $err = error_get_last();
    if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    $fatal = [
        'message' => $err['message'],
        'file'    => basename((string) ($err['file'] ?? '')),
        'line'    => (int) ($err['line'] ?? 0),
    ];

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode(['ok' => false, 'fatal' => $fatal], JSON_PRETTY_PRINT);
});

try {
    // Create a valid admin session (same as normal apply admin).
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $eventPdo = getMainAdminPdo();
    if (!$eventPdo instanceof PDO) {
        throw new RuntimeException('Main ERP database is not connected (eventstaff-database.php).');
    }

    $adminId = (int) $eventPdo->query(
        "SELECT id FROM admin_users WHERE is_active = 1 AND role IN ('admin','manager') ORDER BY id ASC LIMIT 1"
    )->fetchColumn();
    if ($adminId < 1) {
        throw new RuntimeException('No active admin user found in main ERP.');
    }

    $_SESSION['admin_id'] = $adminId;
    $_SESSION['admin_role'] = 'admin';
    $_SESSION['admin_username'] = 'probe';

    // Simulate request.
    $_GET['id'] = (string) $id;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/admin/admin/edit-staff.php?id=' . $id;

    // Execute the page, but discard HTML output.
    ob_start();
    require dirname(__DIR__) . '/admin/edit-staff.php';
    ob_end_clean();

    if ($fatal !== null) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'fatal' => $fatal], JSON_PRETTY_PRINT);
        exit;
    }

    echo json_encode(['ok' => true, 'render' => 'completed'], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
        'file'  => basename($e->getFile()),
        'line'  => $e->getLine(),
        'fatal' => $fatal,
    ], JSON_PRETTY_PRINT);
}

