<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/notification-center.php';
require_once __DIR__ . '/date-format.php';

/**
 * @return array{
 *   total: int,
 *   approved: int,
 *   pending: int,
 *   rejected: int,
 *   upcoming: int,
 *   completed: int,
 *   checked_in: int,
 *   has_data: bool
 * }
 */
function getStaffPortalDashboardMetrics(PDO $pdo, string $email): array
{
    $email = strtolower(trim($email));
    $empty = [
        'total'      => 0,
        'approved'   => 0,
        'pending'    => 0,
        'rejected'   => 0,
        'upcoming'   => 0,
        'completed'  => 0,
        'checked_in' => 0,
        'has_data'   => false,
    ];

    if ($email === '') {
        return $empty;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN sr.status = 'approved' THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN sr.status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN sr.status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                SUM(CASE WHEN sr.status = 'approved' AND e.event_date >= CURDATE() THEN 1 ELSE 0 END) AS upcoming,
                SUM(CASE WHEN sr.status = 'approved' AND e.event_date < CURDATE() THEN 1 ELSE 0 END) AS completed
             FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id
             WHERE LOWER(sr.email) = :email"
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $checkStmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT a.id)
             FROM attendance a
             INNER JOIN staff_registrations sr ON sr.id = a.registration_id
             WHERE LOWER(sr.email) = :email"
        );
        $checkStmt->execute(['email' => $email]);
        $checkedIn = (int) $checkStmt->fetchColumn();

        return [
            'total'      => (int) ($row['total'] ?? 0),
            'approved'   => (int) ($row['approved'] ?? 0),
            'pending'    => (int) ($row['pending'] ?? 0),
            'rejected'   => (int) ($row['rejected'] ?? 0),
            'upcoming'   => (int) ($row['upcoming'] ?? 0),
            'completed'  => (int) ($row['completed'] ?? 0),
            'checked_in' => $checkedIn,
            'has_data'   => (int) ($row['total'] ?? 0) > 0,
        ];
    } catch (Throwable $e) {
        error_log('[EventStaff] getStaffPortalDashboardMetrics: ' . $e->getMessage());

        return $empty;
    }
}

/**
 * @return list<array{kind: string, title: string, detail: string, time: string, time_label: string, url: string}>
 */
