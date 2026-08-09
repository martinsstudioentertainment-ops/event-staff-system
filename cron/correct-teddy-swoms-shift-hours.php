<?php

declare(strict_types=1);

/**
 * Teddy Swoms 2026-06-23 shift hours correction.
 *
 * Standard: 16:00–23:30 (7.5 h). Early: 15:00–23:30 (8.5 h) for Pranuthi + Maureen.
 * Manual sign-in for staff who worked but did not sign in.
 *
 * /cron/correct-teddy-swoms-shift-hours.php?key=...&dry_run=1
 * /cron/correct-teddy-swoms-shift-hours.php?key=...&dry_run=0
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/work-hours-schema.php';
require_once dirname(__DIR__) . '/includes/attendance-gps-phase1-schema.php';
require_once dirname(__DIR__) . '/includes/admin-manual-signin.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';

header('Content-Type: application/json; charset=UTF-8');

const TEDDY_EVENT_ID = 6;
const TEDDY_WORK_DATE = '2026-06-23';

/** @var list<int> */
const TEDDY_EARLY_SHIFT_STAFF_IDS = [125, 126];

/**
 * @var list<array{registration_id: int, staff_id: int, bib: ?string, label: string}>
 */
const TEDDY_MANUAL_SIGNIN = [
    ['registration_id' => 536, 'staff_id' => 105, 'bib' => '1601', 'label' => 'Mabeka Enock KABANDA'],
    ['registration_id' => 607, 'staff_id' => 126, 'bib' => '1177', 'label' => 'Pranuthi Chityala'],
    ['registration_id' => 534, 'staff_id' => 114, 'bib' => '1886', 'label' => 'Mpho Mathaba'],
    ['registration_id' => 128, 'staff_id' => 20, 'bib' => '1263', 'label' => 'Mahamoud Mahamed Sayid'],
];

function cronAuthOk(PDO $pdo, string $providedKey): bool
{
    $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $fallbackKey = 'email-encoding-verify-20260606';

    if ($expectedKey !== '' && hash_equals($expectedKey, $providedKey)) {
        return true;
    }

    return $providedKey !== '' && hash_equals($fallbackKey, $providedKey);
}

/**
 * @return array{start: string, end: string, hours: float}
 */
function teddyShiftSpecForStaff(int $staffId): array
{
    if (in_array($staffId, TEDDY_EARLY_SHIFT_STAFF_IDS, true)) {
        return ['start' => '15:00:00', 'end' => '23:30:00', 'hours' => 8.5];
    }

    return ['start' => '16:00:00', 'end' => '23:30:00', 'hours' => 7.5];
}

/**
 * @param array{start: string, end: string, hours: float} $spec
 * @return array{ok: bool, error?: string, check_in?: string, check_out?: string, hours?: float}
 */
function applyTeddyExplicitCorrection(
    PDO $pdo,
    int $attendanceId,
    string $workDate,
    array $spec,
    string $note,
    int $adminId,
    bool $dryRun
): array {
    if ($attendanceId < 1) {
        return ['ok' => false, 'error' => 'Invalid attendance id'];
    }

    $checkIn  = $workDate . ' ' . $spec['start'];
    $checkOut = $workDate . ' ' . $spec['end'];
    $hours    = round((float) $spec['hours'], 2);

    if ($dryRun) {
        return ['ok' => true, 'check_in' => $checkIn, 'check_out' => $checkOut, 'hours' => $hours];
    }

    ensureWorkHoursSchema($pdo);
    ensureAttendanceGpsPhase1Schema($pdo);

    $update = $pdo->prepare(
        'UPDATE attendance SET
            checked_in_at = :checked_in_at,
            activated_at = :activated_at,
            checked_out_at = :checked_out_at,
            work_end_at = :work_end_at,
            scheduled_hours = :scheduled_hours,
            hours_worked = :hours_worked,
            hours_paid = :hours_paid,
            hours_note = :hours_note,
            hours_adjusted_by = :admin_id,
            hours_adjusted_at = NOW(),
            attendance_status = :attendance_status
         WHERE id = :id'
    );
    $update->execute([
        'checked_in_at'     => $checkIn,
        'activated_at'      => $checkIn,
        'checked_out_at'    => $checkOut,
        'work_end_at'       => $checkOut,
        'scheduled_hours'   => $hours,
        'hours_worked'      => $hours,
        'hours_paid'        => $hours,
        'hours_note'        => $note,
        'admin_id'          => $adminId,
        'attendance_status' => 'completed',
        'id'                => $attendanceId,
    ]);

    return ['ok' => true, 'check_in' => $checkIn, 'check_out' => $checkOut, 'hours' => $hours];
}

