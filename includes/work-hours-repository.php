<?php

require_once __DIR__ . '/attendance-repository.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/staff-labels.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/work-hours-schema.php';

/**
 * @return array{
 *     work_start: DateTime,
 *     work_end: DateTime,
 *     scheduled_hours: float,
 *     hours_worked: float,
 *     hours_paid: float
 * }
 */
function calculateWorkHoursForCheckin(array $event, DateTimeInterface $checkedInAt): array
{
    $date      = (string) ($event['event_date'] ?? '');
    $startTime = (string) ($event['start_time'] ?? $event['event_start_time'] ?? '09:00:00');
    $endTime   = (string) ($event['end_time'] ?? $event['event_end_time'] ?? '23:00:00');

    $eventStart = parseEventDateTime($date, $startTime) ?? new DateTime($date . ' 09:00:00');
    $eventEnd   = parseEventDateTime($date, $endTime) ?? new DateTime($date . ' 23:00:00');

    if ($eventEnd <= $eventStart) {
        $eventEnd = (clone $eventStart)->modify('+8 hours');
    }

    $checkedIn = $checkedInAt instanceof DateTime
        ? clone $checkedInAt
        : new DateTime($checkedInAt->format('Y-m-d H:i:s'), $eventStart->getTimezone());

    $workStart = $checkedIn > $eventStart ? $checkedIn : clone $eventStart;
    $workEnd   = clone $eventEnd;

    $scheduledSeconds = max(0, $eventEnd->getTimestamp() - $eventStart->getTimestamp());
    $workedSeconds    = max(0, $workEnd->getTimestamp() - $workStart->getTimestamp());

    $scheduledHours = round($scheduledSeconds / 3600, 2);
    $hoursWorked    = round($workedSeconds / 3600, 2);

    // Late admin check-in after shift end (e.g. forgot bulk sign-in) — use full scheduled shift.
    $checkinMethod = strtolower((string) ($event['checked_in_method'] ?? ''));
    if ($hoursWorked <= 0 && $scheduledHours > 0
        && in_array($checkinMethod, ['admin', 'admin_manual'], true)) {
        $workStart   = clone $eventStart;
        $hoursWorked = $scheduledHours;
    }

    return [
        'work_start'      => $workStart,
        'work_end'        => $workEnd,
        'scheduled_hours' => $scheduledHours,
        'hours_worked'    => $hoursWorked,
        'hours_paid'      => $hoursWorked,
    ];
}

function initializeWorkHoursForRegistration(PDO $pdo, int $registrationId): void
{
    ensureWorkHoursSchema($pdo);

    $stmt = $pdo->prepare(
        'SELECT a.id, a.checked_in_at, a.activated_at, a.attendance_status, a.hours_paid, a.hours_worked,
                e.event_date, e.start_time, e.end_time
         FROM attendance a
         INNER JOIN events e ON e.id = a.event_id
         WHERE a.registration_id = :registration_id
         LIMIT 1'
    );
    $stmt->execute(['registration_id' => $registrationId]);
    $row = $stmt->fetch();

    if (!$row) {
        return;
    }

    $existingWorked = $row['hours_worked'] !== null ? (float) $row['hours_worked'] : null;
    if ($existingWorked !== null && $existingWorked > 0.01) {
        return;
    }

    require_once __DIR__ . '/attendance-gps-phase1.php';
    if (isGpsAttendanceV2Enabled($pdo) && isAttendancePreCheckedIn($row)) {
        return;
    }

    $workStartRaw = !empty($row['activated_at']) ? (string) $row['activated_at'] : (string) $row['checked_in_at'];
    $checkedIn    = new DateTime($workStartRaw);
    $calcRow      = $row;
    $calcRow['checked_in_method'] = (string) ($row['checked_in_method'] ?? '');
    $calc         = calculateWorkHoursForCheckin($calcRow, $checkedIn);

    $update = $pdo->prepare(
        'UPDATE attendance SET
            work_end_at = :work_end_at,
            scheduled_hours = :scheduled_hours,
            hours_worked = :hours_worked,
            hours_paid = :hours_paid
         WHERE id = :id'
    );
    $update->execute([
        'work_end_at'       => $calc['work_end']->format('Y-m-d H:i:s'),
        'scheduled_hours'   => $calc['scheduled_hours'],
        'hours_worked'      => $calc['hours_worked'],
        'hours_paid'        => $calc['hours_paid'],
        'id'                => (int) $row['id'],
    ]);
}

