<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-portal-dashboard.php';
require_once __DIR__ . '/status-repository.php';
require_once __DIR__ . '/attendance-repository.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/date-format.php';

/**
 * @return list<array<string, mixed>>
 */
function getStaffV3ShiftRows(PDO $pdo, string $email, string $statusToken, int $staffId = 0): array
{
    $email = strtolower(trim($email));
    if ($email === '' && $staffId < 1) {
        return [];
    }

    if ($statusToken !== '') {
        try {
            return getStaffStatusRows($pdo, $statusToken, $staffId);
        } catch (Throwable $e) {
            error_log('[EventStaff] getStaffV3ShiftRows(token): ' . $e->getMessage());

            return [];
        }
    }

    try {
        $match = staffRegistrationMatchClause($email, $staffId);
        $stmt = $pdo->prepare(
            'SELECT sr.*, e.name AS event_name, e.event_date,
                    a.id AS attendance_id, a.checked_in_at, a.checked_out_at,
                    a.hours_worked, a.attendance_status,
                    CASE WHEN a.id IS NULL THEN 0 ELSE 1 END AS is_checked_in
             FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id
             LEFT JOIN attendance a ON a.registration_id = sr.id
             WHERE ' . $match['sql'] . '
             ORDER BY e.event_date ASC, sr.created_at ASC'
        );
        $stmt->execute($match['params']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $r) use ($pdo): array {
            $r = mergeRegistrationWithStaff($pdo, $r);
            $r = mergeRegistrationWithEvent($pdo, $r);

            return $r;
        }, $rows);
    } catch (Throwable $e) {
        error_log('[EventStaff] getStaffV3ShiftRows: ' . $e->getMessage());

        return [];
    }
}

/**
 * @param list<array<string, mixed>> $rows
 */
function getStaffV3TodayShift(array $rows, ?PDO $pdo = null): ?array
{
    $today = getOperationalTodayYmd($pdo);

    foreach ($rows as $row) {
        if ((string) ($row['status'] ?? '') !== 'approved') {
            continue;
        }
        $eventDate = substr((string) ($row['event_date'] ?? ''), 0, 10);
        if ($eventDate === $today) {
            return $row;
        }
    }

    return null;
}

/**
 * Today's shift for the home dashboard — approved first, otherwise pending application for today.
 *
 * @param list<array<string, mixed>> $rows
 */
function getStaffV3TodayDashboardShift(array $rows, ?PDO $pdo = null): ?array
{
    $approved = getStaffV3TodayShift($rows, $pdo);
    if ($approved !== null) {
        return $approved;
    }

    $today = getOperationalTodayYmd($pdo);

    foreach ($rows as $row) {
        if ((string) ($row['status'] ?? '') !== 'pending') {
            continue;
        }
        $eventDate = substr((string) ($row['event_date'] ?? ''), 0, 10);
        if ($eventDate === $today) {
            return $row;
        }
    }

    return null;
}

/**
 * @return array{hours_worked: float, hours_paid: float, checkins: int, earnings: ?float}
 */
function getStaffV3MonthlyStats(PDO $pdo, string $email, int $staffId): array
{
    $email = strtolower(trim($email));
    $empty = ['hours_worked' => 0.0, 'hours_paid' => 0.0, 'checkins' => 0, 'earnings' => null];

    if ($email === '' && $staffId < 1) {
        return $empty;
    }

    $monthStart = date('Y-m-01 00:00:00');

    try {
        $match = staffRegistrationMatchClause($email, $staffId);
        $effectiveCheckIn = 'COALESCE(a.checked_in_at, a.activated_at, a.check_in_gps_at)';
        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(a.hours_worked), 0) AS hours_worked,
                COALESCE(SUM(a.hours_paid), 0) AS hours_paid,
                COUNT(a.id) AS checkins
             FROM attendance a
             INNER JOIN staff_registrations sr ON sr.id = a.registration_id
             WHERE " . $match['sql'] . "
               AND {$effectiveCheckIn} >= :month_start"
        );
        $stmt->execute(array_merge($match['params'], ['month_start' => $monthStart]));
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $hoursWorked = round((float) ($row['hours_worked'] ?? 0), 1);
        $hoursPaid   = round((float) ($row['hours_paid'] ?? 0), 1);
        $checkins    = (int) ($row['checkins'] ?? 0);

        $earnings = null;
        if ($staffId > 0 && $hoursPaid > 0) {
            require_once __DIR__ . '/automation/payroll-repository.php';
            $rate = payroll_default_hourly_rate($pdo, $staffId);
            if ($rate > 0) {
                $earnings = round($hoursPaid * $rate, 2);
            }
        }

        return [
            'hours_worked' => $hoursWorked,
            'hours_paid'   => $hoursPaid,
            'checkins'     => $checkins,
            'earnings'     => $earnings,
        ];
    } catch (Throwable $e) {
        error_log('[EventStaff] getStaffV3MonthlyStats: ' . $e->getMessage());

        return $empty;
    }
}

