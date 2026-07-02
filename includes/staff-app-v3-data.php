<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-portal-dashboard.php';
require_once __DIR__ . '/status-repository.php';
require_once __DIR__ . '/attendance-repository.php';
require_once __DIR__ . '/checkin-bib.php';
require_once __DIR__ . '/date-format.php';

/**
 * Mobile/API shift list by staff profile id when email lookup returns no rows.
 *
 * @return list<array<string, mixed>>
 */
function getStaffV3ShiftRowsByStaffId(PDO $pdo, int $staffId): array
{
    if ($staffId < 1) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT sr.*, e.name AS event_name, e.event_date, e.whatsapp_group_url,
                    a.id AS attendance_id, a.checked_in_at, a.checked_out_at,
                    a.hours_worked, a.attendance_status, a.bib_number,
                    CASE WHEN a.id IS NULL THEN 0 ELSE 1 END AS is_checked_in
             FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id
             LEFT JOIN attendance a ON a.registration_id = sr.id
             WHERE sr.staff_id = :staff_id
             ORDER BY e.event_date ASC, sr.created_at ASC'
        );
        $stmt->execute(['staff_id' => $staffId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        require_once __DIR__ . '/staff-repository.php';

        return array_map(static function (array $r) use ($pdo): array {
            $r = mergeRegistrationWithStaff($pdo, $r);
            $r = mergeRegistrationWithEvent($pdo, $r);
            $r['display_bib_number'] = resolveStaffDisplayBibNumber($r);

            return $r;
        }, $rows);
    } catch (Throwable $e) {
        error_log('[EventStaff] getStaffV3ShiftRowsByStaffId: ' . $e->getMessage());

        return [];
    }
}

/**
 * @return list<array<string, mixed>>
 */
function getStaffV3ShiftRows(PDO $pdo, string $email, string $statusToken): array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return [];
    }

    if ($statusToken !== '') {
        try {
            $tokenRows = getStaffStatusRows($pdo, $statusToken);
            if ($tokenRows !== []) {
                return $tokenRows;
            }
        } catch (Throwable $e) {
            error_log('[EventStaff] getStaffV3ShiftRows(token): ' . $e->getMessage());
        }
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT sr.*, e.name AS event_name, e.event_date, e.whatsapp_group_url,
                    a.id AS attendance_id, a.checked_in_at, a.checked_out_at,
                    a.hours_worked, a.attendance_status, a.bib_number,
                    CASE WHEN a.id IS NULL THEN 0 ELSE 1 END AS is_checked_in
             FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id
             LEFT JOIN attendance a ON a.registration_id = sr.id
             WHERE LOWER(sr.email) = :email
             ORDER BY e.event_date ASC, sr.created_at ASC'
        );
        $stmt->execute(['email' => $email]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        require_once __DIR__ . '/staff-repository.php';

        return array_map(static function (array $r) use ($pdo): array {
            $r = mergeRegistrationWithStaff($pdo, $r);
            $r = mergeRegistrationWithEvent($pdo, $r);
            $r['display_bib_number'] = resolveStaffDisplayBibNumber($r);

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
    $today            = getOperationalTodayYmd($pdo);
    $activeOvernight  = null;

    foreach ($rows as $row) {
        if ((string) ($row['status'] ?? '') !== 'approved') {
            continue;
        }
        $eventDate = substr((string) ($row['event_date'] ?? ''), 0, 10);
        if ($eventDate === $today) {
            return $row;
        }
        if ($activeOvernight === null && staffV3ShiftIsActiveForDisplay($row, $pdo)) {
            $window = staffV3ResolveShiftScheduledWindow($row, $pdo);
            if ($window !== null) {
                $now = new DateTime();
                if ($now >= $window['start'] && $now <= $window['end']) {
                    $activeOvernight = $row;
                }
            }
        }
    }

    return $activeOvernight;
}

/**
 * @return array{hours_worked: float, hours_paid: float, checkins: int, earnings: ?float}
 */
function getStaffV3MonthlyStats(PDO $pdo, string $email, int $staffId): array
{
    $email = strtolower(trim($email));
    $empty = ['hours_worked' => 0.0, 'hours_paid' => 0.0, 'checkins' => 0, 'earnings' => null];

    if ($email === '') {
        return $empty;
    }

    $monthStart = date('Y-m-01 00:00:00');

    try {
        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE
                    WHEN a.checked_out_at IS NOT NULL
                     AND TRIM(a.checked_out_at) <> ''
                     AND a.checked_out_at > '1970-01-01 00:00:00'
                    THEN a.hours_worked ELSE 0 END), 0) AS hours_worked,
                COALESCE(SUM(CASE
                    WHEN a.checked_out_at IS NOT NULL
                     AND TRIM(a.checked_out_at) <> ''
                     AND a.checked_out_at > '1970-01-01 00:00:00'
                    THEN a.hours_paid ELSE 0 END), 0) AS hours_paid,
                COUNT(a.id) AS checkins
             FROM attendance a
             INNER JOIN staff_registrations sr ON sr.id = a.registration_id
             WHERE LOWER(sr.email) = :email
               AND a.checked_in_at >= :month_start"
        );
        $stmt->execute(['email' => $email, 'month_start' => $monthStart]);
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
function getStaffV3CheckinHistory(PDO $pdo, string $email, int $limit = 12): array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT a.checked_in_at, a.checked_out_at, a.hours_worked, a.attendance_status,
                    a.bib_number, sr.assigned_bib_number,
                    e.name AS event_name, e.location AS event_location, e.event_date
             FROM attendance a
             INNER JOIN staff_registrations sr ON sr.id = a.registration_id
             INNER JOIN events e ON e.id = sr.event_id
             WHERE LOWER(sr.email) = :email
             ORDER BY a.checked_in_at DESC
             LIMIT " . max(1, min(50, $limit))
        );
        $stmt->execute(['email' => $email]);

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
        $hasRealCheckin = registrationHadVenueCheckin($row);
        if ($attStatus === 'no_show' && $hasRealCheckin) {
            $attStatus = 'active';
        }

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

