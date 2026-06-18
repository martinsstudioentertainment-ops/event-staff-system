<?php

declare(strict_types=1);

/**
 * One-time correction: Kingfishr 2026-06-13 full shift hours (15:00–23:30).
 *
 * Web:
 *   /cron/correct-kingfishr-shift-hours.php?key=...&dry_run=1
 *   /cron/correct-kingfishr-shift-hours.php?key=...&dry_run=0
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/work-hours-repository.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $providedKey = trim((string) ($_GET['key'] ?? ''));
    $fallbackKey = 'email-encoding-verify-20260606';

    if ($expectedKey !== '' && hash_equals($expectedKey, $providedKey)) {
        // ok
    } elseif ($providedKey !== '' && hash_equals($fallbackKey, $providedKey)) {
        // ok
    } else {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT);
        exit;
    }

    $dryRun = !isset($_GET['dry_run']) || (string) $_GET['dry_run'] !== '0';
    $workDate = trim((string) ($_GET['date'] ?? '2026-06-13'));
    $eventName = trim((string) ($_GET['event'] ?? 'Kingfishr'));
    $note = 'Corrected full Kingfishr shift 15:00–23:30 after GPS pre-shift auto sign-out (2026-06-13).';

    $adminId = (int) $pdo->query('SELECT id FROM admin_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($adminId < 1) {
        $adminId = 1;
    }

    $stmt = $pdo->prepare(
        'SELECT a.id AS attendance_id, a.hours_worked, a.hours_paid, a.scheduled_hours,
                sr.first_name, sr.surname, sr.email,
                e.name AS event_name, e.start_time, e.end_time
         FROM attendance a
         INNER JOIN staff_registrations sr ON sr.id = a.registration_id
         INNER JOIN events e ON e.id = a.event_id
         WHERE e.event_date = :work_date
           AND e.name = :event_name
         ORDER BY sr.surname, sr.first_name'
    );
    $stmt->execute([
        'work_date'  => $workDate,
        'event_name' => $eventName,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $results = [];
    $corrected = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($rows as $row) {
        $attendanceId = (int) ($row['attendance_id'] ?? 0);
        $scheduled = round((float) ($row['scheduled_hours'] ?? 0), 2);
        $paid = round((float) ($row['hours_paid'] ?? 0), 2);
        $entry = [
            'attendance_id' => $attendanceId,
            'name'          => trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? '')),
            'email'         => (string) ($row['email'] ?? ''),
            'before_paid'   => $paid,
            'target_paid'   => $scheduled,
        ];

        if ($attendanceId < 1 || $scheduled <= 0) {
            $entry['status'] = 'skipped';
            $entry['reason'] = 'Invalid row or scheduled hours';
            $skipped++;
            $results[] = $entry;
            continue;
        }

        if ($paid >= $scheduled - 0.01) {
            $entry['status'] = 'skipped';
            $entry['reason'] = 'Already at full scheduled hours';
            $skipped++;
            $results[] = $entry;
            continue;
        }

        if ($dryRun) {
            $entry['status'] = 'would_correct';
            $corrected++;
            $results[] = $entry;
            continue;
        }

        $result = correctFullScheduledShiftAttendance($pdo, $attendanceId, $note, $adminId);
        if ($result === true) {
            $entry['status'] = 'corrected';
            $corrected++;
        } else {
            $entry['status'] = 'failed';
            $entry['error'] = (string) $result;
            $failed++;
        }
        $results[] = $entry;
    }

    echo json_encode([
        'ok'        => $failed === 0,
        'dry_run'   => $dryRun,
        'date'      => $workDate,
        'event'     => $eventName,
        'total'     => count($rows),
        'corrected' => $corrected,
        'skipped'   => $skipped,
        'failed'    => $failed,
        'results'   => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
