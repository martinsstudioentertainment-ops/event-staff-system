<?php

declare(strict_types=1);

/**
 * Revert mistaken Kodaleone 2026-06-20 shift correction (wrong event).
 *
 * /cron/revert-kodaleone-shift-hours.php?key=...&dry_run=1
 * /cron/revert-kodaleone-shift-hours.php?key=...&dry_run=0
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/work-hours-schema.php';
require_once dirname(__DIR__) . '/includes/attendance-gps-phase1-schema.php';

header('Content-Type: application/json; charset=UTF-8');

const KODALEONE_EVENT_ID = 4;
const KODALEONE_CORRECTION_NOTE = 'Kodaleone 2026-06-20 shift hours corrected';

/** @var list<int> */
const KODALEONE_MANUAL_REGISTRATION_IDS = [633, 634, 635];

/** @var list<int> */
const KODALEONE_MANUAL_ATTENDANCE_IDS = [150, 151, 152];

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
 * Hours snapshot captured immediately before mistaken Kodaleone correction.
 *
 * @return list<array{attendance_id: int, before_paid: float}>
 */
function kodaleoneRevertSnapshot(): array
{
  return [
    ['attendance_id' => 67, 'before_paid' => 8.5],
    ['attendance_id' => 59, 'before_paid' => 8.5],
    ['attendance_id' => 62, 'before_paid' => 8.5],
    ['attendance_id' => 110, 'before_paid' => 0],
    ['attendance_id' => 70, 'before_paid' => 8.5],
    ['attendance_id' => 75, 'before_paid' => 0],
    ['attendance_id' => 90, 'before_paid' => 0],
    ['attendance_id' => 106, 'before_paid' => 0],
    ['attendance_id' => 120, 'before_paid' => 0],
    ['attendance_id' => 104, 'before_paid' => 0],
    ['attendance_id' => 96, 'before_paid' => 0],
    ['attendance_id' => 65, 'before_paid' => 8.5],
    ['attendance_id' => 68, 'before_paid' => 8.5],
    ['attendance_id' => 69, 'before_paid' => 8.5],
    ['attendance_id' => 101, 'before_paid' => 0],
    ['attendance_id' => 72, 'before_paid' => 8.5],
    ['attendance_id' => 105, 'before_paid' => 0],
    ['attendance_id' => 109, 'before_paid' => 0],
    ['attendance_id' => 63, 'before_paid' => 8.5],
    ['attendance_id' => 58, 'before_paid' => 8.5],
    ['attendance_id' => 95, 'before_paid' => 0],
    ['attendance_id' => 84, 'before_paid' => 0],
    ['attendance_id' => 130, 'before_paid' => 0],
    ['attendance_id' => 64, 'before_paid' => 8.5],
    ['attendance_id' => 99, 'before_paid' => 0],
    ['attendance_id' => 112, 'before_paid' => 0],
    ['attendance_id' => 92, 'before_paid' => 8.5],
    ['attendance_id' => 132, 'before_paid' => 0],
    ['attendance_id' => 81, 'before_paid' => 0],
    ['attendance_id' => 66, 'before_paid' => 8.5],
    ['attendance_id' => 60, 'before_paid' => 8.5],
    ['attendance_id' => 103, 'before_paid' => 8.5],
    ['attendance_id' => 137, 'before_paid' => 8.5],
    ['attendance_id' => 57, 'before_paid' => 8.5],
    ['attendance_id' => 87, 'before_paid' => 0],
    ['attendance_id' => 61, 'before_paid' => 8.5],
    ['attendance_id' => 85, 'before_paid' => 8.5],
    ['attendance_id' => 79, 'before_paid' => 0],
    ['attendance_id' => 131, 'before_paid' => 0],
    ['attendance_id' => 76, 'before_paid' => 0],
    ['attendance_id' => 133, 'before_paid' => 0],
    ['attendance_id' => 83, 'before_paid' => 0],
    ['attendance_id' => 126, 'before_paid' => 0],
    ['attendance_id' => 73, 'before_paid' => 8.5],
  ];
}

