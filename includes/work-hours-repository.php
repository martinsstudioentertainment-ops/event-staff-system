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
        'SELECT a.id, a.checked_in_at, a.hours_paid,
                e.event_date, e.start_time, e.end_time
         FROM attendance a
         INNER JOIN events e ON e.id = a.event_id
         WHERE a.registration_id = :registration_id
         LIMIT 1'
    );
    $stmt->execute(['registration_id' => $registrationId]);
    $row = $stmt->fetch();

    if (!$row || $row['hours_worked'] !== null) {
        return;
    }

    $checkedIn = new DateTime((string) $row['checked_in_at']);
    $calc      = calculateWorkHoursForCheckin($row, $checkedIn);

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

    $sql = "SELECT a.id AS attendance_id, a.checked_in_at, a.work_end_at,
                   a.scheduled_hours, a.hours_worked, a.hours_paid, a.hours_note,
                   a.hours_adjusted_at, a.checked_in_method,
                   sr.id AS registration_id, sr.first_name, sr.surname, sr.email,
                   sr.gender, sr.staff_role,
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
