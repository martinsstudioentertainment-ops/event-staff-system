<?php

declare(strict_types=1);

require_once __DIR__ . '/../production-readiness.php';

function wf_availability_table_exists(PDO $pdo): bool
{
    return tableExists($pdo, 'staff_availability');
}

function wf_ensure_availability_schema(PDO $pdo): bool
{
    if (wf_availability_table_exists($pdo)) {
        return true;
    }

    $path = dirname(__DIR__, 2) . '/database/migrate-phase-workforce-availability.sql';
    if (!is_file($path)) {
        return false;
    }

    try {
        $sql = (string) file_get_contents($path);
        $pdo->exec($sql);

        return wf_availability_table_exists($pdo);
    } catch (Throwable $e) {
        error_log('[Workforce] availability schema: ' . $e->getMessage());

        return false;
    }
}

/** @return list<array<string, mixed>> */
function wf_get_availability_range(PDO $pdo, string $dateFrom, string $dateTo, ?int $staffId = null): array
{
    if (!wf_availability_table_exists($pdo)) {
        return [];
    }

    $where  = 'a.avail_date >= :from_date AND a.avail_date <= :to_date';
    $params = ['from_date' => $dateFrom, 'to_date' => $dateTo];

    if ($staffId !== null && $staffId > 0) {
        $where           .= ' AND a.staff_id = :staff_id';
        $params['staff_id'] = $staffId;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT a.*, s.first_name, s.surname, s.email
             FROM staff_availability a
             INNER JOIN staff s ON s.id = a.staff_id
             WHERE {$where}
             ORDER BY a.avail_date ASC, s.surname ASC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array<string, mixed>> */
function wf_get_pending_availability_requests(PDO $pdo, int $limit = 50): array
{
    if (!wf_availability_table_exists($pdo)) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT a.*, s.first_name, s.surname, s.email
             FROM staff_availability a
             INNER JOIN staff s ON s.id = a.staff_id
             WHERE a.status IN ('leave_requested', 'holiday_requested') AND a.admin_approved = 0
             ORDER BY a.avail_date ASC
             LIMIT " . max(1, min($limit, 100))
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function wf_set_staff_availability(
    PDO $pdo,
    int $staffId,
    string $date,
    string $status,
    string $notes = '',
    bool $adminApproved = false,
    ?int $adminId = null
): bool
{
    if (!wf_availability_table_exists($pdo) || $staffId < 1 || $date === '') {
        return false;
    }

    $allowed = ['available', 'unavailable', 'preferred', 'leave_requested', 'holiday_requested', 'leave_approved', 'holiday_approved'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO staff_availability (staff_id, avail_date, status, notes, admin_approved, reviewed_by_admin_id, reviewed_at)
             VALUES (:staff_id, :avail_date, :status, :notes, :admin_approved, :reviewed_by, :reviewed_at)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                notes = VALUES(notes),
                admin_approved = VALUES(admin_approved),
                reviewed_by_admin_id = VALUES(reviewed_by_admin_id),
                reviewed_at = VALUES(reviewed_at),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'staff_id'       => $staffId,
            'avail_date'     => $date,
            'status'         => $status,
            'notes'          => $notes !== '' ? $notes : null,
            'admin_approved' => $adminApproved ? 1 : 0,
            'reviewed_by'    => $adminId,
            'reviewed_at'    => $adminApproved ? date('Y-m-d H:i:s') : null,
        ]);

        return true;
    } catch (Throwable $e) {
        error_log('[Workforce] set availability: ' . $e->getMessage());

        return false;
    }
}

function wf_review_availability_request(PDO $pdo, int $id, bool $approve, ?int $adminId = null): bool
{
    if (!wf_availability_table_exists($pdo) || $id < 1) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM staff_availability WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        $current = (string) ($row['status'] ?? '');
        if (!in_array($current, ['leave_requested', 'holiday_requested'], true)) {
            return false;
        }

        $newStatus = $approve
            ? ($current === 'holiday_requested' ? 'holiday_approved' : 'leave_approved')
            : 'unavailable';

        $upd = $pdo->prepare(
            'UPDATE staff_availability
             SET status = :status, admin_approved = :approved, reviewed_by_admin_id = :admin_id, reviewed_at = NOW()
             WHERE id = :id'
        );

        return $upd->execute([
            'status'   => $newStatus,
            'approved' => $approve ? 1 : 0,
            'admin_id' => $adminId,
            'id'       => $id,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}
