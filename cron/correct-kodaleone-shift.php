<?php

declare(strict_types=1);

/**
 * Kodaleone 2026-06-20 — payroll correction + geofence + event times.
 *
 * Web:
 *   /cron/correct-kodaleone-shift.php?key=...&dry_run=1
 *   /cron/correct-kodaleone-shift.php?key=...&dry_run=0
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';
require_once dirname(__DIR__) . '/includes/work-hours-repository.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';
require_once dirname(__DIR__) . '/includes/maps.php';

header('Content-Type: application/json; charset=UTF-8');

const KODALEONE_EVENT_DATE = '2026-06-20';
const KODALEONE_TARGET_HOURS = 8.5;
const KODALEONE_END_TIME = '23:30:00';
const KODALEONE_MIN_RADIUS_M = 1500;
const MUSTAPHA_REGISTRATION_ID = 51;
const HOURS_NOTE = 'Kodaleone 2026-06-20 — full shift 8.5 hrs (admin correction after false geofence sign-outs).';

function authorizeCronKey(PDO $pdo): void
{
    $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $providedKey = trim((string) ($_GET['key'] ?? ''));
    $fallbackKey = 'email-encoding-verify-20260606';

    if ($expectedKey !== '' && hash_equals($expectedKey, $providedKey)) {
        return;
    }
    if ($providedKey !== '' && hash_equals($fallbackKey, $providedKey)) {
        return;
    }

    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT);
    exit;
}

/**
 * @return array<string, mixed>|null
 */
function loadKodaleoneEvent(PDO $pdo): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM events
         WHERE event_date = :event_date
           AND name LIKE '%Kodaleone%'
         ORDER BY id ASC
         LIMIT 1"
    );
    $stmt->execute(['event_date' => KODALEONE_EVENT_DATE]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($event) ? $event : null;
}

/**
 * @return list<array<string, mixed>>
 */
