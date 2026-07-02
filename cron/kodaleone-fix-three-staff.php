<?php

declare(strict_types=1);

/**
 * Kodaleone — register Olayinka, sign in, set 8.5 hrs for her + Mohamed + Mahamoud.
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
require_once dirname(__DIR__) . '/includes/staff-registration-schema.php';

header('Content-Type: application/json; charset=UTF-8');

const KODALEONE_DATE = '2026-06-20';
const KODALEONE_TARGET_HOURS = 8.5;
const KODALEONE_END_TIME = '23:30:00';
const HOURS_NOTE = 'Kodaleone 2026-06-20 — full shift 8.5 hrs (admin correction after false geofence sign-outs).';

/** phone_tail => [name, bib] */
const TARGETS = [
    '894387957' => ['name' => 'Olayinka Popoola', 'bib' => '1058', 'action' => 'register_and_checkin'],
    '857886049' => ['name' => 'Mohamed Osman', 'bib' => '1534', 'action' => 'hours_only'],
    '899791498' => ['name' => 'Mahamoud Mahamed Sayid', 'bib' => '1362', 'action' => 'hours_only'],
];

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

function phoneTail(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '0')) {
        $digits = '353' . substr($digits, 1);
    } elseif (!str_starts_with($digits, '353') && strlen($digits) === 9) {
        $digits = '353' . $digits;
    }

    return strlen($digits) >= 9 ? substr($digits, -9) : $digits;
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
 * @return array<string, mixed>|null
 */