function getStaffPortalActivityFeed(PDO $pdo, string $email, int $limit = 6, string $statusToken = ''): array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return [];
    }

    $statusPageUrl = buildStaffStatusPageUrl($statusToken);

    $items = [];

    foreach (getStaffNotifications($pdo, $email, 8) as $notif) {
        $items[] = [
            'kind'       => mapNotificationActivityKind((string) ($notif['type'] ?? '')),
            'title'      => (string) ($notif['title'] ?? 'Notification'),
            'detail'     => trim((string) ($notif['body'] ?? '')),
            'time'       => (string) ($notif['created_at'] ?? ''),
            'time_label' => formatStaffPortalRelativeTime((string) ($notif['created_at'] ?? '')),
            'url'        => (string) ($notif['action_url'] ?? 'staff-notifications.php'),
            'sort_ts'    => strtotime((string) ($notif['created_at'] ?? '')) ?: 0,
        ];
    }

    try {
        $regStmt = $pdo->prepare(
            "SELECT sr.created_at, sr.status, e.name AS event_name
             FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id
             WHERE LOWER(sr.email) = :email
             ORDER BY sr.created_at DESC
             LIMIT 5"
        );
        $regStmt->execute(['email' => $email]);
        foreach ($regStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $reg) {
            $status = (string) ($reg['status'] ?? 'pending');
            $items[] = [
                'kind'       => $status === 'approved' ? 'approved' : ($status === 'rejected' ? 'rejected' : 'submitted'),
                'title'      => $status === 'approved'
                    ? 'Shift approved'
                    : ($status === 'rejected' ? 'Application update' : 'Application submitted'),
                'detail'     => (string) ($reg['event_name'] ?? 'Event'),
                'time'       => (string) ($reg['created_at'] ?? ''),
                'time_label' => formatStaffPortalRelativeTime((string) ($reg['created_at'] ?? '')),
                'url'        => $statusPageUrl,
                'sort_ts'    => strtotime((string) ($reg['created_at'] ?? '')) ?: 0,
            ];
        }

        $attStmt = $pdo->prepare(
            "SELECT a.checked_in_at, e.name AS event_name
             FROM attendance a
             INNER JOIN staff_registrations sr ON sr.id = a.registration_id
             INNER JOIN events e ON e.id = a.event_id
             WHERE LOWER(sr.email) = :email
             ORDER BY a.checked_in_at DESC
             LIMIT 5"
        );
        $attStmt->execute(['email' => $email]);
        foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $att) {
            $items[] = [
                'kind'       => 'checkin',
                'title'      => 'Venue check-in completed',
                'detail'     => (string) ($att['event_name'] ?? 'Event'),
                'time'       => (string) ($att['checked_in_at'] ?? ''),
                'time_label' => formatStaffPortalRelativeTime((string) ($att['checked_in_at'] ?? '')),
                'url'        => $statusPageUrl,
                'sort_ts'    => strtotime((string) ($att['checked_in_at'] ?? '')) ?: 0,
            ];
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] getStaffPortalActivityFeed: ' . $e->getMessage());
    }

    usort($items, static fn(array $a, array $b): int => ($b['sort_ts'] ?? 0) <=> ($a['sort_ts'] ?? 0));

    $out = [];
    $seen = [];
    foreach ($items as $item) {
        $key = ($item['kind'] ?? '') . '|' . ($item['title'] ?? '') . '|' . ($item['time'] ?? '');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        unset($item['sort_ts']);
        $out[] = $item;
        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

function mapNotificationActivityKind(string $type): string
{
    $type = strtolower(trim($type));

    return match (true) {
        str_contains($type, 'approv') => 'approved',
        str_contains($type, 'reject') => 'rejected',
        str_contains($type, 'remind'), str_contains($type, 'event') => 'reminder',
        str_contains($type, 'check'), str_contains($type, 'attend') => 'checkin',
        str_contains($type, 'regist'), str_contains($type, 'submit') => 'submitted',
        default => 'notification',
    };
}

function formatStaffPortalRelativeTime(string $datetime): string
{
    $datetime = trim($datetime);
    if ($datetime === '') {
        return '';
    }

    try {
        $then = new DateTime($datetime);
        $now  = new DateTime('now', $then->getTimezone());
        $diff = $now->getTimestamp() - $then->getTimestamp();

        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            $m = (int) floor($diff / 60);

            return $m . ' min ago';
        }
        if ($diff < 86400) {
            $h = (int) floor($diff / 3600);

            return $h . ' hr ago';
        }
        if ($diff < 604800) {
            $d = (int) floor($diff / 86400);

            return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
        }

        return formatEventDateLabel($then->format('Y-m-d'));
    } catch (Throwable $e) {
        return '';
    }
}

function getStaffPortalDisplayName(?array $staff, ?PDO $pdo = null): string
{
    if ($staff === null || $staff === []) {
        return 'Staff member';
    }

    $name = trim((string) ($staff['first_name'] ?? '') . ' ' . (string) ($staff['surname'] ?? ''));

    if ($name !== '') {
        return $name;
    }

    $email = strtolower(trim((string) ($staff['email'] ?? '')));
    if ($pdo !== null && $email !== '') {
        try {
            $stmt = $pdo->prepare(
                'SELECT first_name, surname FROM staff_registrations
                 WHERE LOWER(email) = :email
                 ORDER BY created_at DESC LIMIT 1'
            );
            $stmt->execute(['email' => $email]);
            $reg = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($reg)) {
                $regName = trim((string) ($reg['first_name'] ?? '') . ' ' . (string) ($reg['surname'] ?? ''));
                if ($regName !== '') {
                    return $regName;
                }
            }
        } catch (Throwable $e) {
            error_log('[EventStaff] getStaffPortalDisplayName: ' . $e->getMessage());
        }
    }

    return 'Staff member';
}

function getStaffPortalRoleLabel(PDO $pdo, ?array $staff, string $email): string
{
    require_once __DIR__ . '/registration-forms.php';

    if ($staff !== null && trim((string) ($staff['staff_role'] ?? '')) !== '') {
        return formatStaffRoleLabel((string) $staff['staff_role'], $pdo);
    }

    $email = strtolower(trim($email));
    if ($email !== '') {
        try {
            $stmt = $pdo->prepare(
                'SELECT staff_role FROM staff_registrations
                 WHERE LOWER(email) = :email
                 ORDER BY created_at DESC LIMIT 1'
            );
            $stmt->execute(['email' => $email]);
            $role = trim((string) ($stmt->fetchColumn() ?: ''));
            if ($role !== '') {
                return formatStaffRoleLabel($role, $pdo);
            }
        } catch (Throwable $e) {
            error_log('[EventStaff] getStaffPortalRoleLabel: ' . $e->getMessage());
        }
    }

    return 'Event Staff';
}