function backfillMissingWorkHours(PDO $pdo): int
{
    ensureWorkHoursSchema($pdo);

    $stmt = $pdo->query(
        'SELECT a.registration_id
         FROM attendance a
         WHERE a.hours_worked IS NULL'
    );
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $count = 0;

    foreach ($ids as $registrationId) {
        initializeWorkHoursForRegistration($pdo, (int) $registrationId);
        $count++;
    }

    return $count;
}

/**
 * @return array<int, array<string, mixed>>
 */
function getWorkHoursList(PDO $pdo, int $eventId = 0, string $workDate = ''): array
{
    ensureWorkHoursSchema($pdo);
    backfillMissingWorkHours($pdo);

    $where  = '1=1';
    $params = [];

    if ($eventId > 0) {
        $where .= ' AND a.event_id = :event_id';
        $params['event_id'] = $eventId;
    }

    if ($workDate !== '') {
        $where .= ' AND e.event_date = :work_date';
        $params['work_date'] = $workDate;
    }

    require_once __DIR__ . '/staff-registration-schema.php';
    $bibSelect = staffRegistrationColumnExists($pdo, 'assigned_bib_number')
        ? 'sr.assigned_bib_number'
        : 'NULL AS assigned_bib_number';

    $sql = "SELECT a.id AS attendance_id, a.checked_in_at, a.work_end_at,
                   a.scheduled_hours, a.hours_worked, a.hours_paid, a.hours_note,
                   a.hours_adjusted_at, a.checked_in_method,
                   sr.id AS registration_id, sr.first_name, sr.surname, sr.email,
                   sr.gender, sr.staff_role, {$bibSelect},
                   e.id AS event_id, e.name AS event_name, e.event_date,
                   e.start_time, e.end_time,
                   au.username AS adjusted_by_username
            FROM attendance a
            INNER JOIN staff_registrations sr ON sr.id = a.registration_id
            INNER JOIN events e ON e.id = a.event_id
            LEFT JOIN admin_users au ON au.id = a.hours_adjusted_by
            WHERE {$where}
            ORDER BY e.event_date DESC, e.name ASC, sr.surname ASC, sr.first_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * @return array{headcount: int, hours_worked: float, hours_paid: float, scheduled_hours: float}
 */
function getWorkHoursTotals(PDO $pdo, int $eventId = 0, string $workDate = ''): array
{
    $rows = getWorkHoursList($pdo, $eventId, $workDate);

    $totals = [
        'headcount'        => count($rows),
        'hours_worked'     => 0.0,
        'hours_paid'       => 0.0,
        'scheduled_hours'  => 0.0,
    ];

    foreach ($rows as $row) {
        $totals['hours_worked']    += (float) ($row['hours_worked'] ?? 0);
        $totals['hours_paid']      += (float) ($row['hours_paid'] ?? 0);
        $totals['scheduled_hours'] += (float) ($row['scheduled_hours'] ?? 0);
    }

    $totals['hours_worked']    = round($totals['hours_worked'], 2);
    $totals['hours_paid']      = round($totals['hours_paid'], 2);
    $totals['scheduled_hours'] = round($totals['scheduled_hours'], 2);

    return $totals;
}

function formatHoursDecimal(float $hours): string
{
    return number_format($hours, 2) . ' h';
}

/**
 * Scheduled shift length for an attendance row (event start → end).
 */
function resolveEventScheduledHoursFromRow(array $row): float
{
    $date      = (string) ($row['event_date'] ?? '');
    $startTime = (string) ($row['start_time'] ?? '09:00:00');
    $endTime   = (string) ($row['end_time'] ?? '23:00:00');

    $eventStart = parseEventDateTime($date, $startTime) ?? new DateTime($date . ' 09:00:00');
    $eventEnd   = parseEventDateTime($date, $endTime) ?? new DateTime($date . ' 23:00:00');

    if ($eventEnd <= $eventStart) {
        $eventEnd = (clone $eventStart)->modify('+8 hours');
    }

    $seconds = max(0, $eventEnd->getTimestamp() - $eventStart->getTimestamp());

    return round($seconds / 3600, 2);
}

/**
 * @return true|string
 */
function updateWorkHours(PDO $pdo, int $attendanceId, float $hoursPaid, string $note, int $adminUserId): bool|string
{
    ensureWorkHoursSchema($pdo);

    $stmt = $pdo->prepare(
        'SELECT a.*, e.event_date, e.start_time, e.end_time
         FROM attendance a
         INNER JOIN events e ON e.id = a.event_id
         WHERE a.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $attendanceId]);
    $row = $stmt->fetch();

    if (!$row) {
        return 'Work hours record not found.';
    }

    if ($row['hours_worked'] === null) {
        initializeWorkHoursForRegistration($pdo, (int) $row['registration_id']);
        $stmt->execute(['id' => $attendanceId]);
        $row = $stmt->fetch();
    }

    $maxHours = (float) ($row['hours_worked'] ?? 0);
    $hoursPaid = round(max(0, $hoursPaid), 2);

    if ($hoursPaid > $maxHours + 0.01) {
        return 'Payable hours cannot exceed calculated hours worked (' . formatHoursDecimal($maxHours) . ').';
    }

    $update = $pdo->prepare(
        'UPDATE attendance SET
            hours_paid = :hours_paid,
            hours_note = :hours_note,
            hours_adjusted_by = :admin_id,
            hours_adjusted_at = NOW()
         WHERE id = :id'
    );
    $update->execute([
        'hours_paid'  => $hoursPaid,
        'hours_note'  => trim($note) !== '' ? trim($note) : null,
        'admin_id'    => $adminUserId,
        'id'          => $attendanceId,
    ]);

    return true;
}