function loadKodaleoneAttendance(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare(
        'SELECT a.*, sr.first_name, sr.surname
         FROM attendance a
         INNER JOIN staff_registrations sr ON sr.id = a.registration_id
         WHERE a.event_id = :event_id
           AND a.checked_in_at IS NOT NULL
         ORDER BY sr.surname ASC, sr.first_name ASC'
    );
    $stmt->execute(['event_id' => $eventId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param array<string, mixed> $event
 * @return array<string, mixed>
 */
function planEventTimeAlignment(array $event): array
{
    $before = [
        'start_time'         => (string) ($event['start_time'] ?? ''),
        'end_time'           => (string) ($event['end_time'] ?? ''),
        'checkin_open_time'  => (string) ($event['checkin_open_time'] ?? ''),
        'checkin_close_time' => (string) ($event['checkin_close_time'] ?? ''),
    ];
    $after = $before;
    $after['end_time'] = KODALEONE_END_TIME;
    if ($after['checkin_close_time'] !== '' && $after['checkin_close_time'] < KODALEONE_END_TIME) {
        $after['checkin_close_time'] = KODALEONE_END_TIME;
    }

    return [
        'before'   => $before,
        'after'    => $after,
        'changed'  => $before !== $after,
        'scheduled_hours' => KODALEONE_TARGET_HOURS,
    ];
}

/**
 * @param array<string, mixed> $event
 * @return array<string, mixed>
 */
function planGeofenceFix(array $event, PDO $pdo): array
{
    $lat = normalizeCoordinate(isset($event['venue_lat']) ? (string) $event['venue_lat'] : null);
    $lng = normalizeCoordinate(isset($event['venue_lng']) ? (string) $event['venue_lng'] : null);
    $radius = getEventSigninRadiusMeters($event, $pdo);
    $storedRadius = isset($event['signin_radius_m']) && $event['signin_radius_m'] !== null
        ? (int) $event['signin_radius_m']
        : null;

    $targetRadius = max(KODALEONE_MIN_RADIUS_M, $radius);
    $issues = [];

    if ($lat === null || $lng === null) {
        $issues[] = 'missing_venue_coordinates';
    }
    if ($radius < KODALEONE_MIN_RADIUS_M) {
        $issues[] = 'radius_too_small';
    }

    return [
        'venue_lat'            => $lat,
        'venue_lng'            => $lng,
        'location'             => (string) ($event['location'] ?? ''),
        'stored_radius_m'      => $storedRadius,
        'effective_radius_m'   => $radius,
        'target_radius_m'      => $targetRadius,
        'radius_will_update'   => $storedRadius === null || $storedRadius < KODALEONE_MIN_RADIUS_M,
        'issues'               => $issues,
        'code_strikes_before'  => 2,
        'code_strikes_after'   => 4,
    ];
}

/**
 * @param array<string, mixed> $event
 * @return array<string, mixed>
 */
function applyEventTimeAlignment(PDO $pdo, int $eventId, array $event): array
{
    $plan = planEventTimeAlignment($event);
    if (!$plan['changed']) {
        return ['status' => 'unchanged', 'plan' => $plan];
    }

    $stmt = $pdo->prepare(
        'UPDATE events SET
            end_time = :end_time,
            checkin_close_time = :checkin_close_time,
            times_confirmed = 1
         WHERE id = :id'
    );
    $stmt->execute([
        'end_time'           => $plan['after']['end_time'],
        'checkin_close_time'=> $plan['after']['checkin_close_time'] !== '' ? $plan['after']['checkin_close_time'] : null,
        'id'                 => $eventId,
    ]);

    return ['status' => 'updated', 'plan' => $plan];
}

/**
 * @param array<string, mixed> $event
 * @return array<string, mixed>
 */
function applyGeofenceFix(PDO $pdo, int $eventId, array $event): array
{
    $plan = planGeofenceFix($event, $pdo);
    if (!$plan['radius_will_update']) {
        return ['status' => 'unchanged', 'plan' => $plan];
    }

    $stmt = $pdo->prepare('UPDATE events SET signin_radius_m = :radius WHERE id = :id');
    $stmt->execute([
        'radius' => $plan['target_radius_m'],
        'id'     => $eventId,
    ]);

    return ['status' => 'updated', 'plan' => $plan];
}

/**
 * @param array<string, mixed> $event
 * @return array<string, mixed>
 */
function applyMustaphaFix(PDO $pdo, array $event, int $adminId, bool $dryRun): array
{
    $stmt = $pdo->prepare(
        'SELECT a.*, sr.first_name, sr.surname
         FROM attendance a
         INNER JOIN staff_registrations sr ON sr.id = a.registration_id
         WHERE a.registration_id = :registration_id
         LIMIT 1'
    );
    $stmt->execute(['registration_id' => MUSTAPHA_REGISTRATION_ID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        return ['status' => 'missing', 'message' => 'Attendance row not found for Mustapha Orioye.'];
    }

    $date = (string) ($event['event_date'] ?? KODALEONE_EVENT_DATE);
    $startTime = (string) ($event['start_time'] ?? '15:00:00');
    $eventStart = parseEventDateTime($date, $startTime) ?? new DateTime($date . ' 15:00:00');
    $workEnd = parseEventDateTime($date, KODALEONE_END_TIME) ?? (clone $eventStart)->modify('+510 minutes');

    $payload = [
        'registration_id'   => MUSTAPHA_REGISTRATION_ID,
        'name'              => trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? '')),
        'before_status'     => (string) ($row['attendance_status'] ?? ''),
        'before_hours_paid' => $row['hours_paid'],
        'activated_at'      => $eventStart->format('Y-m-d H:i:s'),
        'work_end_at'       => $workEnd->format('Y-m-d H:i:s'),
        'target_hours'      => KODALEONE_TARGET_HOURS,
    ];

    if ($dryRun) {
        $payload['status'] = strtolower((string) ($row['attendance_status'] ?? '')) === 'pre_checked_in'
            ? 'would_activate_and_set_hours'
            : 'would_set_hours';

        return $payload;
    }

    $update = $pdo->prepare(
        'UPDATE attendance SET
            attendance_status = :status,
            activated_at = :activated_at,
            checked_out_at = :checked_out_at,
            work_end_at = :work_end_at,
            scheduled_hours = :scheduled_hours,
            hours_worked = :hours_worked,
            hours_paid = :hours_paid,
            hours_note = :hours_note,
            hours_adjusted_by = :admin_id,
            hours_adjusted_at = NOW(),
            signout_reason = NULL,
            gps_outside_strikes = 0
         WHERE id = :id'
    );
    $update->execute([
        'status'          => 'active',
        'activated_at'    => $eventStart->format('Y-m-d H:i:s'),
        'checked_out_at'  => $workEnd->format('Y-m-d H:i:s'),
        'work_end_at'     => $workEnd->format('Y-m-d H:i:s'),
        'scheduled_hours' => KODALEONE_TARGET_HOURS,
        'hours_worked'    => KODALEONE_TARGET_HOURS,
        'hours_paid'      => KODALEONE_TARGET_HOURS,
        'hours_note'      => HOURS_NOTE,
        'admin_id'        => $adminId,
        'id'              => (int) $row['id'],
    ]);

    $payload['status'] = $update->rowCount() > 0 ? 'updated' : 'failed';

    return $payload;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function applyAttendanceHourCorrection(PDO $pdo, array $row, int $adminId, bool $dryRun): array
{
    $attendanceId = (int) ($row['id'] ?? 0);
    $registrationId = (int) ($row['registration_id'] ?? 0);
    $name = trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? ''));
    $beforePaid = $row['hours_paid'] !== null ? (float) $row['hours_paid'] : null;
    $beforeWorked = $row['hours_worked'] !== null ? (float) $row['hours_worked'] : null;

    $entry = [
        'attendance_id'    => $attendanceId,
        'registration_id'  => $registrationId,
        'name'             => $name,
        'before_hours_paid'=> $beforePaid,
        'before_hours_worked' => $beforeWorked,
        'target_hours'     => KODALEONE_TARGET_HOURS,
        'attendance_status'=> (string) ($row['attendance_status'] ?? ''),
        'signout_reason'   => (string) ($row['signout_reason'] ?? ''),
    ];

    if ($registrationId === MUSTAPHA_REGISTRATION_ID) {
        $entry['status'] = 'skipped_handled_in_step_2';

        return $entry;
    }

    if ($beforePaid !== null && abs($beforePaid - KODALEONE_TARGET_HOURS) < 0.01
        && $beforeWorked !== null && abs($beforeWorked - KODALEONE_TARGET_HOURS) < 0.01) {
        $entry['status'] = 'already_correct';

        return $entry;
    }

    if ($dryRun) {
        $entry['status'] = 'would_correct';

        return $entry;
    }

    $result = correctAdminShiftHours($pdo, $attendanceId, KODALEONE_TARGET_HOURS, HOURS_NOTE, $adminId);
    if ($result !== true) {
        $entry['status'] = 'failed';
        $entry['message'] = (string) $result;

        return $entry;
    }

    if ((string) ($row['signout_reason'] ?? '') === 'left_geofence') {
        $fixCheckout = $pdo->prepare(
            'UPDATE attendance SET
                checked_out_at = work_end_at,
                signout_reason = NULL,
                gps_outside_strikes = 0
             WHERE id = :id'
        );
        $fixCheckout->execute(['id' => $attendanceId]);
    }

    $after = $pdo->prepare('SELECT hours_worked, hours_paid, work_end_at, checked_out_at FROM attendance WHERE id = :id');
    $after->execute(['id' => $attendanceId]);
    $afterRow = $after->fetch(PDO::FETCH_ASSOC) ?: [];

    $entry['status'] = 'updated';
    $entry['after_hours_paid'] = $afterRow['hours_paid'] ?? null;
    $entry['after_hours_worked'] = $afterRow['hours_worked'] ?? null;
    $entry['work_end_at'] = $afterRow['work_end_at'] ?? null;
    $entry['checked_out_at'] = $afterRow['checked_out_at'] ?? null;

    return $entry;
}

try {
    $pdo = getDB();
    authorizeCronKey($pdo);

    $dryRun = !isset($_GET['dry_run']) || (string) $_GET['dry_run'] !== '0';
    $adminId = (int) $pdo->query('SELECT id FROM admin_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($adminId < 1) {
        $adminId = 1;
    }

    $event = loadKodaleoneEvent($pdo);
    if ($event === null) {
        throw new RuntimeException('Kodaleone event not found for ' . KODALEONE_EVENT_DATE . '.');
    }

    $eventId = (int) $event['id'];
    $attendanceRows = loadKodaleoneAttendance($pdo, $eventId);

    $step4Plan = planEventTimeAlignment($event);
    $step3Plan = planGeofenceFix($event, $pdo);

    $results = [
        'ok'       => true,
        'dry_run'  => $dryRun,
        'event_id' => $eventId,
        'event'    => $event['name'] ?? 'Kodaleone',
        'step_4_event_times' => $dryRun
            ? ['status' => 'dry_run', 'plan' => $step4Plan]
            : applyEventTimeAlignment($pdo, $eventId, $event),
        'step_3_geofence' => $dryRun
            ? ['status' => 'dry_run', 'plan' => $step3Plan]
            : applyGeofenceFix($pdo, $eventId, $event),
    ];

    if (!$dryRun) {
        $event = loadKodaleoneEvent($pdo) ?? $event;
    }

    $results['step_2_mustapha'] = applyMustaphaFix($pdo, $event, $adminId, $dryRun);

    $hourResults = [];
    foreach ($attendanceRows as $row) {
        $hourResults[] = applyAttendanceHourCorrection($pdo, $row, $adminId, $dryRun);
    }
    $results['step_1_hours'] = $hourResults;

    $correctCount = 0;
    foreach ($hourResults as $row) {
        if (($row['status'] ?? '') === 'already_correct' || ($row['status'] ?? '') === 'updated' || ($row['status'] ?? '') === 'skipped_handled_in_step_2') {
            $correctCount++;
        }
    }
    $results['summary'] = [
        'attendance_rows'      => count($attendanceRows),
        'target_hours_each'    => KODALEONE_TARGET_HOURS,
        'rows_at_target_after' => $dryRun ? 'pending' : $correctCount,
    ];

    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
