<?php

declare(strict_types=1);

require_once __DIR__ . '/automation-schema.php';

/** @return list<array{key: string, label: string}> */
function training_course_catalog(): array
{
    return [
        ['key' => 'induction', 'label' => 'Induction'],
        ['key' => 'manual_handling', 'label' => 'Manual Handling'],
        ['key' => 'customer_service', 'label' => 'Customer Service'],
        ['key' => 'safety', 'label' => 'Safety Training'],
        ['key' => 'venue', 'label' => 'Venue Training'],
        ['key' => 'custom', 'label' => 'Custom Course'],
    ];
}

/** @return array{completed: int, pending: int, expired: int, upcoming: int, scheduled: int} */
function training_summary(PDO $pdo): array
{
    $summary = ['completed' => 0, 'pending' => 0, 'expired' => 0, 'upcoming' => 0, 'scheduled' => 0];
    if (!tableExists($pdo, 'staff_training_records')) {
        return $summary;
    }

    try {
        $rows = $pdo->query(
            'SELECT record_status, COUNT(*) AS cnt FROM staff_training_records GROUP BY record_status'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $key = (string) ($row['record_status'] ?? '');
            if (isset($summary[$key])) {
                $summary[$key] = (int) ($row['cnt'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        // optional
    }

    return $summary;
}

/** @return list<array<string, mixed>> */
function training_list_records(PDO $pdo, ?string $status = null, int $limit = 100): array
{
    if (!tableExists($pdo, 'staff_training_records')) {
        return [];
    }

    $where  = '1=1';
    $params = [];
    if ($status !== null && $status !== '') {
        $where            = 't.record_status = :status';
        $params['status'] = $status;
    }

    $sql = "SELECT t.*, s.first_name, s.surname, s.email
            FROM staff_training_records t
            INNER JOIN staff s ON s.id = t.staff_id
            WHERE {$where}
            ORDER BY t.expiry_date ASC, t.scheduled_date ASC, t.id DESC
            LIMIT " . max(1, min($limit, 200));

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function training_save_record(
    PDO $pdo,
    int $staffId,
    string $courseKey,
    string $courseName,
    string $status,
    ?string $completedAt = null,
    ?string $expiryDate = null,
    ?string $scheduledDate = null,
    string $notes = ''
): bool {
    if (!tableExists($pdo, 'staff_training_records') || $staffId < 1) {
        return false;
    }

    $courseName = trim($courseName);
    if ($courseName === '') {
        foreach (training_course_catalog() as $c) {
            if (($c['key'] ?? '') === $courseKey) {
                $courseName = (string) ($c['label'] ?? $courseKey);
                break;
            }
        }
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO staff_training_records
             (staff_id, course_key, course_name, record_status, completed_at, expiry_date, scheduled_date, notes)
             VALUES (:sid, :key, :name, :status, :completed, :expiry, :scheduled, :notes)'
        );

        return $stmt->execute([
            'sid'       => $staffId,
            'key'       => $courseKey,
            'name'      => $courseName,
            'status'    => $status,
            'completed' => $completedAt,
            'expiry'    => $expiryDate,
            'scheduled' => $scheduledDate,
            'notes'     => $notes !== '' ? $notes : null,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function training_refresh_expired(PDO $pdo): int
{
    if (!tableExists($pdo, 'staff_training_records')) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare(
            "UPDATE staff_training_records
             SET record_status = 'expired'
             WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND record_status = 'completed'"
        );
        $stmt->execute();

        return $stmt->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/** @return array{expiring: list<array>, due: list<array>, missing: list<array>} */
function training_alerts(PDO $pdo, int $limit = 30): array
{
    $alerts = ['expiring' => [], 'due' => [], 'missing' => []];
    if (!tableExists($pdo, 'staff_training_records')) {
        return $alerts;
    }

    training_refresh_expired($pdo);

    try {
        $alerts['expiring'] = $pdo->query(
            "SELECT t.*, s.first_name, s.surname FROM staff_training_records t
             INNER JOIN staff s ON s.id = t.staff_id
             WHERE t.expiry_date IS NOT NULL AND t.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
               AND t.record_status IN ('completed','expired')
             ORDER BY t.expiry_date ASC LIMIT " . max(1, min($limit, 50))
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $alerts['due'] = $pdo->query(
            "SELECT t.*, s.first_name, s.surname FROM staff_training_records t
             INNER JOIN staff s ON s.id = t.staff_id
             WHERE t.record_status IN ('pending','upcoming','scheduled')
               AND (t.scheduled_date IS NULL OR t.scheduled_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY))
             ORDER BY t.scheduled_date ASC LIMIT " . max(1, min($limit, 50))
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $alerts['missing'] = $pdo->query(
            "SELECT s.id AS staff_id, s.first_name, s.surname, s.email
             FROM staff s
             LEFT JOIN staff_training_records t ON t.staff_id = s.id AND t.course_key = 'induction' AND t.record_status = 'completed'
             WHERE s.is_active = 1 AND t.id IS NULL
             ORDER BY s.surname ASC LIMIT " . max(1, min($limit, 50))
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        // optional
    }

    return $alerts;
}
