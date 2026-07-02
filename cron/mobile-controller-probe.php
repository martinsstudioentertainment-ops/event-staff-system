<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$key = trim((string) ($_GET['key'] ?? ''));
$file = basename((string) ($_GET['file'] ?? ''));
if ($key !== 'email-encoding-verify-20260606' || $file === '') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$allowed = [
    'ConfigController.php',
    'AuthController.php',
    'ProfileController.php',
    'DashboardController.php',
    'ShiftsController.php',
    'AttendanceController.php',
    'NotificationsController.php',
    'MessagesController.php',
    'PushController.php',
    'DocumentsController.php',
    'AvailabilityController.php',
    'SyncController.php',
    'EventsController.php',
];
if (!in_array($file, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad file']);
    exit;
}

try {
    require_once dirname(__DIR__) . '/includes/mobile/controllers/' . $file;
    echo json_encode(['ok' => true, 'file' => $file]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'file'  => $file,
        'error' => $e->getMessage(),
        'path'  => $e->getFile(),
        'line'  => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
}