function staffV3AttendanceHasCompletedCheckout(array $row): bool
{
    $checkedOut = trim((string) ($row['checked_out_at'] ?? ''));

    return $checkedOut !== '' && !isEmptyDbDate($checkedOut);
}

/**
 * True when staff is on an in-progress shift (display layer only — does not change attendance records).
 */
function staffV3ShiftIsActiveForDisplay(array $row, ?PDO $pdo = null): bool
{
    unset($pdo);

    if ((string) ($row['status'] ?? '') !== 'approved') {
        return false;
    }

    $hasAttendance = !empty($row['attendance_id']) || (int) ($row['is_checked_in'] ?? 0) === 1;
    if (!$hasAttendance || !registrationHadVenueCheckin($row)) {
        return false;
    }

    if (staffV3AttendanceHasCompletedCheckout($row)) {
        return false;
    }

    $attStatus = strtolower(trim((string) ($row['attendance_status'] ?? '')));
    if (in_array($attStatus, ['no_show', 'gps_failed', 'manual_review'], true)) {
        return false;
    }

    $window = getEventCheckinWindow($row);
    if (($window['status'] ?? '') === 'after') {
        return false;
    }

    return in_array($attStatus, ['', 'active', 'pre_checked_in'], true);
}

/**
 * Scheduled shift window anchored on event_date (handles overnight end times).
 *
 * @return array{start: DateTime, end: DateTime, event_date: string}|null
 */
