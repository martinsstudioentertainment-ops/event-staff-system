<?php

declare(strict_types=1);

require_once __DIR__ . '/attendance-gps-phase1.php';
require_once __DIR__ . '/attendance-gps-signout-schema.php';
require_once __DIR__ . '/attendance-gps-phase15.php';
require_once __DIR__ . '/attendance-repository.php';
require_once __DIR__ . '/work-hours-repository.php';
require_once __DIR__ . '/maps.php';

const ATTENDANCE_STATUS_AUTO_SIGNED_OUT = 'auto_signed_out';

/** Consecutive outside-geofence pings before auto sign-out (45s poll ≈ 90s grace). */
const GPS_GEOFENCE_EXIT_STRIKES = 2;

/** Server-side sign-out when last known GPS was outside and the app stopped pinging (minutes). */
const GPS_GEOFENCE_STALE_SIGNOUT_MINUTES = 20;

function getAutoSignoutMessage(string $reason): string
{
    return match ($reason) {
        'left_geofence' => 'You have been signed out automatically because you left the venue attendance zone. Your worked hours have been recorded up to sign-out time.',
        'event_end'     => 'This shift has ended. You have been signed out automatically.',
        default         => 'You have been signed out automatically.',
    };
}

/**
 * @param array<string, mixed>|null $attendance
 */
function isAttendanceAutoSignedOut(?array $attendance): bool
{
    if ($attendance === null) {
        return false;
    }

    return strtolower((string) ($attendance['attendance_status'] ?? '')) === ATTENDANCE_STATUS_AUTO_SIGNED_OUT;
}

function finalizeWorkHoursOnSignOut(PDO $pdo, int $attendanceId, DateTimeInterface $checkedOutAt): void
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
        return;
    }

    $date      = (string) ($row['event_date'] ?? '');
    $startTime = (string) ($row['start_time'] ?? '09:00:00');
    $endTime   = (string) ($row['end_time'] ?? '23:00:00');
    $eventStart = parseEventDateTime($date, $startTime) ?? new DateTime($date . ' 09:00:00');
    $eventEnd   = parseEventDateTime($date, $endTime) ?? new DateTime($date . ' 23:00:00');

    $workStartRaw = !empty($row['activated_at']) ? (string) $row['activated_at'] : (string) ($row['checked_in_at'] ?? '');
    if ($workStartRaw === '') {
        return;
    }

    $workStart = new DateTime($workStartRaw, $eventStart->getTimezone());
    $workEnd   = $checkedOutAt instanceof DateTime
        ? clone $checkedOutAt
        : new DateTime($checkedOutAt->format('Y-m-d H:i:s'), $eventStart->getTimezone());

    if ($workStart < $eventStart) {
        $workStart = clone $eventStart;
    }
    if ($workEnd > $eventEnd) {
        $workEnd = clone $eventEnd;
    }
    if ($workEnd <= $workStart) {
        $workEnd = clone $workStart;
    }

    $seconds     = max(0, $workEnd->getTimestamp() - $workStart->getTimestamp());
    $hoursWorked = round($seconds / 3600, 2);
    $adminLocked = !empty($row['hours_adjusted_by']);

    if ($adminLocked) {
        $update = $pdo->prepare(
            'UPDATE attendance SET
                work_end_at = :work_end_at,
                hours_worked = :hours_worked
             WHERE id = :id'
        );
        $update->execute([
            'work_end_at'   => $workEnd->format('Y-m-d H:i:s'),
            'hours_worked'  => $hoursWorked,
            'id'            => $attendanceId,
        ]);

        return;
    }

    $scheduledHours = (float) ($row['scheduled_hours'] ?? 0);
    if ($scheduledHours <= 0) {
        $calc           = calculateWorkHoursForCheckin($row, $workStart);
        $scheduledHours = (float) ($calc['scheduled_hours'] ?? $hoursWorked);
    }

    $update = $pdo->prepare(
        'UPDATE attendance SET
            work_end_at = :work_end_at,
            scheduled_hours = :scheduled_hours,
            hours_worked = :hours_worked,
            hours_paid = :hours_paid
         WHERE id = :id'
    );
    $update->execute([
        'work_end_at'       => $workEnd->format('Y-m-d H:i:s'),
        'scheduled_hours'   => $scheduledHours,
        'hours_worked'      => $hoursWorked,
        'hours_paid'        => $hoursWorked,
        'id'                => $attendanceId,
    ]);
}

/**
 * @return array{ok: bool, signed_out?: bool, message?: string, reason?: string}
 */