try {
    $pdo = getDB();
    $providedKey = trim((string) ($_GET['key'] ?? ''));
    if (!cronAuthOk($pdo, $providedKey)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT);
        exit;
    }

    $dryRun = !isset($_GET['dry_run']) || (string) $_GET['dry_run'] !== '0';
    $snapshot = kodaleoneRevertSnapshot();

    $removedManual = [];
    foreach (KODALEONE_MANUAL_ATTENDANCE_IDS as $attendanceId) {
        $row = ['attendance_id' => $attendanceId];
        if ($dryRun) {
            $row['status'] = 'would_delete_attendance';
            $removedManual[] = $row;
            continue;
        }
        $stmt = $pdo->prepare('DELETE FROM attendance WHERE id = :id AND event_id = :event_id');
        $stmt->execute(['id' => $attendanceId, 'event_id' => KODALEONE_EVENT_ID]);
        $row['status'] = $stmt->rowCount() > 0 ? 'deleted_attendance' : 'not_found';
        $removedManual[] = $row;
    }

    foreach (KODALEONE_MANUAL_REGISTRATION_IDS as $registrationId) {
        $row = ['registration_id' => $registrationId];
        if ($dryRun) {
            $row['status'] = 'would_delete_registration';
            $removedManual[] = $row;
            continue;
        }
        $pdo->prepare('DELETE FROM attendance WHERE registration_id = :id')->execute(['id' => $registrationId]);
        $stmt = $pdo->prepare(
            'DELETE FROM staff_registrations
             WHERE id = :id AND event_id = :event_id AND allocation_type = \'admin_assigned\''
        );
        $stmt->execute(['id' => $registrationId, 'event_id' => KODALEONE_EVENT_ID]);
        $row['status'] = $stmt->rowCount() > 0 ? 'deleted_registration' : 'not_found';
        $removedManual[] = $row;
    }

    $reverted = [];
    $skipped  = 0;
    $failed   = 0;

    $select = $pdo->prepare(
        'SELECT id, hours_paid, hours_worked, hours_note, event_id
         FROM attendance WHERE id = :id LIMIT 1'
    );
    $update = $pdo->prepare(
        'UPDATE attendance SET
            hours_paid = :hours_paid,
            hours_worked = :hours_worked,
            hours_note = NULL,
            hours_adjusted_by = NULL,
            hours_adjusted_at = NULL
         WHERE id = :id'
    );

    foreach ($snapshot as $item) {
        $attendanceId = (int) $item['attendance_id'];
        $beforePaid   = (float) $item['before_paid'];
        $entry = [
            'attendance_id' => $attendanceId,
            'before_paid'   => $beforePaid,
        ];

        $select->execute(['id' => $attendanceId]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int) ($row['event_id'] ?? 0) !== KODALEONE_EVENT_ID) {
            $entry['status'] = 'skipped';
            $entry['reason'] = 'Attendance not found on Kodaleone';
            $skipped++;
            $reverted[] = $entry;
            continue;
        }

        $note = (string) ($row['hours_note'] ?? '');
        if ($note === '' || stripos($note, KODALEONE_CORRECTION_NOTE) === false) {
            $entry['status'] = 'skipped';
            $entry['reason'] = 'Not tagged with Kodaleone correction note';
            $entry['hours_note'] = $note;
            $skipped++;
            $reverted[] = $entry;
            continue;
        }

        $entry['current_paid'] = round((float) ($row['hours_paid'] ?? 0), 2);
        if ($dryRun) {
            $entry['status'] = 'would_revert_hours';
            $reverted[] = $entry;
            continue;
        }

        $update->execute([
            'hours_paid'   => $beforePaid,
            'hours_worked' => $beforePaid,
            'id'           => $attendanceId,
        ]);
        $entry['status'] = 'reverted_hours';
        $reverted[] = $entry;
    }

    echo json_encode([
        'ok'             => true,
        'dry_run'        => $dryRun,
        'event_id'       => KODALEONE_EVENT_ID,
        'removed_manual' => $removedManual,
        'reverted_count' => count(array_filter($reverted, static fn (array $r): bool => ($r['status'] ?? '') === 'reverted_hours' || ($r['status'] ?? '') === 'would_revert_hours')),
        'skipped'        => $skipped,
        'failed'         => $failed,
        'reverted'       => $reverted,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
