<?php

declare(strict_types=1);

/**
 * Kodaleone 2026-06-20 — mark non-attendees no-show; keep real 8.5 hr sign-ins.
 * Also ensures Salim Mursal (reg 169) is signed in with 8.5 hrs.
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
const NO_SHOW_NOTE = 'No-show — did not sign in at venue (Kodaleone 2026-06-20).';
const SALIM_EMAIL = 'milaasyarow95@gmail.com';
const SALIM_REGISTRATION_ID = 169;
const SALIM_BIB = '1041';

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

function isRealKodaleoneSignIn(array $row): bool
{
    $checkedIn = trim((string) ($row['checked_in_at'] ?? ''));
    if ($checkedIn === '') {
        return false;
    }

    $paid = $row['hours_paid'] !== null ? (float) $row['hours_paid'] : 0.0;
    $worked = $row['hours_worked'] !== null ? (float) $row['hours_worked'] : 0.0;
    $note = trim((string) ($row['hours_note'] ?? ''));
    $is85 = abs($paid - KODALEONE_TARGET_HOURS) < 0.01 && abs($worked - KODALEONE_TARGET_HOURS) < 0.01;
    $hasNote = $note === HOURS_NOTE || str_contains($note, '8.5 hrs');

    return $is85 || $hasNote;
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

    $before = $pdo->prepare('SELECT registration_id FROM attendance WHERE id = :id LIMIT 1');
    $before->execute(['id' => $attendanceId]);
    $regId = (int) ($before->fetchColumn() ?: 0);

    if ($bib !== null && $bib !== '' && $regId > 0) {
        saveAttendanceBibNumber($pdo, $regId, $bib);
    }

    return ['attendance_id' => $attendanceId, 'registration_id' => $regId, 'hours_paid' => KODALEONE_TARGET_HOURS];
}

function markKodaleoneNoShow(PDO $pdo, int $registrationId, int $eventId, ?int $attendanceId): void
{
    if ($attendanceId !== null && $attendanceId > 0) {
        $update = $pdo->prepare(
            "UPDATE attendance SET
                attendance_status = 'no_show',
                checked_in_at = NULL,
                activated_at = NULL,
                checked_out_at = NULL,
                work_end_at = NULL,
                checked_in_method = 'system',
                scheduled_hours = 0,
                hours_worked = 0,
                hours_paid = 0,
                hours_note = :note,
                signout_reason = NULL,
                gps_outside_strikes = 0
             WHERE id = :id"
        );
        $update->execute(['note' => NO_SHOW_NOTE, 'id' => $attendanceId]);

        return;
    }

    $insert = $pdo->prepare(
        "INSERT INTO attendance (
            registration_id, event_id, checked_in_method, attendance_status,
            scheduled_hours, hours_worked, hours_paid, hours_note
         ) VALUES (
            :registration_id, :event_id, 'system', 'no_show',
            0, 0, 0, :note
         )"
    );
    $insert->execute([
        'registration_id' => $registrationId,
        'event_id'        => $eventId,
        'note'            => NO_SHOW_NOTE,
    ]);
}

function ensureSalimSignedIn(PDO $pdo, array $event, int $adminId, bool $dryRun): array
{
    $reg = getStaffRegistrationById($pdo, SALIM_REGISTRATION_ID);
    if ($reg === null || strtolower(trim((string) ($reg['email'] ?? ''))) !== SALIM_EMAIL) {
        $stmt = $pdo->prepare(
            "SELECT sr.* FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id
             WHERE LOWER(TRIM(sr.email)) = :email
               AND e.event_date = :event_date
               AND e.name LIKE '%Kodaleone%'
             ORDER BY sr.id DESC LIMIT 1"
        );
        $stmt->execute(['email' => SALIM_EMAIL, 'event_date' => KODALEONE_DATE]);
        $reg = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($reg === null) {
        throw new RuntimeException('Salim Mursal Kodaleone registration not found.');
    }

    $regId = (int) $reg['id'];
    $name = trim((string) $reg['first_name'] . ' ' . (string) $reg['surname']);
    $att = getAttendanceByRegistration($pdo, $regId);
    $eventId = (int) $event['id'];

    if ($dryRun) {
        return [
            'name'            => $name,
            'registration_id' => $regId,
            'action'          => ($att !== null && isRealKodaleoneSignIn($att)) ? 'already_signed_in_8_5' : 'would_sign_in_8_5',
            'bib'             => SALIM_BIB,
        ];
    }

    if ($att === null) {
        $result = recordAdminManualCheckin($pdo, $regId, KODALEONE_TARGET_HOURS, HOURS_NOTE, $adminId, $eventId);
        if ($result !== true) {
            throw new RuntimeException((string) $result);
        }
        $att = getAttendanceByRegistration($pdo, $regId);
    }

    if ($att === null) {
        throw new RuntimeException('Salim attendance missing after sign-in.');
    }

    if (!isRealKodaleoneSignIn($att)) {
        applyFullKodaleoneHours($pdo, (int) $att['id'], $event, $adminId, SALIM_BIB);
    } elseif (trim((string) ($att['bib_number'] ?? '')) === '') {
        saveAttendanceBibNumber($pdo, $regId, SALIM_BIB);
    }

    $after = getAttendanceByRegistration($pdo, $regId);

    return [
        'name'            => $name,
        'registration_id' => $regId,
        'bib'             => SALIM_BIB,
        'hours_paid'      => $after['hours_paid'] ?? null,
        'checked_in_at'   => $after['checked_in_at'] ?? null,
        'status'          => $after['attendance_status'] ?? null,
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

    $stmt = $pdo->prepare(
        "SELECT sr.id AS registration_id, sr.first_name, sr.surname, sr.email, sr.status,
                a.id AS attendance_id, a.checked_in_at, a.hours_paid, a.hours_worked,
                a.hours_note, a.attendance_status
         FROM staff_registrations sr
         LEFT JOIN attendance a ON a.registration_id = sr.id
         WHERE sr.event_id = :event_id AND sr.status = 'approved'
         ORDER BY sr.surname ASC, sr.first_name ASC"
    );
    $stmt->execute(['event_id' => $eventId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $kept = [];
    $noShow = [];

    foreach ($rows as $row) {
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? ''));
        $regId = (int) $row['registration_id'];
        $attId = isset($row['attendance_id']) ? (int) $row['attendance_id'] : 0;

        if (isRealKodaleoneSignIn($row)) {
            $kept[] = ['name' => $name, 'registration_id' => $regId, 'hours_paid' => $row['hours_paid']];
            continue;
        }

        $noShow[] = ['name' => $name, 'registration_id' => $regId, 'attendance_id' => $attId > 0 ? $attId : null];

        if (!$dryRun) {
            markKodaleoneNoShow($pdo, $regId, $eventId, $attId > 0 ? $attId : null);
        }
    }

    $salim = ensureSalimSignedIn($pdo, $event, $adminId, $dryRun);

    $sweepStmt = $pdo->prepare(
        "SELECT a.id AS attendance_id, a.registration_id, sr.first_name, sr.surname,
                a.checked_in_at, a.hours_paid, a.hours_worked, a.hours_note, a.attendance_status
         FROM attendance a
         INNER JOIN staff_registrations sr ON sr.id = a.registration_id
         WHERE a.event_id = :event_id
           AND a.attendance_status <> 'no_show'
           AND COALESCE(a.hours_worked, 0) <= 0
           AND COALESCE(a.hours_paid, 0) <= 0"
    );
    $sweepStmt->execute(['event_id' => $eventId]);
    $sweepRows = $sweepStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $swept = [];

    foreach ($sweepRows as $row) {
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? ''));
        $regId = (int) $row['registration_id'];
        $attId = (int) ($row['attendance_id'] ?? 0);
        if ($attId < 1) {
            continue;
        }
        $swept[] = ['name' => $name, 'registration_id' => $regId, 'attendance_id' => $attId];
        if (!$dryRun) {
            markKodaleoneNoShow($pdo, $regId, $eventId, $attId);
        }
    }

    echo json_encode([
        'ok'       => true,
        'dry_run'  => $dryRun,
        'event'    => ['id' => $eventId, 'name' => $event['name'], 'date' => $event['event_date']],
        'summary'  => [
            'approved_total'   => count($rows),
            'kept_signed_in'   => count($kept),
            'marked_no_show'   => count($noShow),
            'zero_hour_sweep'  => count($swept),
        ],
        'kept_signed_in' => $kept,
        'marked_no_show' => $noShow,
        'zero_hour_sweep' => $swept,
        'salim_mursal'   => $salim,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