function staffV3ResolveShiftScheduledWindow(array $row, ?PDO $pdo = null): ?array
{
    $eventDate = normalizeEventDateYmd((string) ($row['event_date'] ?? ''));
    $start     = trim((string) ($row['event_start_time'] ?? $row['start_time'] ?? ''));
    $end       = trim((string) ($row['event_end_time'] ?? $row['end_time'] ?? ''));

    if ($eventDate === '' || $start === '' || $end === '') {
        return null;
    }

    try {
        if ($pdo instanceof PDO && function_exists('applySystemRuntimeSettings')) {
            applySystemRuntimeSettings($pdo);
        }

        $startDt = new DateTime($eventDate . ' ' . $start);
        $endDt   = new DateTime($eventDate . ' ' . $end);
        if ($endDt <= $startDt) {
            $endDt->modify('+1 day');
        }

        return [
            'start'      => $startDt,
            'end'        => $endDt,
            'event_date' => $eventDate,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Wall-clock progress for an in-window shift (display only).
 *
 * @param array{start: DateTime, end: DateTime, event_date: string} $window
 * @return array{percent: int, label: string, state: string}
 */
function staffV3ComputeLiveShiftProgress(array $window): array
{
    $now     = new DateTime();
    $startDt = $window['start'];
    $endDt   = $window['end'];
    $start   = $startDt->format('H:i');

    if ($now < $startDt) {
        return [
            'percent' => 0,
            'label'   => 'Starts ' . $start,
            'state'   => 'upcoming',
        ];
    }

    if ($now > $endDt) {
        return [
            'percent' => 100,
            'label'   => 'Shift ended',
            'state'   => 'done',
        ];
    }

    $totalSecs  = max(1, $endDt->getTimestamp() - $startDt->getTimestamp());
    $elapsedSec = max(0, $now->getTimestamp() - $startDt->getTimestamp());
    $percent    = (int) min(100, round(($elapsedSec / $totalSecs) * 100));
    $elapsedH   = round($elapsedSec / 3600, 1);
    $totalH     = round($totalSecs / 3600, 1);

    return [
        'percent' => $percent,
        'label'   => 'Active · ' . $elapsedH . ' / ' . $totalH . ' hrs',
        'state'   => 'live',
    ];
}

/**
 * Hours label for check-in history — hide projected hours until checkout completes.
 */
function formatStaffV3HistoryHoursLabel(array $row, ?PDO $pdo = null): string
{
    unset($pdo);

    if (!staffV3AttendanceHasCompletedCheckout($row)) {
        $checkedIn = trim((string) ($row['checked_in_at'] ?? ''));
        if ($checkedIn !== '' && !isEmptyDbDate($checkedIn)) {
            return 'In progress';
        }

        return '';
    }

    $hours = round((float) ($row['hours_worked'] ?? 0), 1);

    return $hours > 0 ? number_format($hours, 1) . ' hrs' : '';
}

/**
 * Live or completed shift time progress for cards.
 *
 * @return array{percent: int, label: string, state: string, hours_worked: float, hours_scheduled: float}
 */
function getStaffV3ShiftTimeProgress(array $row, ?PDO $pdo = null): array
{
    $hoursWorked    = round((float) ($row['hours_worked'] ?? 0), 1);
    $hoursScheduled = getStaffV3ShiftScheduledHours($row);
    $window         = staffV3ResolveShiftScheduledWindow($row, $pdo);

    if (staffV3ShiftIsActiveForDisplay($row, $pdo) && $window !== null) {
        $live = staffV3ComputeLiveShiftProgress($window);

        return [
            'percent'         => (int) ($live['percent'] ?? 0),
            'label'           => (string) ($live['label'] ?? ''),
            'state'           => (string) ($live['state'] ?? 'live'),
            'hours_worked'    => $hoursWorked,
            'hours_scheduled' => $hoursScheduled,
        ];
    }

    $checkedOut  = staffV3AttendanceHasCompletedCheckout($row);
    $windowMeta  = $window !== null ? getEventCheckinWindow($row) : null;
    $windowAfter = is_array($windowMeta) && (($windowMeta['status'] ?? '') === 'after');
    $hasCheckin  = registrationHadVenueCheckin($row);

    if (($checkedOut || $windowAfter) && $hasCheckin && $hoursWorked > 0) {
        return [
            'percent'         => $hoursScheduled > 0
                ? (int) min(100, round(($hoursWorked / $hoursScheduled) * 100))
                : 100,
            'label'           => $hoursWorked . ' hrs worked',
            'state'           => 'done',
            'hours_worked'    => $hoursWorked,
            'hours_scheduled' => $hoursScheduled,
        ];
    }

    if ($window !== null) {
        $today     = getOperationalTodayYmd($pdo);
        $eventDate = $window['event_date'];
        $inCalendarToday = ($eventDate === $today);
        $now       = new DateTime();
        $overnightStillRunning = $now >= $window['start'] && $now <= $window['end'] && !$hasCheckin;

        if ($inCalendarToday || $overnightStillRunning) {
            $live = staffV3ComputeLiveShiftProgress($window);

            return [
                'percent'         => (int) ($live['percent'] ?? 0),
                'label'           => (string) ($live['label'] ?? ''),
                'state'           => (string) ($live['state'] ?? 'upcoming'),
                'hours_worked'    => $hoursWorked,
                'hours_scheduled' => $hoursScheduled,
            ];
        }
    }

    if ($hoursScheduled > 0) {
        return [
            'percent'         => 0,
            'label'           => number_format($hoursScheduled, 1) . ' hrs scheduled',
            'state'           => 'upcoming',
            'hours_worked'    => $hoursWorked,
            'hours_scheduled' => $hoursScheduled,
        ];
    }

    return [
        'percent'         => 0,
        'label'           => '',
        'state'           => 'upcoming',
        'hours_worked'    => $hoursWorked,
        'hours_scheduled' => $hoursScheduled,
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