function formatStaffPortalStaffId(?array $staff): string
{
    if ($staff === null || $staff === []) {
        return '';
    }

    $id = (int) ($staff['id'] ?? 0);

    return $id > 0 ? 'STF-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT) : '';
}

function getStaffPortalAvatarInitials(?array $staff): string
{
    if ($staff === null || $staff === []) {
        return '👤';
    }

    $first = trim((string) ($staff['first_name'] ?? ''));
    $last  = trim((string) ($staff['surname'] ?? ''));
    $ini   = strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));

    return $ini !== '' ? $ini : '👤';
}

function getStaffPortalStatusBadge(?array $staff, array $metrics): array
{
    if ($staff === null || $staff === []) {
        return ['label' => 'Guest', 'tone' => 'neutral'];
    }

    if (empty($staff['profile_completed_at'])) {
        return ['label' => 'Profile needed', 'tone' => 'warn'];
    }

    if (($metrics['approved'] ?? 0) > 0) {
        return ['label' => 'Active staff', 'tone' => 'success'];
    }

    if (($metrics['pending'] ?? 0) > 0) {
        return ['label' => 'Awaiting review', 'tone' => 'pending'];
    }

    return ['label' => 'Registered', 'tone' => 'info'];
}

function buildStaffStatusPageUrl(string $token, string $filter = ''): string
{
    $url = $token !== '' ? 'status.php?token=' . urlencode($token) : 'status.php';
    $filter = strtolower(trim($filter));
    if ($filter !== '' && $filter !== 'all') {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'filter=' . urlencode($filter);
    }

    return $url;
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function filterStaffStatusRows(array $rows, string $filter): array
{
    $filter = strtolower(trim($filter));
    if ($filter === '' || $filter === 'all' || $filter === 'total') {
        return $rows;
    }

    $today = date('Y-m-d');

    return array_values(array_filter($rows, static function (array $row) use ($filter, $today): bool {
        $status    = (string) ($row['status'] ?? '');
        $eventDate = (string) ($row['event_date'] ?? '');

        return match ($filter) {
            'approved'  => $status === 'approved',
            'pending'   => $status === 'pending',
            'rejected'  => $status === 'rejected',
            'upcoming'  => $status === 'approved' && $eventDate >= $today,
            'completed' => $status === 'approved' && $eventDate < $today,
            default     => true,
        };
    }));
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array{total: int, approved: int, pending: int, rejected: int, upcoming: int, completed: int, checked_in: int, has_data: bool}
 */
function computeStaffStatusMetricsFromRows(array $rows): array
{
    $today = date('Y-m-d');
    $metrics = [
        'total'      => count($rows),
        'approved'   => 0,
        'pending'    => 0,
        'rejected'   => 0,
        'upcoming'   => 0,
        'completed'  => 0,
        'checked_in' => 0,
        'has_data'   => $rows !== [],
    ];

    foreach ($rows as $row) {
        $status = (string) ($row['status'] ?? '');
        if ($status === 'approved') {
            $metrics['approved']++;
            $eventDate = (string) ($row['event_date'] ?? '');
            if ($eventDate >= $today) {
                $metrics['upcoming']++;
            } else {
                $metrics['completed']++;
            }
        } elseif ($status === 'pending') {
            $metrics['pending']++;
        } elseif ($status === 'rejected') {
            $metrics['rejected']++;
        }
        if ((int) ($row['is_checked_in'] ?? 0) === 1) {
            $metrics['checked_in']++;
        }
    }

    return $metrics;
}

function formatStaffStatusVenueLabel(array $row): string
{
    $venue = trim((string) ($row['venue_name'] ?? ''));
    if ($venue !== '') {
        return $venue;
    }

    $location = trim((string) ($row['event_location'] ?? $row['location'] ?? ''));
    if ($location !== '') {
        return $location;
    }

    $reporting = trim((string) ($row['reporting_point'] ?? ''));

    return $reporting !== '' ? $reporting : '—';
}

function formatStaffStatusShiftLabel(array $row): string
{
    $role = formatRoleLabel((string) ($row['staff_role'] ?? ''));
    $start = trim((string) ($row['event_start_time'] ?? $row['start_time'] ?? ''));
    $end   = trim((string) ($row['event_end_time'] ?? $row['end_time'] ?? ''));

    if ($start !== '' && $end !== '') {
        return $role . ' · ' . $start . '–' . $end;
    }

    return $role !== '' ? $role : '—';
}
