<?php

require_once __DIR__ . '/config.php';
initSecureSession();

require_once __DIR__ . '/includes/notification-center.php';
require_once __DIR__ . '/includes/status-repository.php';
require_once __DIR__ . '/includes/staff-repository.php';
require_once __DIR__ . '/includes/staff-app-v3-pages.php';
require_once __DIR__ . '/includes/staff-portal-remember.php';

$pdo   = getDB();
$token = trim((string) ($_GET['token'] ?? $_POST['status_token'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    if (verifyCsrf($_POST['csrf_token'] ?? null)) {
        $email = '';
        $portalStaff = getStaffFromPortalSession($pdo);
        if ($portalStaff !== null) {
            $email = strtolower(trim((string) ($portalStaff['email'] ?? '')));
        } elseif ($token !== '') {
            $rows = getStaffStatusRows($pdo, $token);
            if ($rows !== []) {
                $email = strtolower(trim((string) ($rows[0]['email'] ?? '')));
            }
        }
        if ($email !== '') {
            markAllNotificationsRead($pdo, 'staff', $email);
        }
    }

    $redirect = 'staff-notifications.php';
    if ($token !== '') {
        $redirect .= '?token=' . urlencode($token);
    }
    header('Location: ' . $redirect);
    exit;
}

$portalStaff = getStaffFromPortalSession($pdo);
if ($portalStaff === null) {
    restoreStaffPortalFromRememberCookie($pdo);
    $portalStaff = getStaffFromPortalSession($pdo);
}

if ($portalStaff === null && $token !== '') {
    $rows = getStaffStatusRows($pdo, $token);
    if ($rows !== []) {
        $staffId = ensureStaffRecordForEmail($pdo, (string) ($rows[0]['email'] ?? ''));
        if ($staffId !== null) {
            $staff = getStaffById($pdo, $staffId);
            if ($staff !== null) {
                establishStaffPortalSessionWithRemember($pdo, $staff);
                $portalStaff = $staff;
            }
        }
    }
}

if ($portalStaff === null) {
    $return = 'staff-notifications.php' . ($token !== '' ? '?token=' . urlencode($token) : '');
    header('Location: staff-app.php?return=' . urlencode($return));
    exit;
}

$ctx = buildStaffV3Context($pdo, $portalStaff);
if ($token !== '') {
    $ctx['status_token'] = $token;
}
$email         = (string) ($ctx['staff_email'] ?? '');
$notifications = $email !== '' ? getStaffNotifications($pdo, $email, 80) : [];
$unreadCount   = $email !== '' ? countUnreadStaffNotifications($pdo, $email) : 0;
$notifUrl      = 'staff-notifications.php' . ($token !== '' ? '?token=' . urlencode($token) : '');

renderStaffV3PageStart($ctx, 'home', 'Notifications');
renderStaffV3NotificationsPage($ctx, $notifications, $unreadCount, $notifUrl);
renderStaffV3PageEnd($ctx);
