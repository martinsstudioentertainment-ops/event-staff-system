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
    if (!isAdminLoggedIn()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $unread = countUnreadAdminNotifications(getDB());

    echo json_encode([
        'ok'     => true,
        'unread' => $unread,
    ]);
    exit;
}

if ($audience === 'staff') {
    $pdo   = getDB();
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

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Invalid audience']);
