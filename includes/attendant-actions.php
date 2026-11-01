<?php

declare(strict_types=1);

/**
 * Attendant helpers — clock-out + sent-off for back-office hours sheet.
 */

require_once __DIR__ . '/attendance-repository.php';
require_once __DIR__ . '/attendance-gps-signout.php';
require_once __DIR__ . '/work-hours-repository.php';

/**
 * Admin/attendant clock-out at a specific time (default now).
 *
 * @return true|string
 */
function attendantClockOut(
    PDO $pdo,
    int $attendanceId,
    ?DateTimeInterface $at = null,
    string $reason = 'attendant_clock_out'
): bool|string {
    ensureAttendanceGpsSignoutSchema($pdo);
    ensureWorkHoursSchema($pdo);

    $reason = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($reason))) ?? '';
    if ($reason === '') {
        $reason = 'attendant_clock_out';
    }

    $stmt = $pdo->prepare('SELECT * FROM attendance WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $attendanceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 'Attendance record not found.';
    }

    if (!empty($row['checked_out_at'])) {
        return 'Already clocked out.';
    }

    if ((int) ($row['id'] ?? 0) < 1) {
        return 'Attendance record not found.';
    }

    $checkedOutAt = $at instanceof DateTime
        ? clone $at
        : ($at instanceof DateTimeInterface
            ? new DateTime($at->format('Y-m-d H:i:s'))
            : new DateTime('now'));

    $update = $pdo->prepare(
        'UPDATE attendance SET
            checked_out_at = :checked_out_at,
            signout_reason = COALESCE(NULLIF(signout_reason, \'\'), :reason)
         WHERE id = :id
           AND checked_out_at IS NULL'
    );
    $update->execute([
        'checked_out_at' => $checkedOutAt->format('Y-m-d H:i:s'),
        'reason'         => $reason,
        'id'             => $attendanceId,
    ]);

    if ($update->rowCount() < 1) {
        return 'Could not clock out.';
    }

    finalizeWorkHoursOnSignOut($pdo, $attendanceId, $checkedOutAt);

    return true;
}

/**
 * Sent off now — records work_end_at / payable hours for back-office sheet.
 *
 * @return true|string
 */
function attendantSentOffNow(PDO $pdo, int $attendanceId, int $adminUserId, string $note = ''): bool|string
{
    ensureWorkHoursSchema($pdo);

    $stmt = $pdo->prepare(
        'SELECT a.*, e.event_date, e.start_time, e.end_time
         FROM attendance a
         INNER JOIN events e ON e.id = a.event_id
         WHERE a.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $attendanceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 'Attendance record not found.';
    }

    $date       = (string) ($row['event_date'] ?? '');
    $startTime  = (string) ($row['start_time'] ?? '09:00:00');
    $eventStart = parseEventDateTime($date, $startTime) ?? new DateTime($date . ' 09:00:00');
    $now        = new DateTime('now', $eventStart->getTimezone());

    $workStartRaw = !empty($row['activated_at']) ? (string) $row['activated_at'] : (string) ($row['checked_in_at'] ?? '');
    if ($workStartRaw === '') {
        return 'Staff has not signed in yet.';
    }

    $workStart = new DateTime($workStartRaw, $eventStart->getTimezone());
    if ($workStart < $eventStart) {
        $workStart = clone $eventStart;
    }

    $seconds = max(0, $now->getTimestamp() - $workStart->getTimestamp());
    $hoursPaid = round($seconds / 3600, 2);
    if ($hoursPaid < 0.25) {
        $hoursPaid = 0.25;
    }

    $note = trim($note);
    if ($note === '') {
        $note = 'Sent off by attendant at ' . $now->format('H:i');
    } else {
        $note = 'Sent off at ' . $now->format('H:i') . ' — ' . $note;
    }

    $result = recordStaffSentHome($pdo, $attendanceId, $hoursPaid, $note, $adminUserId);
    if ($result !== true) {
        return $result;
    }

    // Also stamp clock-out time if empty so sheet shows they left.
    if (empty($row['checked_out_at'])) {
        attendantClockOut($pdo, $attendanceId, $now);
    }

    return true;
}

function ensureAttendanceGateStartTime(PDO $pdo): bool
{
    static $ok = null;

    if ($ok !== null) {
        return $ok;
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('gate_start_time', $cols, true)) {
            $pdo->exec('ALTER TABLE attendance ADD COLUMN gate_start_time CHAR(5) NULL DEFAULT NULL');
        }
        $ok = true;
    } catch (Throwable $e) {
        error_log('[EventStaff] gate_start_time: ' . $e->getMessage());
        $ok = false;
    }

    return $ok;
}

function normalizeGateStartTime(string $value): ?string
{
    $value = trim($value);

    return preg_match('/^(?:[01]\d|2[0-3]):00$/', $value) === 1 ? $value : null;
}

function saveAttendanceGateStartTime(PDO $pdo, int $registrationId, string $startTime): void
{
    if ($registrationId < 1 || normalizeGateStartTime($startTime) === null || !ensureAttendanceGateStartTime($pdo)) {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE attendance
         SET gate_start_time = :start
         WHERE registration_id = :rid
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([
        'start' => $startTime,
        'rid'   => $registrationId,
    ]);

    try {
        $eventStmt = $pdo->prepare('SELECT event_id FROM staff_registrations WHERE id = :id LIMIT 1');
        $eventStmt->execute(['id' => $registrationId]);
        $eventId = (int) $eventStmt->fetchColumn();
        if ($eventId > 0) {
            require_once __DIR__ . '/google-sheets-queue.php';
            require_once __DIR__ . '/google-sheets-auto-worker.php';
            googleSheetsEnqueueEventRebuild($pdo, $eventId, 'gate_start', $registrationId);
            googleSheetsTriggerWorkerAsync($pdo);
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] gate start sheet sync: ' . $e->getMessage());
    }
}

function attendanceGateStartTimeForRegistration(PDO $pdo, int $registrationId): string
{
    if ($registrationId < 1 || !ensureAttendanceGateStartTime($pdo)) {
        return '';
    }

    $stmt = $pdo->prepare(
        'SELECT gate_start_time
         FROM attendance
         WHERE registration_id = :rid
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute(['rid' => $registrationId]);

    return trim((string) ($stmt->fetchColumn() ?: ''));
}
