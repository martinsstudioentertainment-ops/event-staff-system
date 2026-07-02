<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/staff-app-v3-shell.php';
require_once dirname(__DIR__) . '/includes/staff-app-v3-pages.php';
require_once dirname(__DIR__) . '/includes/staff-portal-shift.php';

header('Content-Type: application/json; charset=utf-8');

$key = trim((string) ($_GET['key'] ?? ''));
if ($key !== 'email-encoding-verify-20260606') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$email = strtolower(trim((string) ($_GET['email'] ?? 'martinsstudioentertainment@gmail.com')));
$steps = [];

try {
    $pdo = getDB();
    $steps[] = ['step' => 'getDB', 'ok' => true];

    $staff = getStaffByEmail($pdo, $email);
    $steps[] = [
        'step'  => 'getStaffByEmail',
        'ok'    => $staff !== null,
        'role'  => $staff['staff_role'] ?? null,
        'id'    => $staff['id'] ?? null,
    ];

    if ($staff === null) {
        echo json_encode(['ok' => false, 'steps' => $steps, 'error' => 'Staff not found'], JSON_PRETTY_PRINT);
        exit;
    }

    $ctx = buildStaffV3Context($pdo, $staff);
    $steps[] = ['step' => 'buildStaffV3Context', 'ok' => true, 'profile_complete' => $ctx['profile_complete'] ?? null];

    ob_start();
    renderStaffV3HomePage($ctx);
    $html = ob_get_clean();
    $steps[] = ['step' => 'renderStaffV3HomePage', 'ok' => true, 'bytes' => strlen($html)];

    echo json_encode(['ok' => true, 'email' => $email, 'steps' => $steps], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    $steps[] = [
        'step'  => 'exception',
        'ok'    => false,
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ];
    echo json_encode(['ok' => false, 'email' => $email, 'steps' => $steps], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
