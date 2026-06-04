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

    if (in_array(normalizeStaffRole($filters['role']), getKnownStaffRoles(), true)) {
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
 * Attach optional event fields to a registration row (avoids SQL on missing columns).
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function mergeRegistrationWithEvent(PDO $pdo, array $row): array
{
    $eventId = (int) ($row['event_id'] ?? 0);
    if ($eventId < 1) {
        return $row;
    }

    try {
        require_once __DIR__ . '/events-repository.php';
        $event = getEventById($pdo, $eventId);
        if ($event === null) {
            return $row;
        }

        $map = [
            'main_security_company' => 'main_security_company',
            'reporting_point'       => 'reporting_point',
            'venue_eircode'         => 'venue_eircode',
            'start_time'            => 'event_start_time',
            'end_time'              => 'event_end_time',
            'venue_lat'             => 'venue_lat',
            'venue_lng'             => 'venue_lng',
            'signin_radius_m'       => 'signin_radius_m',
            'name'                  => 'event_name',
            'location'              => 'event_location',
            'event_date'            => 'event_date',
            'is_active'             => 'is_active',
        ];

        foreach ($map as $from => $to) {
            if (array_key_exists($from, $event)) {
                $row[$to] = $event[$from];
            }
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] mergeRegistrationWithEvent: ' . $e->getMessage());
    }

    return $row;
}

/**
 * Merge staff data from staff table with registration row (backward compatibility).
 * If staff_id exists and staff table is available, prefer staff table data.
 * Falls back to registration row columns if staff table is not available.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function mergeRegistrationWithStaff(PDO $pdo, array $row): array
{
    $staffId = (int) ($row['staff_id'] ?? 0);
    if ($staffId < 1) {
        // No staff_id, use registration row data as-is
        return $row;
    }

    try {
        $staff = getStaffById($pdo, $staffId);
        if ($staff === null) {
            // Staff record not found, use registration row data
            return $row;
        }

        // Merge staff table data, preferring it over registration columns
        $staffFields = [
            'surname', 'first_name', 'full_address', 'eircode',
            'location_lat', 'location_lng', 'email', 'mobile',
            'date_of_birth', 'gender', 'pps_number', 'bank_iban', 'staff_role'
        ];

        foreach ($staffFields as $field) {
            if (isset($staff[$field]) && $staff[$field] !== '') {
                $row[$field] = $staff[$field];
            }
        }
    } catch (Throwable $e) {
        // If staff table operations fail, use registration row data
        error_log('[EventStaff] mergeRegistrationWithStaff: ' . $e->getMessage());
    }

    return $row;
}

/**
 * @return array<string, mixed>|null
 */
function getStaffRegistrationById(PDO $pdo, int $id): ?array
{
    $sql = 'SELECT sr.*, e.name AS event_name, e.event_date, e.location AS event_location
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE sr.id = :id
            LIMIT 1';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('[EventStaff] getStaffRegistrationById: ' . $e->getMessage());

        return null;
    }

    if (!$row) {
        return null;
    }

    $row = mergeRegistrationWithEvent($pdo, $row);
    $row = mergeRegistrationWithStaff($pdo, $row);

    return $row;
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

/**
 * Find staff record by email (normalized staff table).
 * @return array<string, mixed>|null
 */
function getStaffByEmail(PDO $pdo, string $email): ?array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM staff WHERE LOWER(email) = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        // Staff table might not exist yet (migration not run)
        return null;
    }

    return $row ?: null;
}

/**
 * Get staff record by ID.
 * @return array<string, mixed>|null
 */
function getStaffById(PDO $pdo, int $staffId): ?array
{
    if ($staffId <= 0) {
        return null;
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM staff WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $staffId]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        // Staff table might not exist yet (migration not run)
        return null;
    }

    return $row ?: null;
}

/**
 * Create or update staff record from registration data.
 * Returns the staff ID.
 * @param array<string, mixed> $data
 */