/**
 * @return list<array<string, mixed>>
 */
function getStaffV3CheckinHistory(PDO $pdo, string $email, int $limit = 12, int $staffId = 0): array
{
    $email = strtolower(trim($email));
    if ($email === '' && $staffId < 1) {
        return [];
    }

    try {
        $match = staffRegistrationMatchClause($email, $staffId);
        $effectiveCheckIn = 'COALESCE(a.checked_in_at, a.activated_at, a.check_in_gps_at)';
        $stmt = $pdo->prepare(
            "SELECT a.checked_in_at, a.checked_out_at, a.hours_worked, a.attendance_status,
                    a.activated_at, a.check_in_gps_at,
                    e.name AS event_name, e.location AS event_location, e.event_date
             FROM attendance a
             INNER JOIN staff_registrations sr ON sr.id = a.registration_id
             INNER JOIN events e ON e.id = sr.event_id
             WHERE " . $match['sql'] . "
             ORDER BY {$effectiveCheckIn} DESC
             LIMIT " . max(1, min(50, $limit))
        );
        $stmt->execute($match['params']);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[EventStaff] getStaffV3CheckinHistory: ' . $e->getMessage());

        return [];
    }
}

function getStaffV3EmployerLabel(array $row, string $fallback): string
{
    $company = trim((string) ($row['main_security_company'] ?? ''));
    if ($company !== '') {
        return $company;
    }

    return $fallback;
}

/**
 * Shift outcome for admin profile, staff app cards, and status pages.
 *
 * @return array{label: string, badge: string, code: string, tone: string}
 */
function resolveStaffShiftOutcomeMeta(array $row): array
{
    $regStatus = (string) ($row['status'] ?? 'pending');

    if ($regStatus === 'pending') {
        return ['label' => 'Pending approval', 'badge' => 'pending', 'code' => 'pending', 'tone' => 'warn'];
    }
    if ($regStatus === 'rejected') {
        return ['label' => 'Not approved', 'badge' => 'rejected', 'code' => 'rejected', 'tone' => 'danger'];
    }
    if ($regStatus !== 'approved') {
        $label = function_exists('formatStatusLabel') ? formatStatusLabel($regStatus) : ucfirst($regStatus);

        return ['label' => $label, 'badge' => $regStatus, 'code' => $regStatus, 'tone' => 'muted'];
    }

    $hasAttendance = !empty($row['attendance_id']) || (int) ($row['is_checked_in'] ?? 0) === 1;
    $attStatus     = strtolower(trim((string) ($row['attendance_status'] ?? '')));

    if ($hasAttendance) {
        if ($attStatus === 'no_show') {
            return ['label' => 'No-show', 'badge' => 'noshow', 'code' => 'no_show', 'tone' => 'danger'];
        }
        if ($attStatus === 'gps_failed') {
            return ['label' => 'GPS failed', 'badge' => 'rejected', 'code' => 'gps_failed', 'tone' => 'danger'];
        }
        if ($attStatus === 'manual_review') {
            return ['label' => 'Under review', 'badge' => 'pending', 'code' => 'manual_review', 'tone' => 'warn'];
        }

        $window      = getEventCheckinWindow($row);
        $checkedOut  = trim((string) ($row['checked_out_at'] ?? '')) !== '';
        $windowAfter = ($window['status'] ?? '') === 'after';

        if (!$windowAfter && !$checkedOut && in_array($attStatus, ['', 'active', 'pre_checked_in'], true)) {
            return ['label' => 'Checked in', 'badge' => 'checked-in', 'code' => 'checked_in', 'tone' => 'success'];
        }

        $hours = round((float) ($row['hours_worked'] ?? 0), 1);
        $label = $hours > 0 ? sprintf('Completed (%.1fh)', $hours) : 'Completed';

        return ['label' => $label, 'badge' => 'completed', 'code' => 'completed', 'tone' => 'success'];
    }

    $window       = getEventCheckinWindow($row);
    $windowStatus = (string) ($window['status'] ?? 'before');

    if ($windowStatus === 'before') {
        return ['label' => 'Upcoming', 'badge' => 'upcoming', 'code' => 'upcoming', 'tone' => 'info'];
    }
    if ($windowStatus === 'open') {
        return ['label' => 'Awaiting check-in', 'badge' => 'awaiting', 'code' => 'awaiting_checkin', 'tone' => 'warn'];
    }

    return ['label' => 'No-show', 'badge' => 'noshow', 'code' => 'no_show', 'tone' => 'danger'];
}

function getStaffV3ShiftStatusMeta(array $row): array
{
    $outcome = resolveStaffShiftOutcomeMeta($row);

    return [
        'label' => $outcome['label'],
        'tone'  => $outcome['tone'],
    ];
}