function autoSignOutAttendance(PDO $pdo, int $attendanceId, string $reason): array
{
    ensureAttendanceGpsSignoutSchema($pdo);
    ensureAttendanceGpsPhase1Schema($pdo);

    if (!isGpsAttendanceV2Enabled($pdo)) {
        return ['ok' => false, 'message' => 'GPS attendance is not enabled.'];
    }

    $stmt = $pdo->prepare('SELECT id, attendance_status FROM attendance WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $attendanceId]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['ok' => false, 'message' => 'Attendance not found.'];
    }

    $status = strtolower((string) ($row['attendance_status'] ?? ATTENDANCE_STATUS_ACTIVE));
    if ($status === ATTENDANCE_STATUS_AUTO_SIGNED_OUT) {
        return [
            'ok'          => true,
            'signed_out'  => true,
            'reason'      => (string) ($row['signout_reason'] ?? $reason),
            'message'     => getAutoSignoutMessage((string) ($row['signout_reason'] ?? $reason)),
        ];
    }

    if ($status !== ATTENDANCE_STATUS_ACTIVE) {
        return ['ok' => false, 'message' => 'Attendance is not active.'];
    }

    $checkedOutAt = new DateTime('now');

    $update = $pdo->prepare(
        "UPDATE attendance SET
            attendance_status = :signed_out_status,
            checked_out_at = :checked_out_at,
            signout_reason = :reason,
            gps_outside_strikes = 0
         WHERE id = :id
           AND attendance_status = :active_status"
    );
    $update->execute([
        'signed_out_status' => ATTENDANCE_STATUS_AUTO_SIGNED_OUT,
        'checked_out_at'    => $checkedOutAt->format('Y-m-d H:i:s'),
        'reason'            => $reason,
        'id'                => $attendanceId,
        'active_status'     => ATTENDANCE_STATUS_ACTIVE,
    ]);

    if ($update->rowCount() === 0) {
        return ['ok' => false, 'message' => 'Could not sign out — attendance state changed.'];
    }

    finalizeWorkHoursOnSignOut($pdo, $attendanceId, $checkedOutAt);

    return [
        'ok'         => true,
        'signed_out' => true,
        'reason'     => $reason,
        'message'    => getAutoSignoutMessage($reason),
    ];
}

function resetAttendanceGeofenceStrikes(PDO $pdo, int $attendanceId): void
{
    ensureAttendanceGpsSignoutSchema($pdo);

    $stmt = $pdo->prepare(
        'UPDATE attendance SET gps_outside_strikes = 0 WHERE id = :id AND gps_outside_strikes > 0'
    );
    $stmt->execute(['id' => $attendanceId]);
}

function incrementAttendanceGeofenceStrikes(PDO $pdo, int $attendanceId): int
{
    ensureAttendanceGpsSignoutSchema($pdo);

    $stmt = $pdo->prepare(
        'UPDATE attendance SET gps_outside_strikes = LEAST(gps_outside_strikes + 1, 255) WHERE id = :id'
    );
    $stmt->execute(['id' => $attendanceId]);

    $read = $pdo->prepare('SELECT gps_outside_strikes FROM attendance WHERE id = :id LIMIT 1');
    $read->execute(['id' => $attendanceId]);

    return (int) ($read->fetchColumn() ?: 0);
}

/**
 * GPS heartbeat for venue sign-in page — activation + geofence auto sign-out.
 *
 * @param array<string, mixed> $attendance
 * @param array<string, mixed> $event
 * @param array{lat: float, lng: float, accuracy_m: ?int}|null $gps
 * @return array<string, mixed>
 */