function findOrCreateStaff(PDO $pdo, array $data): int
{
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    if ($email === '') {
        throw new InvalidArgumentException('Email is required');
    }

    // Check if staff table exists
    try {
        $pdo->query('SELECT 1 FROM staff LIMIT 1');
    } catch (PDOException $e) {
        // Staff table doesn't exist yet, return 0 (use old structure)
        return 0;
    }

    // Try to find existing staff by email
    $staff = getStaffByEmail($pdo, $email);
    if ($staff) {
        // Update existing staff record
        $stmt = $pdo->prepare(
            'UPDATE staff SET 
                surname = :surname,
                first_name = :first_name,
                full_address = :full_address,
                eircode = :eircode,
                location_lat = :location_lat,
                location_lng = :location_lng,
                mobile = :mobile,
                date_of_birth = :date_of_birth,
                gender = :gender,
                pps_number = :pps_number,
                bank_iban = :bank_iban,
                staff_role = :staff_role,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id'
        );
        $stmt->execute([
            'surname' => $data['surname'] ?? '',
            'first_name' => $data['first_name'] ?? '',
            'full_address' => $data['full_address'] ?? '',
            'eircode' => $data['eircode'] ?? '',
            'location_lat' => $data['location_lat'] ?? null,
            'location_lng' => $data['location_lng'] ?? null,
            'mobile' => $data['mobile'] ?? '',
            'date_of_birth' => $data['date_of_birth'] ?? '',
            'gender' => $data['gender'] ?? 'prefer_not_to_say',
            'pps_number' => $data['pps_number'] ?? '',
            'bank_iban' => $data['bank_iban'] ?? '',
            'staff_role' => $data['staff_role'] ?? 'steward',
            'id' => $staff['id'],
        ]);
        return (int) $staff['id'];
    }

    // Create new staff record
    $stmt = $pdo->prepare(
        'INSERT INTO staff (
            surname, first_name, full_address, eircode, location_lat, location_lng,
            email, mobile, date_of_birth, gender, pps_number, bank_iban, staff_role
        ) VALUES (
            :surname, :first_name, :full_address, :eircode, :location_lat, :location_lng,
            :email, :mobile, :date_of_birth, :gender, :pps_number, :bank_iban, :staff_role
        )'
    );
    $stmt->execute([
        'surname' => $data['surname'] ?? '',
        'first_name' => $data['first_name'] ?? '',
        'full_address' => $data['full_address'] ?? '',
        'eircode' => $data['eircode'] ?? '',
        'location_lat' => $data['location_lat'] ?? null,
        'location_lng' => $data['location_lng'] ?? null,
        'email' => $email,
        'mobile' => $data['mobile'] ?? '',
        'date_of_birth' => $data['date_of_birth'] ?? '',
        'gender' => $data['gender'] ?? 'prefer_not_to_say',
        'pps_number' => $data['pps_number'] ?? '',
        'bank_iban' => $data['bank_iban'] ?? '',
        'staff_role' => $data['staff_role'] ?? 'steward',
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Get all staff members from the staff table.
 * @return array<int, array<string, mixed>>
 */
function getAllStaff(PDO $pdo): array
{
    try {
        $stmt = $pdo->query(
            'SELECT s.*, 
                    (SELECT COUNT(*) FROM staff_registrations sr WHERE sr.staff_id = s.id) as registration_count,
                    (SELECT COUNT(*) FROM staff_registrations sr WHERE sr.staff_id = s.id AND sr.status = "approved") as approved_count
             FROM staff s
             ORDER BY s.surname ASC, s.first_name ASC'
        );
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        // Staff table might not exist yet (migration not run)
        error_log('[EventStaff] getAllStaff: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get staff members with filters.
 * @param array<string, mixed> $filters
 * @return array<int, array<string, mixed>>
 */
function getStaffWithFilters(PDO $pdo, array $filters): array
{
    try {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['q'])) {
            $where[] = '(s.surname LIKE :q OR s.first_name LIKE :q OR s.email LIKE :q OR s.mobile LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        if (!empty($filters['role']) && in_array($filters['role'], ['dsp', 'static', 'steward'], true)) {
            $where[] = 's.staff_role = :role';
            $params['role'] = $filters['role'];
        }

        if (isset($filters['blacklisted'])) {
            $where[] = 's.is_blacklisted = :blacklisted';
            $params['blacklisted'] = $filters['blacklisted'] ? 1 : 0;
        }

        $sql = 'SELECT s.*, 
                       (SELECT COUNT(*) FROM staff_registrations sr WHERE sr.staff_id = s.id) as registration_count,
                       (SELECT COUNT(*) FROM staff_registrations sr WHERE sr.staff_id = s.id AND sr.status = "approved") as approved_count
                FROM staff s
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY s.surname ASC, s.first_name ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('[EventStaff] getStaffWithFilters: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get staff by profile token.
 * @return array<string, mixed>|null
 */
function getStaffByProfileToken(PDO $pdo, string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM staff WHERE profile_token = :token LIMIT 1');
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('[EventStaff] getStaffByProfileToken: ' . $e->getMessage());
        return null;
    }

    return $row ?: null;
}

/**
 * Generate or regenerate profile token for a staff member.
 * @return string
 */
function generateStaffProfileToken(PDO $pdo, int $staffId): string
{
    $staff = getStaffById($pdo, $staffId);
    if (!$staff) {
        throw new InvalidArgumentException('Staff not found');
    }

    $token = bin2hex(random_bytes(32));
    
    $stmt = $pdo->prepare('UPDATE staff SET profile_token = :token WHERE id = :id');
    $stmt->execute(['token' => $token, 'id' => $staffId]);
    
    return $token;
}

/**
 * Update staff information (public profile update).
 * Does not allow changing email or date_of_birth.
 * @param array<string, mixed> $data
 * @return bool
 */
function updateStaffProfile(PDO $pdo, int $staffId, array $data): bool
{
    $allowedFields = [
        'surname', 'first_name', 'full_address', 'eircode',
        'location_lat', 'location_lng', 'mobile', 'gender',
        'pps_number', 'bank_iban', 'staff_role'
    ];

    $updates = [];
    $params = ['id' => $staffId];

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = :$field";
            $params[$field] = $data[$field];
        }
    }

    if ($updates === []) {
        return false;
    }

    $sql = 'UPDATE staff SET ' . implode(', ', $updates) . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id';
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log('[EventStaff] updateStaffProfile: ' . $e->getMessage());
        return false;
    }
}
