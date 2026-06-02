<?php

require_once __DIR__ . '/auth.php';

function logAdminAudit(
    PDO $pdo,
    string $action,
    string $targetType = '',
    ?int $targetId = null,
    ?string $details = null
): void {
    require_once __DIR__ . '/system-settings.php';

    if (!isActivityLoggingEnabled($pdo)) {
        return;
    }

    try {
        $admin = getAdminUser();
        $stmt  = $pdo->prepare(
            'INSERT INTO admin_audit_log (admin_id, admin_username, action, target_type, target_id, details, ip_address)
             VALUES (:admin_id, :admin_username, :action, :target_type, :target_id, :details, :ip_address)'
        );
        $stmt->execute([
            'admin_id'        => $admin ? (int) $admin['id'] : null,
            'admin_username'  => $admin ? (string) ($admin['username'] ?? '') : 'system',
            'action'          => $action,
            'target_type'     => $targetType !== '' ? $targetType : null,
            'target_id'       => $targetId,
            'details'         => $details,
            'ip_address'      => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        ]);
    } catch (Throwable $e) {
        // Never block admin actions if audit logging fails.
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function getAuditLogEntries(PDO $pdo, int $limit = 100, int $offset = 0): array
{
    $limit  = max(1, min($limit, 500));
    $offset = max(0, $offset);

    $sql = 'SELECT * FROM admin_audit_log ORDER BY created_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

    return $pdo->query($sql)->fetchAll();
}

/**
 * @return array<int, array<string, mixed>>
 */
function getAdminLoginAuditEntries(PDO $pdo, int $limit = 100): array
{
    $limit = max(1, min($limit, 500));
    $stmt  = $pdo->prepare(
        "SELECT * FROM admin_audit_log WHERE action = 'login' ORDER BY created_at DESC, id DESC LIMIT " . $limit
    );
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

function formatAuditActionLabel(string $action): string
{
    $labels = [
        'login'              => 'Admin login',
        'status_change'      => 'Status change',
        'bulk_status_change' => 'Bulk status change',
        'admin_checkin'      => 'Manual check-in',
        'export_staff'       => 'Staff CSV export',
        'export_attendance'  => 'Attendance CSV export',
        'staff_email'        => 'Staff email broadcast',
        'event_save'         => 'Event saved',
        'scan_checkin'       => 'QR scan check-in',
        'user_create'        => 'Admin user created',
        'user_update'        => 'Admin user updated',
        'user_deactivate'    => 'Admin user deactivated',
        'export_backup'      => 'Site backup exported',
        'staff_blacklist'    => 'Staff blacklisted',
        'staff_unblacklist'  => 'Staff removed from blacklist',
        'database_backup'    => 'Database backup',
        'go_live_schema'     => 'Go-live schema update',
        'purge_demo_invoices'=> 'Demo invoices removed',
    ];

    return $labels[$action] ?? ucwords(str_replace('_', ' ', $action));
}
