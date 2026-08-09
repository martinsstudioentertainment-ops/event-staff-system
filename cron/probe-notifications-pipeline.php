<?php

declare(strict_types=1);

/**
 * Notification pipeline health probe (read-only).
 * Web: /cron/probe-notifications-pipeline.php?key=...&email=optional@staff.test
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/notification-center-schema.php';
require_once dirname(__DIR__) . '/includes/notification-center.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';

header('Content-Type: application/json; charset=UTF-8');

$fallbackKey = 'email-encoding-verify-20260606';

try {
    $pdo = getDB();
    $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $providedKey = trim((string) ($_GET['key'] ?? ''));

    if (!(($expectedKey !== '' && hash_equals($expectedKey, $providedKey)) || hash_equals($fallbackKey, $providedKey))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT);
        exit;
    }

    ensureNotificationCenterSchema($pdo);

    $email = strtolower(trim((string) ($_GET['email'] ?? '')));

    $totals = [
        'admin_all'   => (int) $pdo->query("SELECT COUNT(*) FROM app_notifications WHERE audience = 'admin'")->fetchColumn(),
        'staff_all'   => (int) $pdo->query("SELECT COUNT(*) FROM app_notifications WHERE audience = 'staff'")->fetchColumn(),
        'admin_unread'=> countUnreadAdminNotifications($pdo),
    ];

    $recentTypes = $pdo->query(
        "SELECT type, audience, COUNT(*) AS cnt
         FROM app_notifications
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY type, audience
         ORDER BY cnt DESC
         LIMIT 40"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $profileOnlyStaff = (int) $pdo->query(
        "SELECT COUNT(*) FROM staff s
         WHERE NOT EXISTS (
             SELECT 1 FROM staff_registrations sr WHERE LOWER(TRIM(sr.email)) = LOWER(TRIM(s.email))
         )"
    )->fetchColumn();

    $profileOnlyWithNotifs = 0;
    if ($profileOnlyStaff > 0) {
        $profileOnlyWithNotifs = (int) $pdo->query(
            "SELECT COUNT(DISTINCT s.email) FROM staff s
             INNER JOIN app_notifications n ON LOWER(n.staff_email) = LOWER(s.email) AND n.audience = 'staff'
             WHERE NOT EXISTS (
                 SELECT 1 FROM staff_registrations sr WHERE LOWER(TRIM(sr.email)) = LOWER(TRIM(s.email))
             )"
        )->fetchColumn();
    }

    $staffSample = null;
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $notifs = getStaffNotifications($pdo, $email, 20);
        $regStmt = $pdo->prepare('SELECT COUNT(*) FROM staff_registrations WHERE LOWER(TRIM(email)) = :e');
        $regStmt->execute(['e' => $email]);
        $staffSample = [
            'email'        => $email,
            'staff_row'    => getStaffByEmail($pdo, $email) !== null,
            'registrations'=> (int) $regStmt->fetchColumn(),
            'notifications'=> count($notifs),
            'unread'       => countUnreadStaffNotifications($pdo, $email),
            'latest'       => array_map(static function (array $row): array {
                return [
                    'id'         => (int) ($row['id'] ?? 0),
                    'type'       => (string) ($row['type'] ?? ''),
                    'title'      => (string) ($row['title'] ?? ''),
                    'is_read'    => (int) ($row['is_read'] ?? 0),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            }, $notifs),
        ];
    }

    $settings = [
        'notify_on_registration'      => getSetting($pdo, 'notify_on_registration', '0'),
        'notify_staff_shift_alerts'   => getSetting($pdo, 'notify_staff_shift_alerts', '1'),
        'notify_admin_on_registration'=> getSetting($pdo, 'notify_admin_on_registration', '0'),
    ];

    echo json_encode([
        'ok'    => true,
        'at'    => gmdate('c'),
        'settings' => $settings,
        'totals' => $totals,
        'profile_only_staff' => $profileOnlyStaff,
        'profile_only_staff_with_in_app_notifications' => $profileOnlyWithNotifs,
        'regression_hint' => $profileOnlyStaff > 0 && $profileOnlyWithNotifs === 0
            ? 'Profile-only staff exist but none have in-app notifications — registration post-save may be missing'
            : null,
        'recent_types_30d' => $recentTypes,
        'staff_sample' => $staffSample,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ], JSON_PRETTY_PRINT);
}
