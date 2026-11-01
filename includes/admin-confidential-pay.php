<?php

declare(strict_types=1);

/**
 * Admin-only confidential staff pay rates (never exposed to staff app / emails).
 */

require_once __DIR__ . '/production-readiness.php';

function ensureAdminConfidentialPaySchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_confidential_pay_rates (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            staff_id INT UNSIGNED NOT NULL,
            event_id INT UNSIGNED NULL DEFAULT NULL,
            staff_hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            contractor_hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 18.00,
            notes VARCHAR(255) NULL DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_admin_conf_pay_staff_event (staff_id, event_id),
            KEY idx_admin_conf_pay_event (event_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $done = true;
}

/**
 * Upsert a confidential rate. event_id null = default for that staff across events.
 */
function saveAdminConfidentialPayRate(
    PDO $pdo,
    int $staffId,
    float $staffHourlyRate,
    float $contractorHourlyRate = 18.0,
    ?int $eventId = null,
    ?string $notes = null
): bool {
    ensureAdminConfidentialPaySchema($pdo);
    if ($staffId < 1) {
        return false;
    }

    $staffHourlyRate = round(max(0, $staffHourlyRate), 2);
    $contractorHourlyRate = round(max(0, $contractorHourlyRate), 2);

    // MySQL UNIQUE (staff_id, event_id) treats NULLs specially — use 0 for "global" sentinel in unique key.
    $eventKey = $eventId !== null && $eventId > 0 ? $eventId : 0;

    $stmt = $pdo->prepare(
        'INSERT INTO admin_confidential_pay_rates
            (staff_id, event_id, staff_hourly_rate, contractor_hourly_rate, notes)
         VALUES (:sid, :eid, :srate, :crate, :notes)
         ON DUPLICATE KEY UPDATE
            staff_hourly_rate = VALUES(staff_hourly_rate),
            contractor_hourly_rate = VALUES(contractor_hourly_rate),
            notes = VALUES(notes)'
    );

    return $stmt->execute([
        'sid' => $staffId,
        'eid' => $eventKey,
        'srate' => $staffHourlyRate,
        'crate' => $contractorHourlyRate,
        'notes' => $notes,
    ]);
}

/**
 * @return array{staff_hourly_rate: float, contractor_hourly_rate: float}|null
 */
function getAdminConfidentialPayRate(PDO $pdo, int $staffId, int $eventId = 0): ?array
{
    ensureAdminConfidentialPaySchema($pdo);
    if ($staffId < 1) {
        return null;
    }

    // Prefer event-specific, then global (event_id = 0).
    $stmt = $pdo->prepare(
        'SELECT staff_hourly_rate, contractor_hourly_rate
         FROM admin_confidential_pay_rates
         WHERE staff_id = :sid AND event_id IN (:eid, 0)
         ORDER BY (event_id = :eid2) DESC
         LIMIT 1'
    );
    $stmt->execute([
        'sid' => $staffId,
        'eid' => max(0, $eventId),
        'eid2' => max(0, $eventId),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'staff_hourly_rate' => round((float) $row['staff_hourly_rate'], 2),
        'contractor_hourly_rate' => round((float) $row['contractor_hourly_rate'], 2),
    ];
}

/**
 * Planned shift hours from event start/end (overnight-safe).
 *
 * @param array<string, mixed> $event
 */
function adminConfidentialPlannedHours(array $event): float
{
    $start = substr((string) ($event['start_time'] ?? ''), 0, 8);
    $end = substr((string) ($event['end_time'] ?? ''), 0, 8);
    if ($start === '' || $end === '') {
        return 0.0;
    }

    try {
        $s = new DateTimeImmutable('2000-01-01 ' . $start);
        $e = new DateTimeImmutable('2000-01-01 ' . $end);
        if ($e <= $s) {
            $e = $e->modify('+1 day');
        }

        return round(($e->getTimestamp() - $s->getTimestamp()) / 3600, 2);
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * Build admin profit rows for an event (approved roster).
 *
 * @return list<array<string, mixed>>
 */
function buildAdminProfitSheetRows(PDO $pdo, int $eventId): array
{
    ensureAdminConfidentialPaySchema($pdo);
    if ($eventId < 1) {
        return [];
    }

    $eventStmt = $pdo->prepare('SELECT * FROM events WHERE id = :id LIMIT 1');
    $eventStmt->execute(['id' => $eventId]);
    $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) {
        return [];
    }

    $planned = adminConfidentialPlannedHours($event);

    $stmt = $pdo->prepare(
        "SELECT sr.id AS registration_id, sr.staff_id, sr.first_name, sr.surname, sr.mobile, sr.email,
                sr.staff_role, sr.status,
                a.id AS attendance_id, a.checked_in_at, a.checked_out_at,
                a.hours_worked, a.hours_paid, a.hours_note
         FROM staff_registrations sr
         LEFT JOIN attendance a ON a.registration_id = sr.id AND a.event_id = sr.event_id
         WHERE sr.event_id = :eid AND sr.status = 'approved'
         ORDER BY sr.surname, sr.first_name"
    );
    $stmt->execute(['eid' => $eventId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $row) {
        $staffId = (int) ($row['staff_id'] ?? 0);
        $rates = getAdminConfidentialPayRate($pdo, $staffId, $eventId);
        $staffRate = $rates['staff_hourly_rate'] ?? 0.0;
        $contractorRate = $rates['contractor_hourly_rate'] ?? 18.0;

        $actualPaid = isset($row['hours_paid']) && $row['hours_paid'] !== null
            ? (float) $row['hours_paid']
            : null;
        $actualWorked = isset($row['hours_worked']) && $row['hours_worked'] !== null
            ? (float) $row['hours_worked']
            : null;
        $hours = $actualPaid !== null && $actualPaid > 0
            ? $actualPaid
            : ($actualWorked !== null && $actualWorked > 0 ? $actualWorked : $planned);
        $hoursSource = ($actualPaid !== null && $actualPaid > 0) || ($actualWorked !== null && $actualWorked > 0)
            ? 'actual'
            : 'planned';

        $staffPay = round($hours * $staffRate, 2);
        $contractorPaid = round($hours * $contractorRate, 2);
        $profit = round($contractorPaid - $staffPay, 2);

        $out[] = [
            'registration_id' => (int) ($row['registration_id'] ?? 0),
            'staff_id' => $staffId,
            'name' => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? '')),
            'mobile' => (string) ($row['mobile'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'role' => (string) ($row['staff_role'] ?? ''),
            'event_id' => $eventId,
            'event_name' => (string) ($event['name'] ?? ''),
            'event_date' => substr((string) ($event['event_date'] ?? ''), 0, 10),
            'checked_in_at' => $row['checked_in_at'] ?? null,
            'checked_out_at' => $row['checked_out_at'] ?? null,
            'hours' => $hours,
            'hours_source' => $hoursSource,
            'planned_hours' => $planned,
            'staff_hourly_rate' => $staffRate,
            'contractor_hourly_rate' => $contractorRate,
            'staff_pay' => $staffPay,
            'contractor_paid' => $contractorPaid,
            'profit' => $profit,
            'has_rate' => $rates !== null && $staffRate > 0,
        ];
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{hours: float, staff_pay: float, contractor_paid: float, profit: float}
 */
function adminProfitSheetTotals(array $rows): array
{
    $hours = $staff = $contractor = $profit = 0.0;
    foreach ($rows as $row) {
        $hours += (float) ($row['hours'] ?? 0);
        $staff += (float) ($row['staff_pay'] ?? 0);
        $contractor += (float) ($row['contractor_paid'] ?? 0);
        $profit += (float) ($row['profit'] ?? 0);
    }

    return [
        'hours' => round($hours, 2),
        'staff_pay' => round($staff, 2),
        'contractor_paid' => round($contractor, 2),
        'profit' => round($profit, 2),
    ];
}
