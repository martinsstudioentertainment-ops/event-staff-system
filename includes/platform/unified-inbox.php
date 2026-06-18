<?php

declare(strict_types=1);

require_once __DIR__ . '/platform-schema.php';
require_once __DIR__ . '/../notification-center.php';
require_once __DIR__ . '/../staff-messages.php';
require_once __DIR__ . '/payroll-intelligence.php';
require_once __DIR__ . '/google-sheets-control.php';

/**
 * Unified inbox item shape.
 *
 * @return array<int, array<string, mixed>>
 */
function listUnifiedInboxItems(PDO $pdo, array $filters = [], int $limit = 100, int $offset = 0): array
{
    ensurePlatformMaturitySchema($pdo);
    $limit  = max(1, min($limit, 200));
    $offset = max(0, $offset);
    $items = [];

    $includeNotifications = ($filters['type'] ?? 'all') === 'all' || ($filters['type'] ?? '') === 'notification';
    $includeMessages      = ($filters['type'] ?? 'all') === 'all' || ($filters['type'] ?? '') === 'message';
    $includePayroll       = ($filters['type'] ?? 'all') === 'all' || ($filters['type'] ?? '') === 'payroll';
    $includeGps           = ($filters['type'] ?? 'all') === 'all' || ($filters['type'] ?? '') === 'gps';
    $includeSheets        = ($filters['type'] ?? 'all') === 'all' || ($filters['type'] ?? '') === 'sheets';
    $showArchived         = !empty($filters['archived']);
    $search               = strtolower(trim((string) ($filters['q'] ?? '')));

    $archived = getArchivedInboxKeys($pdo);

    if ($includeNotifications) {
        foreach (getAdminNotifications($pdo, 80) as $n) {
            $key = 'notification:' . (int) $n['id'];
            if (isset($archived[$key]) !== $showArchived) {
                continue;
            }
            if (!$showArchived && !empty($n['is_read'])) {
                if (($filters['status'] ?? 'all') === 'unread') {
                    continue;
                }
            } elseif (($filters['status'] ?? 'all') === 'read' && empty($n['is_read'])) {
                continue;
            }
            $title = (string) ($n['title'] ?? '');
            $body  = (string) ($n['body'] ?? '');
            if ($search !== '' && !str_contains(strtolower($title . ' ' . $body), $search)) {
                continue;
            }
            $items[] = [
                'key'         => $key,
                'source_type' => 'notification',
                'source_id'   => (int) $n['id'],
                'category'    => 'notification',
                'title'       => $title,
                'body'        => $body,
                'is_read'     => !empty($n['is_read']),
                'action_url'  => (string) ($n['action_url'] ?? ''),
                'created_at'  => (string) ($n['created_at'] ?? ''),
            ];
        }
    }

    if ($includeMessages) {
        foreach (listStaffInboxThreads($pdo, 40) as $t) {
            $staffId = (int) ($t['staff_id'] ?? 0);
            $key     = 'message:' . $staffId;
            if (isset($archived[$key]) !== $showArchived) {
                continue;
            }
            $unread = (int) ($t['unread_count'] ?? 0) > 0;
            if (($filters['status'] ?? 'all') === 'unread' && !$unread) {
                continue;
            }
            if (($filters['status'] ?? 'all') === 'read' && $unread) {
                continue;
            }
            $name = trim((string) ($t['first_name'] ?? '') . ' ' . (string) ($t['surname'] ?? ''));
            $title = $name !== '' ? 'Message from ' . $name : 'Staff message';
            $body  = (string) ($t['last_body'] ?? '');
            if ($search !== '' && !str_contains(strtolower($title . ' ' . $body . ' ' . ($t['staff_email'] ?? '')), $search)) {
                continue;
            }
            $items[] = [
                'key'         => $key,
                'source_type' => 'message',
                'source_id'   => $staffId,
                'category'    => 'message',
                'title'       => $title,
                'body'        => $body,
                'is_read'     => !$unread,
                'action_url'  => 'staff-inbox-thread.php?staff_id=' . $staffId,
                'created_at'  => (string) ($t['last_at'] ?? ''),
            ];
        }
    }

    if ($includePayroll) {
        foreach (listPayrollAlerts($pdo, 30, $showArchived) as $a) {
            $key = 'payroll:' . (int) $a['id'];
            if (isset($archived[$key]) !== $showArchived) {
                continue;
            }
            $title = (string) ($a['title'] ?? 'Payroll alert');
            $body  = (string) ($a['body'] ?? '');
            if ($search !== '' && !str_contains(strtolower($title . ' ' . $body), $search)) {
                continue;
            }
            $items[] = [
                'key'         => $key,
                'source_type' => 'payroll',
                'source_id'   => (int) $a['id'],
                'category'    => 'payroll',
                'title'       => $title,
                'body'        => $body,
                'is_read'     => !empty($a['resolved_at']),
                'action_url'  => 'payroll-intelligence.php',
                'created_at'  => (string) ($a['created_at'] ?? ''),
            ];
        }
    }

    if ($includeGps) {
        try {
            $gpsRows = $pdo->query(
                "SELECT id, staff_email, event_id, attendance_status, updated_at
                 FROM attendance
                 WHERE attendance_status IN ('gps_failed','manual_review','no_show')
                 ORDER BY updated_at DESC
                 LIMIT 20"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($gpsRows as $g) {
                $attId = (int) $g['id'];
                $key   = 'gps_attendance:' . $attId;
                if (isset($archived[$key]) !== $showArchived) {
                    continue;
                }
                $title = 'GPS / attendance: ' . (string) ($g['attendance_status'] ?? '');
                $body  = (string) ($g['staff_email'] ?? '');
                if ($search !== '' && !str_contains(strtolower($title . ' ' . $body), $search)) {
                    continue;
                }
                $items[] = [
                    'key'         => $key,
                    'source_type' => 'gps_attendance',
                    'source_id'   => $attId,
                    'category'    => 'gps',
                    'title'       => $title,
                    'body'        => $body,
                    'is_read'     => false,
                    'action_url'  => 'attendance.php',
                    'created_at'  => (string) ($g['updated_at'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            // optional
        }
    }

    if ($includeSheets) {
        foreach (getRecentSheetsSyncFailures($pdo, 15) as $f) {
            $key = 'sheets:' . (int) $f['id'];
            if (isset($archived[$key]) !== $showArchived) {
                continue;
            }
            $title = 'Sheets sync failed';
            $body  = (string) ($f['detail'] ?? '');
            if ($search !== '' && !str_contains(strtolower($title . ' ' . $body), $search)) {
                continue;
            }
            $items[] = [
                'key'         => $key,
                'source_type' => 'sheets',
                'source_id'   => (int) $f['id'],
                'category'    => 'sheets',
                'title'       => $title,
                'body'        => $body,
                'is_read'     => false,
                'action_url'  => 'google-sheets-control.php',
                'created_at'  => (string) ($f['created_at'] ?? ''),
            ];
        }
    }

    usort($items, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });

    return array_slice($items, $offset, $limit);
}

/** @return array<string, true> */
function getArchivedInboxKeys(PDO $pdo): array
{
    ensurePlatformMaturitySchema($pdo);
    try {
        $rows = $pdo->query('SELECT source_type, source_id FROM platform_inbox_archive')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row['source_type'] . ':' . (int) $row['source_id']] = true;
    }

    return $out;
}

function archiveUnifiedInboxItem(PDO $pdo, string $sourceType, int $sourceId, ?int $adminUserId = null): bool
{
    ensurePlatformMaturitySchema($pdo);
    try {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO platform_inbox_archive (source_type, source_id, admin_user_id)
            VALUES (:type, :id, :admin)
        ");
        $stmt->execute([
            'type'  => $sourceType,
            'id'    => $sourceId,
            'admin' => $adminUserId,
        ]);

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function unarchiveUnifiedInboxItem(PDO $pdo, string $sourceType, int $sourceId): bool
{
    ensurePlatformMaturitySchema($pdo);
    try {
        $stmt = $pdo->prepare('DELETE FROM platform_inbox_archive WHERE source_type = :type AND source_id = :id');
        $stmt->execute(['type' => $sourceType, 'id' => $sourceId]);

        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function markUnifiedInboxItemRead(PDO $pdo, string $sourceType, int $sourceId): void
{
    if ($sourceType === 'notification') {
        markNotificationRead($pdo, $sourceId, 'admin');
    } elseif ($sourceType === 'message') {
        markStaffMessagesReadForAdmin($pdo, $sourceId);
    } elseif ($sourceType === 'payroll') {
        resolvePayrollAlert($pdo, $sourceId);
    }
}

/** @return array{total: int, unread: int, archived: int} */
function summarizeUnifiedInbox(PDO $pdo): array
{
    $all = listUnifiedInboxItems($pdo, ['status' => 'all'], 500);
    $unread = listUnifiedInboxItems($pdo, ['status' => 'unread'], 500);
    $archived = listUnifiedInboxItems($pdo, ['archived' => true], 500);

    return [
        'total'    => count($all),
        'unread'   => count($unread),
        'archived' => count($archived),
    ];
}