function findRegistrationByPhoneTail(PDO $pdo, int $eventId, string $tail): ?array
{
    $stmt = $pdo->prepare(
        'SELECT sr.* FROM staff_registrations sr WHERE sr.event_id = :event_id'
    );
    $stmt->execute(['event_id' => $eventId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (phoneTail((string) ($row['mobile'] ?? '')) === $tail) {
            return $row;
        }
    }

    return null;
}

/**
 * @return array<string, mixed>|null
 */
function findAnyRegistrationByPhoneTail(PDO $pdo, string $tail): ?array
{
    $stmt = $pdo->query(
        'SELECT * FROM staff_registrations WHERE mobile IS NOT NULL AND TRIM(mobile) <> \'\' ORDER BY id DESC'
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (phoneTail((string) ($row['mobile'] ?? '')) === $tail) {
            return $row;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $source
 */
function cloneApprovedRegistration(PDO $pdo, array $source, int $eventId): int
{
    ensureStaffRegistrationSaveSchema($pdo);

    $skip = ['id', 'created_at', 'updated_at', 'status_token', 'checkin_token'];
    $row = [];
    foreach ($source as $key => $value) {
        if (in_array($key, $skip, true)) {
            continue;
        }
        if (!staffRegistrationColumnExists($pdo, (string) $key)) {
            continue;
        }
        $row[$key] = $value;
    }

    $row['event_id'] = $eventId;
    $row['status'] = 'approved';
    if (staffRegistrationColumnExists($pdo, 'status_token')) {
        $row['status_token'] = bin2hex(random_bytes(32));
    }

    $columns = array_keys($row);
    $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);
    $sql = 'INSERT INTO staff_registrations (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $params = [];
    foreach ($row as $col => $val) {
        $params[$col] = $val;
    }
    $stmt->execute($params);

    return (int) $pdo->lastInsertId();
}

/**
 * @param array<string, mixed> $event
 */
function applyFullKodaleoneHours(PDO $pdo, int $attendanceId, array $event, int $adminId, string $bib): array
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
            activated_at = COALESCE(NULLIF(activated_at, \'\'), :activated_at),
            checked_in_at = COALESCE(NULLIF(checked_in_at, \'\'), :checked_in_at),
            checked_in_method = COALESCE(NULLIF(checked_in_method, \'\'), :method),
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
    $checkInAt = !empty($beforeRow['checked_in_at'])
        ? (string) $beforeRow['checked_in_at']
        : $eventStart->format('Y-m-d H:i:s');

    $update->execute([
        'status'          => 'active',
        'activated_at'    => $eventStart->format('Y-m-d H:i:s'),
        'checked_in_at'   => $checkInAt,
        'method'          => 'admin',
        'checked_out_at'  => $workEnd->format('Y-m-d H:i:s'),
        'work_end_at'     => $workEnd->format('Y-m-d H:i:s'),
        'scheduled_hours' => KODALEONE_TARGET_HOURS,
        'hours_worked'    => KODALEONE_TARGET_HOURS,
        'hours_paid'      => KODALEONE_TARGET_HOURS,
        'hours_note'      => HOURS_NOTE,
        'admin_id'        => $adminId,
        'id'              => $attendanceId,
    ]);

    saveAttendanceBibNumber($pdo, (int) ($beforeRow['registration_id'] ?? 0), $bib);

    $after = $pdo->prepare('SELECT hours_paid, hours_worked, checked_in_at, bib_number FROM attendance WHERE id = :id');
    $after->execute(['id' => $attendanceId]);

    return [
        'attendance_id' => $attendanceId,
        'before_hours_paid' => $beforeRow['hours_paid'] ?? null,
        'after' => $after->fetch(PDO::FETCH_ASSOC) ?: [],
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

    $results = [];

    foreach (TARGETS as $tail => $meta) {
        $tail = (string) $tail;
        $entry = [
            'name'   => $meta['name'],
            'bib'    => $meta['bib'],
            'action' => $meta['action'],
        ];

        $reg = findRegistrationByPhoneTail($pdo, $eventId, $tail);

        if ($reg === null && $meta['action'] === 'register_and_checkin') {
            $source = findAnyRegistrationByPhoneTail($pdo, $tail);
            if ($source === null) {
                $entry['status'] = 'failed';
                $entry['error'] = 'No existing registration found to clone.';
                $results[] = $entry;
                continue;
            }
            if ($dryRun) {
                $entry['status'] = 'would_register_and_checkin';
                $entry['clone_from_registration_id'] = (int) $source['id'];
                $results[] = $entry;
                continue;
            }
            $newRegId = cloneApprovedRegistration($pdo, $source, $eventId);
            $reg = getStaffRegistrationById($pdo, $newRegId);
            $entry['registration_id'] = $newRegId;
            $entry['registered'] = true;
        }

        if ($reg === null) {
            $entry['status'] = 'failed';
            $entry['error'] = 'Kodaleone registration not found.';
            $results[] = $entry;
            continue;
        }

        $regId = (int) $reg['id'];
        $entry['registration_id'] = $regId;

        if ($reg['status'] !== 'approved') {
            if ($dryRun) {
                $entry['would_approve'] = true;
            } else {
                $pdo->prepare('UPDATE staff_registrations SET status = \'approved\' WHERE id = :id')
                    ->execute(['id' => $regId]);
            }
        }

        $att = getAttendanceByRegistration($pdo, $regId);
        if ($att === null) {
            if ($dryRun) {
                $entry['status'] = 'would_checkin_and_set_hours';
                $results[] = $entry;
                continue;
            }
            $checkin = recordCheckin($pdo, $regId, 'admin', null, $meta['bib']);
            if ($checkin !== true && $checkin !== 'pre_checked_in') {
                $entry['status'] = 'failed';
                $entry['error'] = (string) $checkin;
                $results[] = $entry;
                continue;
            }
            $att = getAttendanceByRegistration($pdo, $regId);
        }

        if ($att === null) {
            $entry['status'] = 'failed';
            $entry['error'] = 'Attendance row missing after check-in.';
            $results[] = $entry;
            continue;
        }

        $attId = (int) $att['id'];
        $paid = $att['hours_paid'] !== null ? (float) $att['hours_paid'] : 0.0;

        if ($dryRun) {
            $entry['status'] = abs($paid - KODALEONE_TARGET_HOURS) < 0.01 ? 'already_8_5' : 'would_set_8_5';
            $entry['attendance_id'] = $attId;
            $entry['before_hours_paid'] = $paid;
            $results[] = $entry;
            continue;
        }

        $hourResult = applyFullKodaleoneHours($pdo, $attId, $event, $adminId, $meta['bib']);
        $entry['status'] = 'updated';
        $entry['attendance_id'] = $attId;
        $entry = array_merge($entry, $hourResult);
        $results[] = $entry;
    }

    echo json_encode([
        'ok'      => true,
        'dry_run' => $dryRun,
        'event'   => $event['name'] ?? 'Kodaleone',
        'target_hours' => KODALEONE_TARGET_HOURS,
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
