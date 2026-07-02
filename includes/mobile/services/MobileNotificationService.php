<?php

declare(strict_types=1);

require_once __DIR__ . '/../../notification-center.php';
require_once __DIR__ . '/../mobile-rate-limit.php';
require_once __DIR__ . '/../mappers/MobileNotificationMapper.php';

function mobileNotificationReadThrottle(int $staffId): void
{
    mobileThrottleOrFail('notifications_read_' . $staffId, 120, 60);
}

function mobileNotificationWriteThrottle(int $staffId): void
{
    mobileThrottleOrFail('notifications_write_' . $staffId, 60, 60);
}

/**
 * @return array<string, mixed>|null
 */
function mobileGetStaffNotificationById(PDO $pdo, int $id, string $email): ?array
{
    $email = strtolower(trim($email));
    if ($id < 1 || $email === '') {
        return null;
    }

    ensureNotificationCenterSchema($pdo);

    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM app_notifications
             WHERE id = :id AND audience = 'staff' AND LOWER(staff_email) = :email
             LIMIT 1"
        );
        $stmt->execute(['id' => $id, 'email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    } catch (PDOException $e) {
        error_log('[MobileAPI] getStaffNotificationById: ' . $e->getMessage());

        return null;
    }
}

/**
 * @param array<string, mixed> $query
 * @return array{ok: true, notifications: list, unread_count: int, pagination: array, categories: array}|array{ok: false, message: string, code: string, status: int}
 */
function mobileNotificationServiceList(PDO $pdo, array $staff, array $query): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    $email   = strtolower(trim((string) ($staff['email'] ?? '')));

    mobileNotificationReadThrottle($staffId);

    if ($email === '') {
        return [
            'ok'      => false,
            'message' => 'Staff email is required.',
            'code'    => 'FORBIDDEN',
            'status'  => 403,
        ];
    }

    $page       = max(1, (int) ($query['page'] ?? 1));
    $perPage    = max(1, min(50, (int) ($query['per_page'] ?? 50)));
    $unreadOnly = filter_var($query['unread_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $offset     = ($page - 1) * $perPage;

    ensureNotificationCenterSchema($pdo);

    $where  = "audience = 'staff' AND LOWER(staff_email) = :email";
    $params = ['email' => $email];
    if ($unreadOnly) {
        $where .= ' AND is_read = 0';
    }

    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM app_notifications WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT * FROM app_notifications WHERE {$where} ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('[MobileAPI] notification list: ' . $e->getMessage());

        return [
            'ok'      => false,
            'message' => 'Could not load notifications.',
            'code'    => 'ERROR',
            'status'  => 500,
        ];
    }

    $notifications = [];
    foreach ($rows as $row) {
        $row['action_url'] = resolveStaffNotificationActionUrl(
            $pdo,
            (string) ($row['action_url'] ?? '')
        );
        $notifications[] = mobileMapNotificationRow($row);
    }

    return [
        'ok'             => true,
        'notifications'  => $notifications,
        'unread_count'   => countUnreadStaffNotifications($pdo, $email),
        'pagination'     => [
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ],
        'categories'     => array_values(mobileNotificationTypeCatalog()),
    ];
}

/**
 * @return array{ok: true, notification: array}|array{ok: false, message: string, code: string, status: int}
 */
function mobileNotificationServiceMarkRead(PDO $pdo, array $staff, int $notificationId): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    $email   = strtolower(trim((string) ($staff['email'] ?? '')));

    mobileNotificationWriteThrottle($staffId);

    if ($notificationId < 1) {
        return [
            'ok'      => false,
            'message' => 'Invalid notification ID.',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 422,
        ];
    }

    $row = mobileGetStaffNotificationById($pdo, $notificationId, $email);
    if ($row === null) {
        return [
            'ok'      => false,
            'message' => 'Notification not found.',
            'code'    => 'NOT_FOUND',
            'status'  => 404,
        ];
    }

    if ((int) ($row['is_read'] ?? 0) === 1) {
        $row['action_url'] = resolveStaffNotificationActionUrl($pdo, (string) ($row['action_url'] ?? ''));

        return [
            'ok'           => true,
            'notification' => mobileMapNotificationRow($row),
            'already_read' => true,
        ];
    }

    if (!markNotificationRead($pdo, $notificationId, 'staff', $email)) {
        return [
            'ok'      => false,
            'message' => 'Notification not found.',
            'code'    => 'NOT_FOUND',
            'status'  => 404,
        ];
    }

    $after = mobileGetStaffNotificationById($pdo, $notificationId, $email);
    if ($after !== null) {
        $after['action_url'] = resolveStaffNotificationActionUrl($pdo, (string) ($after['action_url'] ?? ''));
    }

    return [
        'ok'           => true,
        'notification' => mobileMapNotificationRow($after ?? $row),
        'already_read' => false,
    ];
}

/**
 * @return array{ok: true, marked: int, unread_count: int}|array{ok: false, message: string, code: string, status: int}
 */
function mobileNotificationServiceMarkAllRead(PDO $pdo, array $staff): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    $email   = strtolower(trim((string) ($staff['email'] ?? '')));

    mobileNotificationWriteThrottle($staffId);

    if ($email === '') {
        return [
            'ok'      => false,
            'message' => 'Staff email is required.',
            'code'    => 'FORBIDDEN',
            'status'  => 403,
        ];
    }

    $marked = markAllNotificationsRead($pdo, 'staff', $email);

    return [
        'ok'           => true,
        'marked'       => $marked,
        'unread_count' => countUnreadStaffNotifications($pdo, $email),
    ];
}
