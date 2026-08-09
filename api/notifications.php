<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
initSecureSession();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notification-center.php';
require_once __DIR__ . '/../includes/status-repository.php';
require_once __DIR__ . '/../includes/staff-portal-session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$audience = trim((string) ($_GET['audience'] ?? ''));

if ($audience === 'admin') {
    requireAdminApiSession();

    try {
        $pdo = getDB();
    } catch (Throwable $e) {
        error_log('[NotificationsAPI] admin DB: ' . $e->getMessage());
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Service unavailable']);
        exit;
    }

    $unread = countUnreadAdminNotifications($pdo);

    echo json_encode([
        'ok'     => true,
        'unread' => $unread,
    ]);
    exit;
}

if ($audience === 'staff') {
    try {
        $pdo = getDB();
    } catch (Throwable $e) {
        error_log('[NotificationsAPI] staff DB: ' . $e->getMessage());
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Service unavailable']);
        exit;
    }

    $email = '';

    $token = trim((string) ($_GET['token'] ?? ''));
    if ($token !== '') {
        $rows = getStaffStatusRows($pdo, $token);
        if ($rows !== []) {
            $email = strtolower(trim((string) ($rows[0]['email'] ?? '')));
        }
    }

    if ($email === '') {
        $portalStaff = getStaffFromPortalSession($pdo);
        if ($portalStaff !== null) {
            $email = strtolower(trim((string) ($portalStaff['email'] ?? '')));
        }
    }

    if ($email === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Staff identity required']);
        exit;
    }

    $unread = countUnreadStaffNotifications($pdo, $email);

    echo json_encode([
        'ok'     => true,
        'unread' => $unread,
        'email'  => $email,
    ]);
    exit;
}

http_response_code(200);
echo json_encode([
    'ok'      => false,
    'error'   => 'audience_required',
    'message' => 'Pass audience=admin or audience=staff.',
    'usage'   => [
        'admin' => '/api/notifications.php?audience=admin',
        'staff' => '/api/notifications.php?audience=staff&token=…',
    ],
]);
