<?php
require_once __DIR__ . '/registration-forms.php';
require_once __DIR__ . '/staff-labels.php';

/**
 */

require_once __DIR__ . '/../config.php';

function getDashboardStats(PDO $pdo): array
{
    $todayCheckins = 0;
    try {
        $todayCheckins = (int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE DATE(checked_in_at) = CURDATE()")->fetchColumn();
    } catch (PDOException $e) {
        $todayCheckins = 0;
    }

    return [
        'total_staff'    => (int) $pdo->query('SELECT COUNT(*) FROM staff_registrations')->fetchColumn(),
        'pending'        => (int) $pdo->query("SELECT COUNT(*) FROM staff_registrations WHERE status = 'pending'")->fetchColumn(),
        'approved'       => (int) $pdo->query("SELECT COUNT(*) FROM staff_registrations WHERE status = 'approved'")->fetchColumn(),
        'rejected'       => (int) $pdo->query("SELECT COUNT(*) FROM staff_registrations WHERE status = 'rejected'")->fetchColumn(),
        'events'         => (int) $pdo->query('SELECT COUNT(*) FROM events WHERE is_active = 1')->fetchColumn(),
        'today_checkins' => $todayCheckins,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function getRecentPendingRegistrations(PDO $pdo, int $limit = 5): array
{
    $sql = "SELECT sr.*, e.name AS event_name, e.event_date
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE sr.status = 'pending'
            ORDER BY sr.created_at DESC
            LIMIT " . max(1, min($limit, 20));

    return $pdo->query($sql)->fetchAll();
}

/**
 * @return array<int, array<string, mixed>>
 */
function getUpcomingEventsSummary(PDO $pdo, int $limit = 5): array
{
    $sql = "SELECT e.id, e.name, e.event_date,
                   COUNT(sr.id) AS registration_count,
                   SUM(CASE WHEN sr.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                   SUM(CASE WHEN sr.status = 'pending' THEN 1 ELSE 0 END) AS pending_count
            FROM events e
            LEFT JOIN staff_registrations sr ON sr.event_id = e.id
            WHERE e.is_active = 1 AND e.event_date >= CURDATE()
            GROUP BY e.id
            ORDER BY e.event_date ASC, e.name ASC
            LIMIT " . max(1, min($limit, 20));

    return $pdo->query($sql)->fetchAll();
}

/**
 * @return array<string, mixed>
 */
function getStaffFiltersFromRequest(): array
{
    return [
        'q'        => trim((string) ($_GET['q'] ?? '')),
        'status'   => trim((string) ($_GET['status'] ?? '')),
        'role'     => trim((string) ($_GET['role'] ?? '')),
        'event_id' => (int) ($_GET['event_id'] ?? 0),
    ];
}

/**
 * @param array<string, mixed> $filters
 */
function buildStaffWhereClause(array $filters): array
{
    $where  = ['1=1'];
    $params = [];

    if ($filters['q'] !== '') {
        $where[] = '(sr.surname LIKE :q OR sr.first_name LIKE :q OR sr.email LIKE :q OR sr.mobile LIKE :q)';
        $params['q'] = '%' . $filters['q'] . '%';
    }

    if (in_array($filters['status'], ['pending', 'approved', 'rejected'], true)) {
        $where[] = 'sr.status = :status';
        $params['status'] = $filters['status'];
    }

    if (in_array(normalizeStaffRole($filters['role']), ['dsp', 'static', 'both', 'steward'], true)) {
        $where[] = 'sr.staff_role = :role';
        $params['role'] = normalizeStaffRole($filters['role']);
    }

    if ($filters['event_id'] > 0) {
        $where[] = 'sr.event_id = :event_id';
        $params['event_id'] = $filters['event_id'];
    }

    return [implode(' AND ', $where), $params];
}

/**
 * @param array<string, mixed> $filters
 * @return array<int, array<string, mixed>>
 */
function getStaffRegistrations(PDO $pdo, array $filters): array
{
    [$where, $params] = buildStaffWhereClause($filters);

    $sql = "SELECT sr.*, e.name AS event_name, e.event_date
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE {$where}
            ORDER BY sr.created_at DESC
            LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * @return array<int, array<string, mixed>>
 */
function getEventsForFilter(PDO $pdo): array
{
    return $pdo->query('SELECT id, name, event_date FROM events WHERE is_active = 1 ORDER BY event_date ASC')->fetchAll();
}

function updateStaffStatus(PDO $pdo, int $id, string $status): bool
{
    if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
        return false;
    }

    $stmt = $pdo->prepare('UPDATE staff_registrations SET status = :status WHERE id = :id');
    $stmt->execute(['status' => $status, 'id' => $id]);

    return $stmt->rowCount() > 0;
}

/**
 * @param array<int, int> $ids
 * @return array{updated: int, skipped: int}
 */
function bulkUpdateStaffStatus(PDO $pdo, array $ids, string $status): array
{
    if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
        return ['updated' => 0, 'skipped' => count($ids)];
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return ['updated' => 0, 'skipped' => 0];
    }

    $updated = 0;
    foreach ($ids as $id) {
        if (updateStaffStatus($pdo, $id, $status)) {
            $updated++;
        }
    }

    return ['updated' => $updated, 'skipped' => count($ids) - $updated];
}

/**
 * @return array<int, array<string, mixed>>
 */
function getApprovedStaffForEvent(PDO $pdo, int $eventId): array
{
    if ($eventId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT sr.*
         FROM staff_registrations sr
         WHERE sr.event_id = :event_id AND sr.status = 'approved'
         ORDER BY sr.surname ASC, sr.first_name ASC"
    );
    $stmt->execute(['event_id' => $eventId]);

    return $stmt->fetchAll();
}

/**
 * @param array<string, mixed> $filters
 * @return array<int, array<string, mixed>>
 */
function getExportRows(PDO $pdo, array $filters): array
{
    [$where, $params] = buildStaffWhereClause($filters);

    $sql = "SELECT sr.*, e.name AS event_name, e.event_date
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE {$where}
            ORDER BY sr.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * @param array<int, int> $ids
 */
function markRowsExported(PDO $pdo, array $ids): void
{
    if ($ids === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE staff_registrations SET exported_at = NOW() WHERE id IN ({$placeholders})");
    $stmt->execute(array_values($ids));
}

function formatRoleLabel(string $role): string
{
    return formatStaffRoleLabel($role);
}

function formatStatusLabel(string $status): string
{
    return ucfirst($status);
}

function formatEventLabel(array $row): string
{
    $date = $row['event_date'] ?? '';
    if ($date !== '') {
        $date = date('d.m.Y', strtotime((string) $date));
    }

    return trim(($row['event_name'] ?? '') . ($date ? ' — ' . $date : ''));
}

/**
 * @return array<string, mixed>|null
 */
function getStaffRegistrationById(PDO $pdo, int $id): ?array
{
    $sql = 'SELECT sr.*, e.name AS event_name, e.main_security_company, e.event_date, e.location AS event_location,
                   e.reporting_point, e.venue_eircode,
                   e.start_time AS event_start_time, e.end_time AS event_end_time,
                   e.venue_lat, e.venue_lng, e.signin_radius_m
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE sr.id = :id
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * All registrations for the same email (multi-event staff).
 * @return array<int, array<string, mixed>>
 */
function getStaffRegistrationsByEmail(PDO $pdo, string $email): array
{
    $sql = 'SELECT sr.*, e.name AS event_name, e.event_date
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE sr.email = :email
            ORDER BY e.event_date ASC, sr.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['email' => $email]);

    return $stmt->fetchAll();
}

/**
 * Latest registration row for an email (for returning-user form prefill).
 * @return array<string, mixed>|null
 */
function getLatestRegistrationByEmail(PDO $pdo, string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT sr.*
         FROM staff_registrations sr
         WHERE LOWER(sr.email) = :email
         ORDER BY sr.created_at DESC
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * @return int[]
 */
function getRegisteredEventIdsByEmail(PDO $pdo, string $email): array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT DISTINCT sr.event_id
         FROM staff_registrations sr
         WHERE LOWER(sr.email) = :email
         ORDER BY sr.event_id ASC'
    );
    $stmt->execute(['email' => $email]);

    return array_map('intval', array_column($stmt->fetchAll(), 'event_id'));
}

/**
 * @return array<int, array<string, mixed>>
 */
function getRegisteredEventsSummaryByEmail(PDO $pdo, string $email): array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT sr.event_id, e.name AS event_name, e.event_date, sr.status
         FROM staff_registrations sr
         INNER JOIN events e ON e.id = sr.event_id
         WHERE LOWER(sr.email) = :email
         ORDER BY e.event_date ASC, e.name ASC'
    );
    $stmt->execute(['email' => $email]);

    return $stmt->fetchAll();
}

function updateAdminPassword(PDO $pdo, int $adminId, string $currentPassword, string $newPassword): bool|string
{
    if (strlen($newPassword) < 8) {
        return 'New password must be at least 8 characters.';
    }

    $stmt = $pdo->prepare('SELECT password_hash FROM admin_users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $adminId]);
    $hash = $stmt->fetchColumn();

    if (!$hash || !password_verify($currentPassword, $hash)) {
        return 'Current password is incorrect.';
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $update  = $pdo->prepare('UPDATE admin_users SET password_hash = :hash WHERE id = :id');
    $update->execute(['hash' => $newHash, 'id' => $adminId]);

    return true;
}
