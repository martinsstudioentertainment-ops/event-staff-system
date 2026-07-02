<?php

declare(strict_types=1);

require_once __DIR__ . '/feature-flags.php';
require_once __DIR__ . '/attendance-gps-phase1-schema.php';

const ATTENDANCE_STATUS_ACTIVE = 'active';
const ATTENDANCE_STATUS_PRE_CHECKED_IN = 'pre_checked_in';
const ATTENDANCE_STATUS_NO_SHOW = 'no_show';

function isGpsAttendanceV2Enabled(?PDO $pdo): bool
{
    return isFeatureEnabled($pdo, 'feature_gps_attendance_v2');
}

function getHibernationCheckinMessage(): string
{
    require_once __DIR__ . '/attendance-gps-phase15.php';

    return getHibernationCheckinMessagePhase15();
}

/**
 * @param array<string, mixed>|null $attendance
 */
function isAttendancePreCheckedIn(?array $attendance): bool
{
    if ($attendance === null) {
        return false;
    }

    return strtolower((string) ($attendance['attendance_status'] ?? ATTENDANCE_STATUS_ACTIVE)) === ATTENDANCE_STATUS_PRE_CHECKED_IN;
}

/**
 * @param array<string, mixed>|null $attendance
 */
function isAttendanceActive(?array $attendance): bool
{
    if ($attendance === null) {
        return false;
    }

    $status = strtolower((string) ($attendance['attendance_status'] ?? ATTENDANCE_STATUS_ACTIVE));

    return $status === ATTENDANCE_STATUS_ACTIVE || $status === '';
}

/**
 * Activate hibernated attendance when the event has started.
 */
function activateHibernatedAttendance(PDO $pdo, int $attendanceId, DateTimeInterface $eventStart): bool
{
    ensureAttendanceGpsPhase1Schema($pdo);
    require_once __DIR__ . '/attendance-gps-phase15-schema.php';
    ensureAttendanceGpsPhase15Schema($pdo);

    $stmt = $pdo->prepare(
        'SELECT a.*, e.id AS event_row_id, e.event_date, e.start_time, e.end_time,
                e.venue_lat, e.venue_lng, e.venue_eircode, e.signin_radius_m, e.location
         FROM attendance a
         INNER JOIN events e ON e.id = a.event_id
         WHERE a.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $attendanceId]);
    $row = $stmt->fetch();

    if (!$row || !isAttendancePreCheckedIn($row) || $row['activated_at'] !== null) {
        return false;
    }

    require_once __DIR__ . '/attendance-gps-phase15.php';
    if (!canActivateWithGpsProof($pdo, $row, $row)) {
        return false;
    }

    $activatedAt = $eventStart instanceof DateTime
        ? $eventStart->format('Y-m-d H:i:s')
        : (new DateTime($eventStart->format('Y-m-d H:i:s')))->format('Y-m-d H:i:s');

    $update = $pdo->prepare(
        "UPDATE attendance
         SET attendance_status = :status,
             activated_at = :activated_at
         WHERE id = :id
           AND attendance_status = :pre_status"
    );
    $update->execute([
        'status'       => ATTENDANCE_STATUS_ACTIVE,
        'activated_at' => $activatedAt,
        'id'           => $attendanceId,
        'pre_status'   => ATTENDANCE_STATUS_PRE_CHECKED_IN,
    ]);

    if ($update->rowCount() === 0) {
        return false;
    }

    try {
        require_once __DIR__ . '/work-hours-repository.php';
        initializeWorkHoursForRegistration($pdo, (int) $row['registration_id']);
    } catch (Throwable $e) {
        error_log('[EventStaff] activateHibernatedAttendance work-hours id=' . $attendanceId . ': ' . $e->getMessage());
    }

    return true;
}

/**
 * Activate all PRE_CHECKED_IN rows whose events have reached start time.
 */
function activateHibernatedAttendanceForStartedEvents(PDO $pdo): int
{
    if (!isGpsAttendanceV2Enabled($pdo)) {
        return 0;
    }

    ensureAttendanceGpsPhase1Schema($pdo);

    $stmt = $pdo->query(
        "SELECT a.id, e.event_date, e.start_time
         FROM attendance a
         INNER JOIN events e ON e.id = a.event_id
         WHERE a.attendance_status = '" . ATTENDANCE_STATUS_PRE_CHECKED_IN . "'
           AND a.activated_at IS NULL"
    );

    $rows  = $stmt ? $stmt->fetchAll() : [];
    $count = 0;
    $now   = new DateTime('now');

    require_once __DIR__ . '/attendance-repository.php';

    foreach ($rows as $row) {
        $eventStart = parseEventDateTime((string) $row['event_date'], (string) $row['start_time']);
        if ($eventStart === null || $now < $eventStart) {
            continue;
        }

        if (activateHibernatedAttendance($pdo, (int) $row['id'], $eventStart)) {
            $count++;
        }
    }

    return $count;
}

/**
 * Opportunistic activation for a single registration (e.g. page load after event start).
 */
function maybeActivateHibernatedAttendanceForRegistration(PDO $pdo, int $registrationId): bool
{
    if (!isGpsAttendanceV2Enabled($pdo)) {
        return false;
    }

    ensureAttendanceGpsPhase1Schema($pdo);

    $stmt = $pdo->prepare(
        'SELECT a.id, a.attendance_status, e.event_date, e.start_time
         FROM attendance a
         INNER JOIN events e ON e.id = a.event_id
         WHERE a.registration_id = :registration_id
         LIMIT 1'
    );
    $stmt->execute(['registration_id' => $registrationId]);
    $row = $stmt->fetch();

    if (!$row || !isAttendancePreCheckedIn($row)) {
        return false;
    }

    require_once __DIR__ . '/attendance-repository.php';

    $eventStart = parseEventDateTime((string) $row['event_date'], (string) $row['start_time']);
    if ($eventStart === null) {
        return false;
    }

    $now = new DateTime('now', $eventStart->getTimezone());
    if ($now < $eventStart) {
        return false;
    }

    return activateHibernatedAttendance($pdo, (int) $row['id'], $eventStart);
}