/**
 * Record sent home early — sets payable hours, note, and actual work end on the attendance row.
 *
 * @return true|string
 */
function recordStaffSentHome(PDO $pdo, int $attendanceId, float $hoursPaid, string $note, int $adminUserId): bool|string
{
    ensureWorkHoursSchema($pdo);

    $stmt = $pdo->prepare(
        'SELECT a.*, e.event_date, e.start_time, e.end_time
         FROM attendance a
         INNER JOIN events e ON e.id = a.event_id
         WHERE a.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $attendanceId]);
    $row = $stmt->fetch();

    if (!$row) {
        return 'Attendance record not found.';
    }

    $hoursPaid = round(max(0, $hoursPaid), 2);
    if ($hoursPaid <= 0) {
        return 'Enter payable hours greater than 0.';
    }

    $date       = (string) ($row['event_date'] ?? '');
    $startTime  = (string) ($row['start_time'] ?? '09:00:00');
    $endTime    = (string) ($row['end_time'] ?? '23:00:00');
    $eventStart = parseEventDateTime($date, $startTime) ?? new DateTime($date . ' 09:00:00');
    $eventEnd   = parseEventDateTime($date, $endTime) ?? new DateTime($date . ' 23:00:00');

    $workStartRaw = !empty($row['activated_at']) ? (string) $row['activated_at'] : (string) ($row['checked_in_at'] ?? '');
    if ($workStartRaw === '') {
        return 'Staff has not signed in yet.';
    }

    $workStart = new DateTime($workStartRaw, $eventStart->getTimezone());
    if ($workStart < $eventStart) {
        $workStart = clone $eventStart;
    }

    $workEnd = (clone $workStart)->modify('+' . (int) round($hoursPaid * 3600) . ' seconds');
    if ($workEnd > $eventEnd) {
        $workEnd = clone $eventEnd;
    }

    $seconds     = max(0, $workEnd->getTimestamp() - $workStart->getTimestamp());
    $hoursWorked = round($seconds / 3600, 2);
    if ($hoursPaid > $hoursWorked + 0.01) {
        return 'Payable hours cannot exceed time from sign-in to shift end (' . formatHoursDecimal($hoursWorked) . ').';
    }

    $note = trim($note);
    if ($note === '') {
        $note = 'Sent home early';
    }

    $calc = calculateWorkHoursForCheckin($row, $workStart);
    $scheduledHours = (float) ($calc['scheduled_hours'] ?? $hoursWorked);

    $update = $pdo->prepare(
        'UPDATE attendance SET
            work_end_at = :work_end_at,
            scheduled_hours = :scheduled_hours,
            hours_worked = :hours_worked,
            hours_paid = :hours_paid,
            hours_note = :hours_note,
            hours_adjusted_by = :admin_id,
            hours_adjusted_at = NOW()
         WHERE id = :id'
    );
    $update->execute([
        'work_end_at'       => $workEnd->format('Y-m-d H:i:s'),
        'scheduled_hours'   => $scheduledHours,
        'hours_worked'      => $hoursWorked,
        'hours_paid'        => $hoursPaid,
        'hours_note'        => $note,
        'admin_id'          => $adminUserId,
        'id'                => $attendanceId,
    ]);

    return true;
}

