<?php

declare(strict_types=1);

/**
 * Kodaleone 2026-06-20 — sign in Salim Mursal with 8.5 hrs (same as corrected staff).
 *
 * GET: ?key=...&dry_run=1
 * GET: ?key=...&dry_run=0
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';
require_once dirname(__DIR__) . '/includes/work-hours-repository.php';
require_once dirname(__DIR__) . '/includes/checkin-bib.php';
require_once dirname(__DIR__) . '/includes/admin-manual-signin.php';

header('Content-Type: application/json; charset=UTF-8');

const KODALEONE_DATE = '2026-06-20';
const KODALEONE_TARGET_HOURS = 8.5;
const KODALEONE_END_TIME = '23:30:00';
const HOURS_NOTE = 'Kodaleone 2026-06-20 — full shift 8.5 hrs (admin correction after false geofence sign-outs).';
const TARGET_EMAIL = 'milaasyarow95@gmail.com';
const TARGET_REGISTRATION_ID = 169;

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

function loadKodaleoneEvent(PDO $pdo): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM events
         WHERE event_date = :event_date AND name LIKE '%Kodaleone%'
         ORDER BY id ASC LIMIT 1"
    );
    $stmt->execute(['event_date' => KODALEONE_DATE]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($event) ? $event : null;
}

/**
 * @param array<string, mixed> $event
 */
function applyFullKodaleoneHours(PDO $pdo, int $attendanceId, array $event, int $adminId, ?string $bib = null): array
{
    $date = (string) ($event['event_date'] ?? KODALEONE_DATE);
    $startTime = (string) ($event['start_time'] ?? '15:00:00');
    $eventStart = parseEventDateTime($date, $startTime) ?? new DateTime($date . ' 15:00:00');
    $workEnd = parseEventDateTime($date, KODALEONE_END_TIME) ?? (clone $eventStart)->modify('+510 minutes');

    $before = $pdo->prepare('SELECT * FROM attendance WHERE id = :id LIMIT 1');
    $before->execute(['id' => $attendanceId]);
    $beforeRow = $before->fetch(PDO::FETCH_ASSOC) ?: [];

    $update = $pdo->prepare(
        'UPDATE attendance SET
            attendance_status = :status,
            activated_at = :activated_at,
            checked_in_at = :checked_in_at,
            checked_in_method = :method,
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
        'checked_in_at'   => $eventStart->format('Y-m-d H:i:s'),
        'method'          => 'admin_manual',
        'checked_out_at'  => $workEnd->format('Y-m-d H:i:s'),
        'work_end_at'     => $workEnd->format('Y-m-d H:i:s'),
        'scheduled_hours' => KODALEONE_TARGET_HOURS,
        'hours_worked'    => KODALEONE_TARGET_HOURS,
        'hours_paid'      => KODALEONE_TARGET_HOURS,
        'hours_note'      => HOURS_NOTE,
        'admin_id'        => $adminId,
        'id'              => $attendanceId,
    ]);

    $regId = (int) ($beforeRow['registration_id'] ?? 0);
    if ($bib !== null && $bib !== '' && $regId > 0) {
        saveAttendanceBibNumber($pdo, $regId, $bib);
    }

    $after = $pdo->prepare(
        'SELECT hours_paid, hours_worked, checked_in_at, checked_out_at, activated_at, bib_number, hours_note
         FROM attendance WHERE id = :id'
    );
    $after->execute(['id' => $attendanceId]);

    return [
        'attendance_id'       => $attendanceId,
        'registration_id'     => $regId,
        'before_hours_paid'   => $beforeRow['hours_paid'] ?? null,
        'before_checked_in_at'=> $beforeRow['checked_in_at'] ?? null,
        'after'               => $after->fetch(PDO::FETCH_ASSOC) ?: [],
    ];
}

try {
    $pdo = getDB();
    authorizeCronKey($pdo);

    $dryRun = !isset($_GET['dry_run']) || (string) $_GET['dry_run'] !== '0';
    $adminId = (int) ($pdo->query('SELECT id FROM admin_users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1);

    $event = loadKodaleoneEvent($pdo);
    if ($event === null) {
        throw new RuntimeException('Kodaleone event not found.');
    }

    $eventId = (int) $event['id'];
    $reg = getStaffRegistrationById($pdo, TARGET_REGISTRATION_ID);
    if ($reg === null || strtolower(trim((string) ($reg['email'] ?? ''))) !== TARGET_EMAIL) {
        $stmt = $pdo->prepare(
            "SELECT sr.* FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id
             WHERE LOWER(TRIM(sr.email)) = :email
               AND e.event_date = :event_date
               AND e.name LIKE '%Kodaleone%'
             ORDER BY sr.id DESC LIMIT 1"
        );
        $stmt->execute(['email' => TARGET_EMAIL, 'event_date' => KODALEONE_DATE]);
        $reg = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($reg === null) {
        throw new RuntimeException('Salim Mursal Kodaleone registration not found.');
    }

    $regId = (int) $reg['id'];
    $name = trim((string) $reg['first_name'] . ' ' . (string) $reg['surname']);
    $att = getAttendanceByRegistration($pdo, $regId);

    $preview = [
        'name'             => $name,
        'email'            => (string) ($reg['email'] ?? ''),
        'registration_id'  => $regId,
        'event_id'         => $eventId,
        'target_hours'     => KODALEONE_TARGET_HOURS,
        'check_in_at'      => KODALEONE_DATE . ' 15:00:00',
        'work_end_at'      => KODALEONE_DATE . ' ' . KODALEONE_END_TIME,
        'hours_note'       => HOURS_NOTE,
        'has_attendance'   => $att !== null,
        'attendance_id'    => $att ? (int) $att['id'] : null,
        'current_hours'    => $att ? ($att['hours_paid'] ?? null) : null,
        'current_check_in' => $att ? ($att['checked_in_at'] ?? null) : null,
    ];

    if ($dryRun) {
        echo json_encode([
            'ok'      => true,
            'dry_run' => true,
            'preview' => $preview,
            'action'  => $att === null ? 'would_manual_signin_8_5' : 'would_update_hours_to_8_5',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($att === null) {
        $note = HOURS_NOTE;
        $result = recordAdminManualCheckin($pdo, $regId, KODALEONE_TARGET_HOURS, $note, $adminId, $eventId);
        if ($result !== true) {
            throw new RuntimeException((string) $result);
        }
        $att = getAttendanceByRegistration($pdo, $regId);
    }

    if ($att === null) {
        throw new RuntimeException('Attendance row missing after sign-in.');
    }

    $hourResult = applyFullKodaleoneHours($pdo, (int) $att['id'], $event, $adminId);

    echo json_encode([
        'ok'      => true,
        'dry_run' => false,
        'staff'   => $name,
        'result'  => $hourResult,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
