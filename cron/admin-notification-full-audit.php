<?php

declare(strict_types=1);

/**
 * Read-only Admin Notification system audit.
 *
 *   ?key=CRON_KEY
 *   ?key=CRON_KEY&since=2026-06-27
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/notification-center.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $since = trim((string) ($_GET['since'] ?? '2026-06-27 00:00:00'));

    $schemaCols = [];
    try {
        $schemaCols = $pdo->query('SHOW COLUMNS FROM app_notifications')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $schemaCols = ['error' => $e->getMessage()];
    }

    $typeCounts = $pdo->query(
        "SELECT audience, type, COUNT(*) AS cnt,
                SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_cnt
         FROM app_notifications
         GROUP BY audience, type
         ORDER BY audience, type"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $gapStmt = $pdo->prepare(
        "SELECT sr.id, sr.email, sr.first_name, sr.surname, sr.staff_role, sr.status,
                sr.allocation_type, sr.created_at, e.name AS event_name
         FROM staff_registrations sr
         LEFT JOIN events e ON e.id = sr.event_id
         WHERE sr.created_at >= :since
           AND NOT EXISTS (
               SELECT 1 FROM app_notifications n
               WHERE n.audience = 'admin' AND n.type = 'registration' AND n.related_id = sr.id
           )
         ORDER BY sr.id ASC"
    );
    $gapStmt->execute(['since' => $since]);
    $registrationGaps = $gapStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $recentRegs = $pdo->prepare(
        "SELECT sr.id, sr.email, sr.first_name, sr.surname, sr.staff_role, sr.status,
                sr.allocation_type, sr.admin_assigned_by, sr.override_reason, sr.created_at,
                e.name AS event_name,
                (SELECT n.id FROM app_notifications n
                 WHERE n.audience = 'admin' AND n.type = 'registration' AND n.related_id = sr.id
                 LIMIT 1) AS admin_notif_id
         FROM staff_registrations sr
         LEFT JOIN events e ON e.id = sr.event_id
         WHERE sr.created_at >= :since
         ORDER BY sr.id DESC
         LIMIT 25"
    );
    $recentRegs->execute(['since' => $since]);
    $recentRegistrationRows = $recentRegs->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $readAtBroken = false;
    try {
        $pdo->query("SELECT COUNT(*) FROM app_notifications WHERE audience = 'admin' AND read_at IS NULL");
    } catch (Throwable $e) {
        $readAtBroken = true;
    }

    $validationRollbackGuard = false;
    $validationPath = dirname(__DIR__) . '/includes/validation.php';
    if (is_readable($validationPath)) {
        $src = (string) file_get_contents($validationPath);
        $validationRollbackGuard = str_contains($src, 'inTransaction()');
    }

    $mobilePostSaveGap = false;
    $mobilePath = dirname(__DIR__) . '/includes/mobile/services/MobileEventsService.php';
    if (is_readable($mobilePath)) {
        $src = (string) file_get_contents($mobilePath);
        $mobilePostSaveGap = str_contains($src, 'saveRegistrations(')
            && !str_contains($src, 'runRegistrationPostSave');
    }

    $pipelineSources = [
        [
            'type'              => 'registration',
            'label'             => 'New registration (web submit.php)',
            'trigger'           => 'runRegistrationPostSaveJobs → notifyAdminNewRegistration',
            'paths'             => ['submit.php', 'includes/registration-post-save.php'],
            'creates_admin_row' => true,
            'gap_risk'          => 'Post-save runs after fastcgi_finish_request; silent catch on failure',
        ],
        [
            'type'              => 'registration',
            'label'             => 'New registration (mobile API)',
            'trigger'           => 'mobileEventsServiceRegister',
            'paths'             => ['includes/mobile/services/MobileEventsService.php'],
            'creates_admin_row' => false,
            'gap_risk'          => 'saveRegistrations() only — no runRegistrationPostSaveJobs()',
        ],
        [
            'type'              => 'registration',
            'label'             => 'Admin assignment',
            'trigger'           => 'adminAssignStaffToEvent',
            'paths'             => ['includes/staff-allocation.php'],
            'creates_admin_row' => false,
            'gap_risk'          => 'By design — admin-initiated, no admin notification',
        ],
        [
            'type'              => 'checkin',
            'label'             => 'Staff check-in',
            'trigger'           => 'notifyAdminStaffCheckin',
            'paths'             => ['includes/notification-center.php', 'includes/notifications.php'],
            'creates_admin_row' => 'only if notify_ops_on_checkin=1',
            'gap_risk'          => 'Early return when setting off — no in-app row',
        ],
        [
            'type'              => 'staff_message',
            'label'             => 'Staff message to admin',
            'trigger'           => 'notifyAdminInApp(staff_message)',
            'paths'             => ['includes/staff-messages.php', 'includes/mobile/services/MobileMessageService.php'],
            'creates_admin_row' => true,
        ],
        [
            'type'              => 'master_staff_identity_alert',
            'label'             => 'Master Staff Identity alert',
            'trigger'           => 'canonicalIdentitySendIntegrityAlerts',
            'paths'             => ['includes/platform/canonical-identity.php'],
            'creates_admin_row' => true,
        ],
        [
            'type'              => 'status_approved / status_rejected',
            'label'             => 'Staff approval/rejection',
            'trigger'           => 'notifyStaffStatusInApp',
            'paths'             => ['includes/notification-center.php', 'includes/status-change-post-save.php'],
            'creates_admin_row' => false,
            'audience'          => 'staff only',
        ],
    ];

    $uiPipeline = [
        'admin_bell' => [
            'location'  => 'includes/admin/header-bar.php',
            'behavior'  => 'Link to notifications.php (no dropdown panel)',
            'badge'     => 'Server-rendered erp-header__notif-badge on page load',
        ],
        'admin_sidebar_badge' => [
            'location'  => 'includes/admin/sidebar.php [data-admin-notif-badge]',
            'behavior'  => 'JS poll every 120s via api/notifications.php?audience=admin',
        ],
        'admin_api' => [
            'endpoint'  => 'api/notifications.php?audience=admin',
            'returns'   => 'unread count only (not notification list)',
        ],
        'admin_page' => [
            'endpoint'  => 'admin/notifications.php',
            'returns'   => 'Full list via getAdminNotifications() server-side',
        ],
        'mark_read' => [
            'endpoint'  => 'api/notifications-mark-read.php POST',
            'js'        => 'assets/js/notifications.js',
        ],
        'unified_inbox' => [
            'endpoint'  => 'admin/unified-inbox.php',
            'merges'    => 'app_notifications + messages + payroll + sheets + gps',
        ],
        'system_health_unread' => [
            'bug'       => $readAtBroken,
            'detail'    => 'Uses read_at column which does not exist; always reports 0 unread',
            'file'      => 'includes/admin/system-health.php',
        ],
    ];

    $settings = [
        'notify_admin_on_registration' => getSetting($pdo, 'notify_admin_on_registration', '1'),
        'notify_admin_email'           => getSetting($pdo, 'notify_admin_email', ''),
        'notify_ops_on_checkin'        => getSetting($pdo, 'notify_ops_on_checkin', '0'),
        'notify_staff_enabled'         => getSetting($pdo, 'notify_staff_enabled', '1'),
        'company_email'                => getSetting($pdo, 'company_email', ''),
    ];

    echo json_encode([
        'ok'       => true,
        'since'    => $since,
        'generated_at' => gmdate('c'),
        'summary'  => [
            'unread_admin'              => countUnreadAdminNotifications($pdo),
            'total_admin'               => countAllNotifications($pdo, 'admin'),
            'registration_gaps_since'   => count($registrationGaps),
            'validation_rollback_guard' => $validationRollbackGuard,
            'mobile_api_missing_post_save' => $mobilePostSaveGap,
            'system_health_read_at_bug' => $readAtBroken,
        ],
        'schema' => [
            'app_notifications_columns' => $schemaCols,
            'has_read_at'               => in_array('read_at', $schemaCols, true),
            'uses_is_read'              => in_array('is_read', $schemaCols, true),
        ],
        'settings' => $settings,
        'admin_alert_emails' => getAdminAlertEmails($pdo),
        'notification_type_counts' => $typeCounts,
        'registration_gaps' => $registrationGaps,
        'recent_registrations' => $recentRegistrationRows,
        'pipeline_sources' => $pipelineSources,
        'ui_pipeline' => $uiPipeline,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
