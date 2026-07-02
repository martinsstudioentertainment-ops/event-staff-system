<?php

declare(strict_types=1);

require_once __DIR__ . '/attendance-repository.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/work-hours-repository.php';
require_once __DIR__ . '/work-hours-schema.php';
require_once __DIR__ . '/attendance-gps-phase1-schema.php';
require_once __DIR__ . '/attendance-bib-schema.php';
require_once __DIR__ . '/checkin-bib.php';
require_once __DIR__ . '/registration-bib.php';

/**
 * Approved staff for an event who have not checked in yet.
 *
 * @return list<array<string, mixed>>
 */
function getApprovedStaffMissingCheckin(PDO $pdo, int $eventId): array
{
    if ($eventId < 1) {
        return [];
    }

    $bibSelect = registrationBibColumnEnabled($pdo) ? ', sr.assigned_bib_number' : '';

    $sql = "SELECT sr.id, sr.first_name, sr.surname, sr.email, sr.staff_role, sr.gender{$bibSelect},
                   e.name AS event_name, e.event_date, e.start_time, e.end_time
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            LEFT JOIN attendance a ON a.registration_id = sr.id
            WHERE sr.event_id = :event_id
              AND sr.status = 'approved'
              AND a.id IS NULL
            ORDER BY sr.surname ASC, sr.first_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['event_id' => $eventId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Default payable hours for an event (full shift from start to end).
 */
function suggestManualSigninHours(array $event): float
{
    $date      = (string) ($event['event_date'] ?? '');
    $startTime = (string) ($event['start_time'] ?? $event['event_start_time'] ?? '09:00:00');
    $endTime   = (string) ($event['end_time'] ?? $event['event_end_time'] ?? '23:00:00');

    $eventStart = parseEventDateTime($date, $startTime) ?? new DateTime($date . ' 09:00:00');
    $eventEnd   = parseEventDateTime($date, $endTime) ?? new DateTime($date . ' 23:00:00');

    if ($eventEnd <= $eventStart) {
        $eventEnd = (clone $eventStart)->modify('+8 hours');
    }

    $seconds = max(0, $eventEnd->getTimestamp() - $eventStart->getTimestamp());

    return round($seconds / 3600, 2);
}

/**
 * Admin retroactive sign-in — bypasses GPS and closed check-in window.
 *
 * @return true|string
 */
function recordAdminManualCheckin(
    PDO $pdo,
    int $registrationId,
    float $hoursPaid,
    string $note,
    int $adminUserId,
    int $expectedEventId = 0,
    ?string $bibNumber = null
): bool|string {
    ensureWorkHoursSchema($pdo);
    ensureAttendanceGpsPhase1Schema($pdo);

    $hoursPaid = round(max(0, $hoursPaid), 2);
    if ($hoursPaid <= 0) {
        return 'Enter hours worked (greater than 0).';
    }

    $reg = getStaffRegistrationById($pdo, $registrationId);
    if ($reg === null) {
        return 'Registration not found.';
    }

    if ((string) ($reg['status'] ?? '') !== 'approved') {
        return 'Only approved staff can be signed in.';
    }

    if (hasCheckedIn($pdo, $registrationId)) {
        return 'Already checked in.';
    }

    $eventId = (int) ($reg['event_id'] ?? 0);
    if ($expectedEventId > 0 && $eventId !== $expectedEventId) {
        return 'Registration does not belong to this event.';
    }

    $event = getEventById($pdo, $eventId);
    if ($event === null) {
        return 'Event not found.';
    }

    $date      = (string) ($event['event_date'] ?? '');
    $startTime = (string) ($event['start_time'] ?? $event['event_start_time'] ?? '09:00:00');
    $eventStart = parseEventDateTime($date, $startTime) ?? new DateTime($date . ' 09:00:00');
    $checkedInAt = $eventStart->format('Y-m-d H:i:s');
    $workEnd     = (clone $eventStart)->modify('+' . (int) round($hoursPaid * 3600) . ' seconds');

    $calc = calculateWorkHoursForCheckin($event, $eventStart);
    $scheduledHours = (float) ($calc['scheduled_hours'] ?? $hoursPaid);

    $defaultNote = 'Manual sign-in — staff worked but could not complete venue QR sign-in.';
    $note        = trim($note) !== '' ? trim($note) : $defaultNote;

    $bibParsed = parseCheckinBibNumber($bibNumber, false);
    if (!$bibParsed['ok']) {
        return (string) ($bibParsed['error'] ?? 'Enter a valid bib number.');
    }

    $insert = $pdo->prepare(
        'INSERT INTO attendance (
            registration_id, event_id, checked_in_at, checked_in_method,
            attendance_status, activated_at,
            work_end_at, scheduled_hours, hours_worked, hours_paid,
            hours_note, hours_adjusted_by, hours_adjusted_at
         ) VALUES (
            :registration_id, :event_id, :checked_in_at, :method,
            :attendance_status, :activated_at,
            :work_end_at, :scheduled_hours, :hours_worked, :hours_paid,
            :hours_note, :admin_id, NOW()
         )'
    );
    $insert->execute([
        'registration_id'   => $registrationId,
        'event_id'          => $eventId,
        'checked_in_at'     => $checkedInAt,
        'method'            => 'admin_manual',
        'attendance_status' => 'active',
        'activated_at'      => $checkedInAt,
        'work_end_at'       => $workEnd->format('Y-m-d H:i:s'),
        'scheduled_hours'   => $scheduledHours,
        'hours_worked'      => $hoursPaid,
        'hours_paid'        => $hoursPaid,
        'hours_note'        => $note,
        'admin_id'          => $adminUserId,
    ]);

    ensureCheckinToken($pdo, $registrationId);

    if ($bibParsed['bib'] !== null && $bibParsed['bib'] !== '') {
        saveAttendanceBibNumber($pdo, $registrationId, $bibParsed['bib']);
    }

    try {
        require_once __DIR__ . '/notifications.php';
        notifyStaffCheckin($pdo, $registrationId, 'admin_manual');
    } catch (Throwable $e) {
        error_log('[EventStaff] notifyStaffCheckin manual id=' . $registrationId . ': ' . $e->getMessage());
    }

    return true;
}

/**
 * Re-create attendance after an accidental check-in reset — keeps historical shift hours.
 *
 * @return array{ok: bool, attendance_id?: int, error?: string}
 */
function restoreStaffShiftAttendance(
    PDO $pdo,
    int $registrationId,
    float $hoursPaid,
    string $note = '',
    ?string $checkedInAt = null,
    bool $notify = false
): array {
    ensureWorkHoursSchema($pdo);
    ensureAttendanceGpsPhase1Schema($pdo);

    $hoursPaid = round(max(0, $hoursPaid), 2);
    if ($hoursPaid <= 0) {
        return ['ok' => false, 'error' => 'Hours must be greater than 0.'];
    }

    if (hasCheckedIn($pdo, $registrationId)) {
        return ['ok' => false, 'error' => 'Already checked in — edit hours on Work hours or staff profile instead.'];
    }

    $reg = getStaffRegistrationById($pdo, $registrationId);
    if ($reg === null) {
        return ['ok' => false, 'error' => 'Registration not found.'];
    }

    if ((string) ($reg['status'] ?? '') !== 'approved') {
        return ['ok' => false, 'error' => 'Only approved staff can be restored.'];
    }

    $eventId = (int) ($reg['event_id'] ?? 0);
    $event   = getEventById($pdo, $eventId);
    if ($event === null) {
        return ['ok' => false, 'error' => 'Event not found.'];
    }

    $date       = (string) ($event['event_date'] ?? '');
    $startTime  = (string) ($event['start_time'] ?? $event['event_start_time'] ?? '09:00:00');
    $eventStart = parseEventDateTime($date, $startTime) ?? new DateTime($date . ' 09:00:00');

    if ($checkedInAt !== null && trim($checkedInAt) !== '') {
        try {
            $eventStart = new DateTime(trim($checkedInAt));
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Invalid check-in datetime.'];
        }
    }

    $workEnd = (clone $eventStart)->modify('+' . (int) round($hoursPaid * 3600) . ' seconds');
    $calc    = calculateWorkHoursForCheckin($event, $eventStart);
    $scheduledHours = (float) ($calc['scheduled_hours'] ?? $hoursPaid);

    $defaultNote = 'Restored shift hours after accidental check-in reset.';
    $note        = trim($note) !== '' ? trim($note) : $defaultNote;

    $insert = $pdo->prepare(
        'INSERT INTO attendance (
            registration_id, event_id, checked_in_at, checked_in_method,
            attendance_status, activated_at, checked_out_at,
            work_end_at, scheduled_hours, hours_worked, hours_paid,
            hours_note, hours_adjusted_at
         ) VALUES (
            :registration_id, :event_id, :checked_in_at, :method,
            :attendance_status, :activated_at, :checked_out_at,
            :work_end_at, :scheduled_hours, :hours_worked, :hours_paid,
            :hours_note, NOW()
         )'
    );
    $insert->execute([
        'registration_id'   => $registrationId,
        'event_id'          => $eventId,
        'checked_in_at'     => $eventStart->format('Y-m-d H:i:s'),
        'method'            => 'admin_manual',
        'attendance_status' => 'completed',
        'activated_at'      => $eventStart->format('Y-m-d H:i:s'),
        'checked_out_at'    => $workEnd->format('Y-m-d H:i:s'),
        'work_end_at'       => $workEnd->format('Y-m-d H:i:s'),
        'scheduled_hours'   => $scheduledHours,
        'hours_worked'      => $hoursPaid,
        'hours_paid'        => $hoursPaid,
        'hours_note'        => $note,
    ]);

    $attendanceId = (int) $pdo->lastInsertId();
    ensureCheckinToken($pdo, $registrationId);

    if ($notify) {
        try {
            require_once __DIR__ . '/notifications.php';
            notifyStaffCheckin($pdo, $registrationId, 'admin_manual');
        } catch (Throwable $e) {
            error_log('[EventStaff] notifyStaffCheckin restore id=' . $registrationId . ': ' . $e->getMessage());
        }
    }

    return [
        'ok'            => true,
        'attendance_id' => $attendanceId,
        'registration_id' => $registrationId,
        'event_id'      => $eventId,
        'hours_paid'    => $hoursPaid,
        'checked_in_at' => $eventStart->format('Y-m-d H:i:s'),
    ];
}

/**
 * @return array{signed: int, failed: list<array{id: int, name: string, error: string}>}
 */
function recordAdminManualCheckinBulk(
    PDO $pdo,
    int $eventId,
    array $hoursByRegistrationId,
    string $note,
    int $adminUserId,
    array $bibByRegistrationId = []
): array {
    $signed = 0;
    $failed = [];

    foreach ($hoursByRegistrationId as $regId => $hours) {
        $regId = (int) $regId;
        $hours = (float) $hours;
        if ($regId < 1 || $hours <= 0) {
            continue;
        }

        $bib = trim((string) ($bibByRegistrationId[$regId] ?? $bibByRegistrationId[(string) $regId] ?? ''));
        $result = recordAdminManualCheckin($pdo, $regId, $hours, $note, $adminUserId, $eventId, $bib !== '' ? $bib : null);
        if ($result === true) {
            $signed++;
            continue;
        }

        $reg  = getStaffRegistrationById($pdo, $regId);
        $name = $reg ? trim((string) $reg['first_name'] . ' ' . (string) $reg['surname']) : ('#' . $regId);
        $failed[] = ['id' => $regId, 'name' => $name, 'error' => (string) $result];
    }

    return ['signed' => $signed, 'failed' => $failed];
}
