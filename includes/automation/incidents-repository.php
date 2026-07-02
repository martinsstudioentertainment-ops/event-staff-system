<?php

declare(strict_types=1);

require_once __DIR__ . '/automation-schema.php';

/** @return list<string> */
function incident_types(): array
{
    return ['attendance', 'venue', 'conduct', 'gps', 'safety', 'client_complaint', 'other'];
}

/** @return array{open: int, investigating: int, resolved: int, closed: int} */
function incidents_summary(PDO $pdo): array
{
    $summary = ['open' => 0, 'investigating' => 0, 'resolved' => 0, 'closed' => 0];
    if (!tableExists($pdo, 'staff_incidents')) {
        return $summary;
    }

    try {
        $rows = $pdo->query(
            'SELECT incident_status, COUNT(*) AS cnt FROM staff_incidents GROUP BY incident_status'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $summary[(string) ($row['incident_status'] ?? '')] = (int) ($row['cnt'] ?? 0);
        }
    } catch (Throwable $e) {
        // optional
    }

    return $summary;
}

/** @return list<array<string, mixed>> */
function incidents_list(PDO $pdo, ?string $status = null, ?string $type = null, int $limit = 100): array
{
    if (!tableExists($pdo, 'staff_incidents')) {
        return [];
    }

    $where  = ['1=1'];
    $params = [];
    if ($status !== null && $status !== '') {
        $where[]           = 'i.incident_status = :status';
        $params['status']  = $status;
    }
    if ($type !== null && $type !== '') {
        $where[]         = 'i.incident_type = :type';
        $params['type']  = $type;
    }

    $sql = 'SELECT i.*, s.first_name, s.surname, s.email, e.name AS event_name
            FROM staff_incidents i
            LEFT JOIN staff s ON s.id = i.staff_id
            LEFT JOIN events e ON e.id = i.event_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY i.reported_at DESC
            LIMIT ' . max(1, min($limit, 200));

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function incidents_create(
    PDO $pdo,
    string $type,
    string $title,
    string $description = '',
    ?int $staffId = null,
    ?int $eventId = null,
    ?int $adminId = null,
    string $evidence = '',
    string $actionsTaken = '',
    string $riskLevel = 'medium'
): bool {
    if (!tableExists($pdo, 'staff_incidents') || $title === '') {
        return false;
    }
    if (!in_array($type, incident_types(), true)) {
        $type = 'other';
    }

    $riskAllowed = ['low', 'medium', 'high', 'critical'];
    if (!in_array($riskLevel, $riskAllowed, true)) {
        $riskLevel = 'medium';
    }

    $hasExtended = tableExists($pdo, 'staff_incidents')
        && incidents_column_exists($pdo, 'evidence_text');

    try {
        if ($hasExtended) {
            return $pdo->prepare(
                'INSERT INTO staff_incidents (staff_id, event_id, incident_type, title, description, evidence_text, actions_taken, risk_level, reported_by_admin_id)
                 VALUES (:staff, :event, :type, :title, :desc, :evidence, :actions, :risk, :admin)'
            )->execute([
                'staff'    => $staffId,
                'event'    => $eventId,
                'type'     => $type,
                'title'    => $title,
                'desc'     => $description !== '' ? $description : null,
                'evidence' => $evidence !== '' ? $evidence : null,
                'actions'  => $actionsTaken !== '' ? $actionsTaken : null,
                'risk'     => $riskLevel,
                'admin'    => $adminId,
            ]);
        }

        return $pdo->prepare(
            'INSERT INTO staff_incidents (staff_id, event_id, incident_type, title, description, reported_by_admin_id)
             VALUES (:staff, :event, :type, :title, :desc, :admin)'
        )->execute([
            'staff'  => $staffId,
            'event'  => $eventId,
            'type'   => $type,
            'title'  => $title,
            'desc'   => $description !== '' ? $description : null,
            'admin'  => $adminId,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function incidents_column_exists(PDO $pdo, string $column): bool
{
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM staff_incidents')->fetchAll(PDO::FETCH_COLUMN);

        return in_array($column, $cols, true);
    } catch (Throwable $e) {
        return false;
    }
}

function incidents_update_details(PDO $pdo, int $id, string $evidence = '', string $actionsTaken = '', string $riskLevel = ''): bool
{
    if (!tableExists($pdo, 'staff_incidents') || $id < 1 || !incidents_column_exists($pdo, 'evidence_text')) {
        return false;
    }

    $riskAllowed = ['low', 'medium', 'high', 'critical'];
    if ($riskLevel !== '' && !in_array($riskLevel, $riskAllowed, true)) {
        $riskLevel = 'medium';
    }

    try {
        return $pdo->prepare(
            'UPDATE staff_incidents SET evidence_text = :evidence, actions_taken = :actions,
             risk_level = COALESCE(NULLIF(:risk, \'\'), risk_level) WHERE id = :id'
        )->execute([
            'evidence' => $evidence !== '' ? $evidence : null,
            'actions'  => $actionsTaken !== '' ? $actionsTaken : null,
            'risk'     => $riskLevel,
            'id'       => $id,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function incidents_update_status(PDO $pdo, int $id, string $status, string $resolution = ''): bool
{
    if (!tableExists($pdo, 'staff_incidents') || $id < 1) {
        return false;
    }

    $allowed = ['open', 'investigating', 'resolved', 'closed'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }

    try {
        return $pdo->prepare(
            'UPDATE staff_incidents
             SET incident_status = :status,
                 resolution_notes = :notes,
                 resolved_at = CASE WHEN :status IN (\'resolved\',\'closed\') THEN NOW() ELSE resolved_at END
             WHERE id = :id'
        )->execute([
            'status' => $status,
            'notes'  => $resolution !== '' ? $resolution : null,
            'id'     => $id,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function incidents_seed_from_attendance(PDO $pdo): int
{
    if (!tableExists($pdo, 'staff_incidents')) {
        return 0;
    }

    try {
        $rows = $pdo->query(
            "SELECT a.id, sr.staff_id, a.event_id, a.attendance_status
             FROM attendance a
             INNER JOIN staff_registrations sr ON sr.id = a.registration_id
             WHERE a.attendance_status IN ('gps_failed', 'manual_review', 'no_show')
               AND a.checked_in_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             LIMIT 100"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return 0;
    }

    $count = 0;
    foreach ($rows as $row) {
        $type = match ((string) ($row['attendance_status'] ?? '')) {
            'gps_failed', 'manual_review' => 'gps',
            'no_show' => 'attendance',
            default => 'attendance',
        };
        $title = 'Auto: ' . str_replace('_', ' ', (string) ($row['attendance_status'] ?? 'incident'));
        if (incidents_create(
            $pdo,
            $type,
            $title,
            'Generated from attendance record #' . (int) ($row['id'] ?? 0),
            (int) ($row['staff_id'] ?? 0) ?: null,
            (int) ($row['event_id'] ?? 0) ?: null
        )) {
            $count++;
        }
    }

    return $count;
}
