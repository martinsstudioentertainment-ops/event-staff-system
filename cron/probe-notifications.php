<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/notification-center.php';
require_once dirname(__DIR__) . '/includes/pwa-push.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $settings = [
        'notify_admin_on_registration' => getSetting($pdo, 'notify_admin_on_registration', '1'),
        'notify_admin_email'           => getSetting($pdo, 'notify_admin_email', ''),
        'notify_ops_on_checkin'        => getSetting($pdo, 'notify_ops_on_checkin', '0'),
        'notify_staff_enabled'         => getSetting($pdo, 'notify_staff_enabled', '1'),
        'notify_staff_shift_alerts'    => getSetting($pdo, 'notify_staff_shift_alerts', '1'),
        'pwa_push_enabled'             => getSetting($pdo, 'pwa_push_enabled', '1'),
        'fcm_project_id'               => getSetting($pdo, 'fcm_project_id', ''),
        'fcm_service_account_path'     => getSetting($pdo, 'fcm_service_account_path', ''),
        'company_email'                => getSetting($pdo, 'company_email', ''),
    ];

    $adminEmails = getAdminAlertEmails($pdo);

    $recentAdmin = $pdo->query(
        "SELECT id, type, title, created_at, is_read FROM app_notifications
         WHERE audience = 'admin' ORDER BY id DESC LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $recentStaff = $pdo->query(
        "SELECT id, staff_email, type, title, created_at, is_read FROM app_notifications
         WHERE audience = 'staff' ORDER BY id DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $unreadAdmin = countUnreadAdminNotifications($pdo);

    $fcmActive = 0;
    $fcmTotal  = 0;
    try {
        $fcmTotal  = (int) $pdo->query('SELECT COUNT(*) FROM fcm_device_tokens')->fetchColumn();
        $fcmActive = (int) $pdo->query(
            'SELECT COUNT(*) FROM fcm_device_tokens t INNER JOIN staff s ON s.id = t.staff_id WHERE t.is_active = 1'
        )->fetchColumn();
    } catch (Throwable $e) {
    }

    $pushSubs = 0;
    try {
        $pushSubs = (int) $pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn();
    } catch (Throwable $e) {
    }

    $recentRegs = $pdo->query(
        "SELECT id, email, surname, first_name, created_at FROM staff_registrations ORDER BY id DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $regsWithNotif = [];
    foreach ($recentRegs as $reg) {
        $rid = (int) ($reg['id'] ?? 0);
        $stmt = $pdo->prepare(
            "SELECT id, created_at FROM app_notifications WHERE audience = 'admin' AND related_id = :rid AND type = 'registration' LIMIT 1"
        );
        $stmt->execute(['rid' => $rid]);
        $regsWithNotif[$rid] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $adminSince27 = $pdo->query(
        "SELECT COUNT(*) FROM app_notifications WHERE audience = 'admin' AND created_at >= '2026-06-27 00:00:00'"
    )->fetchColumn();

    $adminRegSince27 = $pdo->query(
        "SELECT COUNT(*) FROM app_notifications WHERE audience = 'admin' AND type = 'registration' AND created_at >= '2026-06-27 00:00:00'"
    )->fetchColumn();

    $regsSince27 = (int) $pdo->query(
        "SELECT COUNT(*) FROM staff_registrations WHERE created_at >= '2026-06-27 00:00:00'"
    )->fetchColumn();

    echo json_encode([
        'ok'       => true,
        'settings' => $settings,
        'admin_alert_emails' => $adminEmails,
        'unread_admin_notifications' => $unreadAdmin,
        'pwa_push_configured' => isPwaPushConfigured($pdo),
        'pwa_push_enabled'    => isPwaPushEnabled($pdo),
        'fcm_tokens_total'    => $fcmTotal,
        'fcm_tokens_active'   => $fcmActive,
        'pwa_push_subscriptions' => $pushSubs,
        'recent_admin_notifications' => $recentAdmin,
        'recent_staff_notifications' => $recentStaff,
        'recent_registrations' => $recentRegs,
        'registration_admin_notif_map' => $regsWithNotif,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