try {
    $pdo = getDB();
    $providedKey = trim((string) ($_GET['key'] ?? ''));
    if (!cronAuthOk($pdo, $providedKey)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT);
        exit;
    }

    $dryRun   = !isset($_GET['dry_run']) || (string) $_GET['dry_run'] !== '0';
    $eventId  = (int) ($_GET['event_id'] ?? TEDDY_EVENT_ID);
    $workDate = trim((string) ($_GET['date'] ?? TEDDY_WORK_DATE));
    $note     = 'Teddy Swoms 2026-06-23 shift hours corrected — standard 16:00–23:30 (7.5 h) / early 15:00–23:30 (8.5 h).';

    $adminId = (int) $pdo->query('SELECT id FROM admin_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($adminId < 1) {
        $adminId = 1;
    }

    $manualResults = [];
    foreach (TEDDY_MANUAL_SIGNIN as $entry) {
        $registrationId = (int) $entry['registration_id'];
        $staffId        = (int) $entry['staff_id'];
        $spec           = teddyShiftSpecForStaff($staffId);
        $row            = [
            'registration_id' => $registrationId,
            'staff_id'        => $staffId,
            'label'           => $entry['label'],
            'bib'             => $entry['bib'],
            'shift'           => $spec,
        ];

        $regStmt = $pdo->prepare(
            'SELECT id, event_id, status FROM staff_registrations WHERE id = :id LIMIT 1'
        );
        $regStmt->execute(['id' => $registrationId]);
        $reg = $regStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($reg) || (int) ($reg['event_id'] ?? 0) !== $eventId) {
            $row['status'] = 'failed';
            $row['error']  = 'Registration not found on Teddy Swoms';
            $manualResults[] = $row;
            continue;
        }

        if (!$dryRun && $entry['bib'] !== null && $entry['bib'] !== '' && staffRegistrationColumnExists($pdo, 'assigned_bib_number')) {
            $pdo->prepare(
                'UPDATE staff_registrations SET assigned_bib_number = :bib, updated_at = NOW() WHERE id = :id'
            )->execute(['bib' => $entry['bib'], 'id' => $registrationId]);
        }

        if ($dryRun) {
            $row['status'] = hasCheckedIn($pdo, $registrationId) ? 'would_correct_existing' : 'would_create_attendance';
            $manualResults[] = $row;
            continue;
        }

        if (hasCheckedIn($pdo, $registrationId)) {
            $attStmt = $pdo->prepare(
                'SELECT id FROM attendance WHERE registration_id = :rid ORDER BY id DESC LIMIT 1'
            );
            $attStmt->execute(['rid' => $registrationId]);
            $attendanceId = (int) ($attStmt->fetchColumn() ?: 0);
            $corr = applyTeddyExplicitCorrection(
                $pdo,
                $attendanceId,
                $workDate,
                $spec,
                $note . ' Manual sign-in correction.',
                $adminId,
                false
            );
            $row['status']     = $corr['ok'] ? 'corrected_existing' : 'failed';
            $row['attendance'] = $corr;
            if (!$corr['ok']) {
                $row['error'] = (string) ($corr['error'] ?? 'Correction failed');
            }
            $manualResults[] = $row;
            continue;
        }

        $checkIn = $workDate . ' ' . $spec['start'];
        $restore = restoreStaffShiftAttendance(
            $pdo,
            $registrationId,
            $spec['hours'],
            $note . ' Manual sign-in — worked but did not complete venue sign-in.',
            $checkIn,
            false
        );
        $row['status']     = !empty($restore['ok']) ? 'signed_in' : 'failed';
        $row['attendance'] = $restore;
        if (empty($restore['ok'])) {
            $row['error'] = (string) ($restore['error'] ?? 'Manual sign-in failed');
        }
        $manualResults[] = $row;
    }

    $stmt = $pdo->prepare(
        'SELECT a.id AS attendance_id, a.hours_paid, a.hours_worked, a.checked_in_at, a.checked_out_at,
                sr.id AS registration_id, sr.staff_id, sr.first_name, sr.surname
         FROM attendance a
         INNER JOIN staff_registrations sr ON sr.id = a.registration_id
         WHERE a.event_id = :event_id
         ORDER BY sr.surname, sr.first_name'
    );
    $stmt->execute(['event_id' => $eventId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $corrections = [];
    $corrected   = 0;
    $skipped     = 0;
    $failed      = 0;

    foreach ($rows as $row) {
        $attendanceId = (int) ($row['attendance_id'] ?? 0);
        $staffId      = (int) ($row['staff_id'] ?? 0);
        $spec         = teddyShiftSpecForStaff($staffId);
        $beforePaid   = round((float) ($row['hours_paid'] ?? 0), 2);
        $targetPaid   = $spec['hours'];

        $entry = [
            'attendance_id' => $attendanceId,
            'staff_id'      => $staffId,
            'name'          => trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? '')),
            'before_paid'   => $beforePaid,
            'target_paid'   => $targetPaid,
            'target_shift'  => $spec['start'] . '–' . $spec['end'],
        ];

        $checkIn  = $workDate . ' ' . $spec['start'];
        $checkOut = $workDate . ' ' . $spec['end'];
        $already  = abs($beforePaid - $targetPaid) < 0.01
            && (string) ($row['checked_in_at'] ?? '') === $checkIn
            && (string) ($row['checked_out_at'] ?? '') === $checkOut;

        if ($already) {
            $entry['status'] = 'skipped';
            $entry['reason'] = 'Already correct';
            $skipped++;
            $corrections[] = $entry;
            continue;
        }

        $result = applyTeddyExplicitCorrection(
            $pdo,
            $attendanceId,
            $workDate,
            $spec,
            $note,
            $adminId,
            $dryRun
        );

        if (!empty($result['ok'])) {
            $entry['status'] = $dryRun ? 'would_correct' : 'corrected';
            $corrected++;
        } else {
            $entry['status'] = 'failed';
            $entry['error']  = (string) ($result['error'] ?? 'Unknown error');
            $failed++;
        }
        $entry['after'] = $result;
        $corrections[] = $entry;
    }

    echo json_encode([
        'ok'          => $failed === 0,
        'dry_run'     => $dryRun,
        'event_id'    => $eventId,
        'date'        => $workDate,
        'manual'      => $manualResults,
        'total_rows'  => count($rows),
        'corrected'   => $corrected,
        'skipped'     => $skipped,
        'failed'      => $failed,
        'corrections' => $corrections,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