function formatStaffV3ShiftTime(array $row): string
{
    $start = trim((string) ($row['event_start_time'] ?? $row['start_time'] ?? ''));
    $end   = trim((string) ($row['event_end_time'] ?? $row['end_time'] ?? ''));

    if ($start !== '' && $end !== '') {
        return $start . ' – ' . $end;
    }
    if ($start !== '') {
        return $start;
    }

    return '—';
}

function getStaffV3ShiftHoursLabel(array $row): string
{
    $start = trim((string) ($row['event_start_time'] ?? $row['start_time'] ?? ''));
    $end   = trim((string) ($row['event_end_time'] ?? $row['end_time'] ?? ''));

    if ($start === '' || $end === '') {
        return '';
    }

    try {
        $s = new DateTime('2000-01-01 ' . $start);
        $e = new DateTime('2000-01-01 ' . $end);
        if ($e <= $s) {
            $e->modify('+1 day');
        }
        $diff = $s->diff($e);
        $hours = $diff->h + ($diff->i / 60);

        return $hours > 0 ? number_format($hours, 1) . ' hrs' : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Scheduled shift length in hours (from event start/end).
 */
function getStaffV3ShiftScheduledHours(array $row): float
{
    $start = trim((string) ($row['event_start_time'] ?? $row['start_time'] ?? ''));
    $end   = trim((string) ($row['event_end_time'] ?? $row['end_time'] ?? ''));
    if ($start === '' || $end === '') {
        return 0.0;
    }

    try {
        $s = new DateTime('2000-01-01 ' . $start);
        $e = new DateTime('2000-01-01 ' . $end);
        if ($e <= $s) {
            $e->modify('+1 day');
        }
        $diff  = $s->diff($e);

        return round($diff->h + ($diff->i / 60) + ($diff->s / 3600), 2);
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * Live or completed shift time progress for cards.
 *
 * @return array{percent: int, label: string, state: string, hours_worked: float, hours_scheduled: float}
 */
function getStaffV3ShiftTimeProgress(array $row): array
{
    $hoursWorked    = round((float) ($row['hours_worked'] ?? 0), 1);
    $hoursScheduled = getStaffV3ShiftScheduledHours($row);
    $eventDate      = normalizeEventDateYmd((string) ($row['event_date'] ?? ''));
    $today          = date('Y-m-d');
    $start          = trim((string) ($row['event_start_time'] ?? $row['start_time'] ?? ''));
    $end            = trim((string) ($row['event_end_time'] ?? $row['end_time'] ?? ''));

    $percent = 0;
    $label   = '';
    $state   = 'upcoming';

    if ($hoursWorked > 0) {
        $state   = 'done';
        $label   = $hoursWorked . ' hrs worked';
        $percent = $hoursScheduled > 0
            ? (int) min(100, round(($hoursWorked / $hoursScheduled) * 100))
            : 100;
    } elseif ($eventDate === $today && $start !== '' && $end !== '') {
        try {
            $now     = new DateTime();
            $startDt = new DateTime($today . ' ' . $start);
            $endDt   = new DateTime($today . ' ' . $end);
            if ($endDt <= $startDt) {
                $endDt->modify('+1 day');
            }
            if ($now < $startDt) {
                $state   = 'upcoming';
                $label   = 'Starts ' . $start;
                $percent = 0;
            } elseif ($now > $endDt) {
                $state   = 'done';
                $label   = 'Shift ended';
                $percent = 100;
            } else {
                $state      = 'live';
                $totalSecs  = max(1, $endDt->getTimestamp() - $startDt->getTimestamp());
                $elapsedSec = max(0, $now->getTimestamp() - $startDt->getTimestamp());
                $percent    = (int) min(100, round(($elapsedSec / $totalSecs) * 100));
                $elapsedH   = round($elapsedSec / 3600, 1);
                $totalH     = round($totalSecs / 3600, 1);
                $label      = $elapsedH . ' / ' . $totalH . ' hrs';
            }
        } catch (Throwable $e) {
            $label = $hoursScheduled > 0 ? number_format($hoursScheduled, 1) . ' hrs scheduled' : '';
        }
    } elseif ($hoursScheduled > 0) {
        $label = number_format($hoursScheduled, 1) . ' hrs scheduled';
    }

    return [
        'percent'          => $percent,
        'label'            => $label,
        'state'            => $state,
        'hours_worked'     => $hoursWorked,
        'hours_scheduled'  => $hoursScheduled,
    ];
}

function getStaffV3CheckinUrl(PDO $pdo, array $row): string
{
    unset($pdo, $row);

    return 'staff-checkin.php';
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<string>
 */
function getStaffV3EmployerFilters(array $rows, string $fallback): array
{
    $employers = [];
    foreach ($rows as $row) {
        $label = getStaffV3EmployerLabel($row, $fallback);
        if ($label !== '') {
            $employers[$label] = true;
        }
    }

    $list = array_keys($employers);
    sort($list);

    return $list;
}
