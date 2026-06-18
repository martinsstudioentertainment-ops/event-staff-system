<?php

require_once __DIR__ . '/config.php';
initSecureSession();

require_once __DIR__ . '/includes/notification-center.php';
require_once __DIR__ . '/includes/staff-app-v3-pages.php';
require_once __DIR__ . '/includes/staff-portal-remember.php';

$pdo = getDB();
restoreStaffPortalFromRememberCookie($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    if (verifyCsrf($_POST['csrf_token'] ?? null)) {
        $portalStaff = getStaffFromPortalSession($pdo);
        if ($portalStaff !== null) {
            $email = strtolower(trim((string) ($portalStaff['email'] ?? '')));
            if ($email !== '') {
                markAllNotificationsRead($pdo, 'staff', $email);
            }
        }
    }

    header('Location: staff-notifications.php');
    exit;
}

$token = trim((string) ($_GET['token'] ?? ''));
if ($token !== '') {
    $portalStaff = getStaffFromPortalSession($pdo);
    if ($portalStaff !== null) {
        header('Location: staff-notifications.php');
        exit;
    }

    if (!restoreStaffPortalFromRememberCookie($pdo)) {
        header('Location: staff-app.php?return=' . urlencode('staff-notifications.php'));
        exit;
    }
}

$portalStaff = staffV3RequireSignIn($pdo);
$ctx         = buildStaffV3Context($pdo, $portalStaff);
$email       = (string) ($ctx['staff_email'] ?? '');
$notifications = $email !== '' ? getStaffNotifications($pdo, $email, 80) : [];
$unreadCount   = $email !== '' ? countUnreadStaffNotifications($pdo, $email) : 0;

renderStaffV3PageStart($ctx, 'home', 'Notifications');
renderStaffV3NotificationsPage($ctx, $notifications, $unreadCount, 'staff-notifications.php');
renderStaffV3PageEnd($ctx);