/**
 * Admin correction for missed bulk sign-in or late manual check-in — sets full shift hours.
 *
 * @return true|string
 */
function correctAdminShiftHours(PDO $pdo, int $attendanceId, float $hoursPaid, string $note, int $adminUserId): bool|string
{
    ensureWorkHoursSchema($pdo);

    $stmt = $pdo->prepare(
        'SELECT a.*, e.event_date, e.start_time, e.end_time
         FROM attendance a
         INNER JOIN events e ON e.id = a.event_id
         WHERE a.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $attendanceId]);
    $row = $stmt->fetch();

    if (!$row) {
        return 'Attendance record not found.';
    }

    $hoursPaid = round(max(0, $hoursPaid), 2);
    if ($hoursPaid <= 0) {
        return 'Enter payable hours greater than 0.';
    }

    $date       = (string) ($row['event_date'] ?? '');
    $startTime  = (string) ($row['start_time'] ?? '09:00:00');
    $endTime    = (string) ($row['end_time'] ?? '23:00:00');
    $eventStart = parseEventDateTime($date, $startTime) ?? new DateTime($date . ' 09:00:00');
    $eventEnd   = parseEventDateTime($date, $endTime) ?? new DateTime($date . ' 23:00:00');

    if ($eventEnd <= $eventStart) {
        $eventEnd = (clone $eventStart)->modify('+8 hours');
    }

    $scheduledSeconds = max(0, $eventEnd->getTimestamp() - $eventStart->getTimestamp());
    $scheduledHours   = round($scheduledSeconds / 3600, 2);
    if ($hoursPaid > $scheduledHours + 0.01) {
        return 'Payable hours cannot exceed the scheduled shift (' . formatHoursDecimal($scheduledHours) . ').';
    }

    $workStart = clone $eventStart;
    $workEnd   = (clone $workStart)->modify('+' . (int) round($hoursPaid * 3600) . ' seconds');
    if ($workEnd > $eventEnd) {
        $workEnd = clone $eventEnd;
    }

    $note = trim($note);
    if ($note === '') {
        $note = 'Shift hours corrected — admin sign-in after shift / missed bulk sign-in.';
    }

    $update = $pdo->prepare(
        'UPDATE attendance SET
            work_end_at = :work_end_at,
            scheduled_hours = :scheduled_hours,
            hours_worked = :hours_worked,
            hours_paid = :hours_paid,
            hours_note = :hours_note,
            hours_adjusted_by = :admin_id,
            hours_adjusted_at = NOW()
         WHERE id = :id'
    );
    $update->execute([
        'work_end_at'     => $workEnd->format('Y-m-d H:i:s'),
        'scheduled_hours' => $scheduledHours,
        'hours_worked'    => $hoursPaid,
        'hours_paid'      => $hoursPaid,
        'hours_note'      => $note,
        'admin_id'        => $adminUserId,
        'id'              => $attendanceId,
    ]);

    return true;
}

/**
 * @return list<array<string, mixed>>
 */
function getAttendanceHistoryForRegistration(PDO $pdo, int $registrationId): array
{
    ensureWorkHoursSchema($pdo);

    $stmt = $pdo->prepare(
        'SELECT a.*, e.name AS event_name, e.event_date, e.start_time, e.end_time,
                au.username AS adjusted_by_username
         FROM attendance a
         INNER JOIN events e ON e.id = a.event_id
         WHERE a.registration_id = :registration_id
         ORDER BY a.checked_in_at DESC'
    );
    $stmt->execute(['registration_id' => $registrationId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