function processGpsAttendancePing(PDO $pdo, array $attendance, array $event, ?array $gps): array
{
    ensureAttendanceGpsSignoutSchema($pdo);

    $attendanceId   = (int) ($attendance['id'] ?? 0);
    $status         = strtolower((string) ($attendance['attendance_status'] ?? ATTENDANCE_STATUS_ACTIVE));

    if ($status === ATTENDANCE_STATUS_AUTO_SIGNED_OUT) {
        $reason = (string) ($attendance['signout_reason'] ?? 'left_geofence');

        return [
            'ok'         => true,
            'signed_out' => true,
            'status'     => ATTENDANCE_STATUS_AUTO_SIGNED_OUT,
            'reason'     => $reason,
            'message'    => getAutoSignoutMessage($reason),
        ];
    }

    if ($status !== ATTENDANCE_STATUS_PRE_CHECKED_IN && $status !== ATTENDANCE_STATUS_ACTIVE) {
        return ['ok' => false, 'error' => 'Attendance is not in a GPS-verifiable state.'];
    }

    if ($gps === null) {
        return ['ok' => false, 'error' => getGpsRequiredMessage(), 'in_zone' => false];
    }

    updateAttendanceLastGps($pdo, $attendanceId, $gps);

    $check = validateGpsForCheckin($pdo, $event, $gps);

    if ($status === ATTENDANCE_STATUS_PRE_CHECKED_IN) {
        if (!$check['ok']) {
            return ['ok' => false, 'error' => $check['message'], 'in_zone' => false];
        }

        $activated = maybeActivateHibernatedAttendanceForRegistration($pdo, (int) ($attendance['registration_id'] ?? 0));

        return [
            'ok'        => true,
            'in_zone'   => true,
            'activated' => $activated,
            'status'    => $activated ? ATTENDANCE_STATUS_ACTIVE : ATTENDANCE_STATUS_PRE_CHECKED_IN,
            'monitoring'=> $activated,
        ];
    }

    if ($check['ok']) {
        resetAttendanceGeofenceStrikes($pdo, $attendanceId);

        return [
            'ok'         => true,
            'in_zone'    => true,
            'status'     => ATTENDANCE_STATUS_ACTIVE,
            'monitoring' => true,
        ];
    }

    $strikes = incrementAttendanceGeofenceStrikes($pdo, $attendanceId);
    if ($strikes >= GPS_GEOFENCE_EXIT_STRIKES) {
        $result = autoSignOutAttendance($pdo, $attendanceId, 'left_geofence');

        return array_merge($result, [
            'in_zone'    => false,
            'status'     => ATTENDANCE_STATUS_AUTO_SIGNED_OUT,
            'monitoring' => false,
        ]);
    }

    return [
        'ok'              => true,
        'in_zone'         => false,
        'outside_warning' => true,
        'strikes'         => $strikes,
        'strikes_needed'  => GPS_GEOFENCE_EXIT_STRIKES,
        'status'          => ATTENDANCE_STATUS_ACTIVE,
        'monitoring'      => true,
        'message'         => $check['message'] . ' Stay inside the zone or you will be signed out automatically.',
    ];
}

/**
 * Server-side catch-up when the staff app stops sending GPS (backgrounded / closed).
 * Re-checks last known coordinates so off-venue staff are signed out without keeping the page open.
 */
function enforceStaleGeofenceSignouts(PDO $pdo): int
{
    if (!isGpsAttendanceV2Enabled($pdo)) {
        return 0;
    }

    ensureAttendanceGpsSignoutSchema($pdo);
    ensureAttendanceGpsPhase15Schema($pdo);
    require_once __DIR__ . '/date-format.php';
    require_once __DIR__ . '/events-repository.php';

    $today = getOperationalTodayYmd($pdo);
    $stmt  = $pdo->prepare(
        "SELECT a.*, e.id AS event_row_id, e.venue_lat, e.venue_lng, e.venue_eircode,
                e.signin_radius_m, e.location, e.event_date
         FROM attendance a
         INNER JOIN events e ON e.id = a.event_id
         WHERE e.event_date = :today
           AND a.attendance_status = :active
           AND a.last_gps_at IS NOT NULL
           AND a.last_gps_lat IS NOT NULL
           AND a.last_gps_lng IS NOT NULL"
    );
    $stmt->execute([
        'today'  => $today,
        'active' => ATTENDANCE_STATUS_ACTIVE,
    ]);

    $signedOut = 0;
    $staleSec  = GPS_GEOFENCE_STALE_SIGNOUT_MINUTES * 60;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $attendanceId = (int) ($row['id'] ?? 0);
        if ($attendanceId < 1) {
            continue;
        }

        $event = getEventById($pdo, (int) ($row['event_id'] ?? 0));
        if ($event === null) {
            continue;
        }

        $gps = [
            'lat'        => (float) $row['last_gps_lat'],
            'lng'        => (float) $row['last_gps_lng'],
            'accuracy_m' => $row['last_gps_accuracy_m'] !== null ? (int) $row['last_gps_accuracy_m'] : null,
        ];
        $check = validateGpsForCheckin($pdo, $event, $gps);
        if ($check['ok']) {
            continue;
        }

        $lastAt = strtotime((string) ($row['last_gps_at'] ?? ''));
        if ($lastAt < 1) {
            continue;
        }

        $ageSec  = time() - $lastAt;
        $strikes = (int) ($row['gps_outside_strikes'] ?? 0);
        $shouldSignOut = $strikes >= GPS_GEOFENCE_EXIT_STRIKES
            || ($strikes >= 1 && $ageSec >= 90)
            || $ageSec >= $staleSec;

        if (!$shouldSignOut) {
            continue;
        }

        $result = autoSignOutAttendance($pdo, $attendanceId, 'left_geofence');
        if (!empty($result['signed_out'])) {
            $signedOut++;
        }
    }

    return $signedOut;
}
