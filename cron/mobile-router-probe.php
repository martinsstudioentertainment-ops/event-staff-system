<?php

declare(strict_types=1);

/**
 * Mobile router include diagnostic — read-only.
 */

require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$key = trim((string) ($_GET['key'] ?? ''));
if ($key !== 'email-encoding-verify-20260606') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$base = dirname(__DIR__) . '/includes/mobile/controllers/';
$controllers = [
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

$steps = [];
foreach ($controllers as $file) {
    try {
        require_once $base . $file;
        $steps[] = ['file' => $file, 'ok' => true];
    } catch (Throwable $e) {
        $steps[] = [
            'file'  => $file,
            'ok'    => false,
            'error' => $e->getMessage(),
            'line'  => $e->getLine(),
            'path'  => $e->getFile(),
        ];
    }
}

echo json_encode(['ok' => true, 'steps' => $steps], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
