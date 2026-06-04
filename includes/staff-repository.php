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
        $needle = '%' . $filters['q'] . '%';
        $where[] = '(LOWER(sr.surname) LIKE LOWER(:q_surname) OR LOWER(sr.first_name) LIKE LOWER(:q_first) OR LOWER(sr.email) LIKE LOWER(:q_email) OR sr.mobile LIKE :q_mobile)';
        $params['q_surname'] = $needle;
        $params['q_first']   = $needle;
        $params['q_email']   = $needle;
        $params['q_mobile']  = $needle;
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
 */
function countStaffRegistrations(PDO $pdo, array $filters): int
{
    [$where, $params] = buildStaffWhereClause($filters);

    $sql = "SELECT COUNT(*)
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE {$where}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

/**
 * @param array<string, mixed> $filters
 * @return array<int, array<string, mixed>>
 */
function getStaffRegistrations(PDO $pdo, array $filters, ?int $limit = null, int $offset = 0): array
{
    [$where, $params] = buildStaffWhereClause($filters);

    $sql = "SELECT sr.*, e.name AS event_name, e.event_date
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE {$where}
            ORDER BY sr.created_at DESC";

    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
    } else {
        $sql .= ' LIMIT 500';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll() ?: [];

    return array_map(static fn(array $row): array => mergeRegistrationWithStaff($pdo, $row), $rows);
}

/**
 * @return array<int, array<string, mixed>>
 */
function getEventsForFilter(PDO $pdo): array
{
    return $pdo->query('SELECT id, name, event_date FROM events WHERE is_active = 1 ORDER BY event_date ASC')->fetchAll();
}

function updateStaffStatus(PDO $pdo, int $id, string $status, bool $allowIncompleteApproval = false): bool
{
    if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
        return false;
    }

    if ($status === 'approved' && !$allowIncompleteApproval) {
        require_once __DIR__ . '/staff-onboarding.php';
        $row = getStaffRegistrationById($pdo, $id);
        if ($row !== null) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email !== '') {
                ensureStaffRecordForEmail($pdo, $email);
                $row = getStaffRegistrationById($pdo, $id) ?? $row;
            }
            if (!isStaffOnboardingComplete($row)) {
                return false;
            }
        }
    }

    $stmt = $pdo->prepare('UPDATE staff_registrations SET status = :status WHERE id = :id');
    $stmt->execute(['status' => $status, 'id' => $id]);

    return $stmt->rowCount() > 0;
}

/**
 * Admin staff list URL filtered by registrant email (and optional status).
 */
function buildStaffRegistrationsAdminUrl(string $email, ?string $status = null): string
{
    $params = ['q' => trim($email)];
    if ($status !== null && $status !== '') {
        $params['status'] = $status;
    }

    return 'staff.php?' . http_build_query($params);
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
 * Approved registrations with a complete staff profile only.
 *
 * @return array<int, array<string, mixed>>
 */
function getVerifiedApprovedStaffForEvent(PDO $pdo, int $eventId): array
{
    require_once __DIR__ . '/staff-onboarding.php';

    return array_values(array_filter(
        getApprovedStaffForEvent($pdo, $eventId),
        static function (array $row) use ($pdo): bool {
            return isStaffOnboardingComplete(mergeRegistrationWithStaff($pdo, $row));
        }
    ));
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

    $rows = $stmt->fetchAll() ?: [];

    return array_map(static fn(array $row): array => mergeRegistrationWithStaff($pdo, $row), $rows);
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
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email !== '') {
            $byEmail = getStaffByEmail($pdo, $email);
            if ($byEmail !== null) {
                $staffId = (int) $byEmail['id'];
            }
        }
    }
    if ($staffId < 1) {
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
            'date_of_birth', 'gender', 'pps_number', 'bank_iban', 'staff_role',
            'psa_licence', 'psa_expiry_date', 'psa_front_image', 'psa_back_image',
            'profile_completed',
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

    $rows = $stmt->fetchAll() ?: [];

    return array_map(static fn(array $row): array => mergeRegistrationWithStaff($pdo, $row), $rows);
}

/**
 * Keep staff_registrations rows in sync when admin edits the staff table.
 *
 * @param array<string, mixed> $data
 */
function syncStaffPersonalDataToRegistrations(PDO $pdo, int $staffId, array $data): void
{
    if ($staffId < 1) {
        return;
    }

    $map = [
        'surname'      => 'surname',
        'first_name'   => 'first_name',
        'full_address' => 'full_address',
        'eircode'      => 'eircode',
        'location_lat' => 'location_lat',
        'location_lng' => 'location_lng',
        'mobile'       => 'mobile',
        'gender'       => 'gender',
        'pps_number'   => 'pps_number',
        'bank_iban'    => 'bank_iban',
        'staff_role'   => 'staff_role',
    ];

    $sets   = [];
    $params = ['staff_id' => $staffId];

    foreach ($map as $from => $column) {
        if (!array_key_exists($from, $data)) {
            continue;
        }
        $sets[]           = "{$column} = :{$column}";
        $params[$column]  = $data[$from];
    }

    if ($sets === []) {
        return;
    }

    $staff = getStaffById($pdo, $staffId);
    $email = strtolower(trim((string) ($staff['email'] ?? '')));
    if ($email !== '') {
        $params['email'] = $email;
    }

    try {
        $where = 'staff_id = :staff_id';
        if ($email !== '') {
            $where .= ' OR LOWER(email) = :email';
        }
        $sql = 'UPDATE staff_registrations SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } catch (PDOException $e) {
        error_log('[EventStaff] syncStaffPersonalDataToRegistrations: ' . $e->getMessage());
    }
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
                psa_licence = :psa_licence,
                psa_expiry_date = :psa_expiry_date,
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
            'psa_licence' => trim((string) ($data['psa_licence'] ?? '')),
            'psa_expiry_date' => trim((string) ($data['psa_expiry_date'] ?? '')) ?: null,
            'staff_role' => $data['staff_role'] ?? 'steward',
            'id' => $staff['id'],
        ]);
        ensureStaffProfileTokenAfterSave($pdo, (int) $staff['id']);
        linkStaffIdToRegistrationsByEmail($pdo, $email, (int) $staff['id']);

        return (int) $staff['id'];
    }

    // Create new staff record
    $stmt = $pdo->prepare(
        'INSERT INTO staff (
            surname, first_name, full_address, eircode, location_lat, location_lng,
            email, mobile, date_of_birth, gender, pps_number, bank_iban,
            psa_licence, psa_expiry_date, staff_role
        ) VALUES (
            :surname, :first_name, :full_address, :eircode, :location_lat, :location_lng,
            :email, :mobile, :date_of_birth, :gender, :pps_number, :bank_iban,
            :psa_licence, :psa_expiry_date, :staff_role
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
        'psa_licence' => trim((string) ($data['psa_licence'] ?? '')),
        'psa_expiry_date' => trim((string) ($data['psa_expiry_date'] ?? '')) ?: null,
        'staff_role' => $data['staff_role'] ?? 'steward',
    ]);

    $newId = (int) $pdo->lastInsertId();
    ensureStaffProfileTokenAfterSave($pdo, $newId);
    linkStaffIdToRegistrationsByEmail($pdo, $email, $newId);

    return $newId;
}

/**
 * Attach staff_id to legacy registrations that only have email.
 */
function linkStaffIdToRegistrationsByEmail(PDO $pdo, string $email, int $staffId): void
{
    require_once __DIR__ . '/staff-registration-schema.php';

    if ($staffId < 1) {
        return;
    }

    $email = strtolower(trim($email));
    if ($email === '') {
        return;
    }

    try {
        if (!staffRegistrationColumnExists($pdo, 'staff_id')) {
            return;
        }

        $stmt = $pdo->prepare(
            'UPDATE staff_registrations
             SET staff_id = :staff_id
             WHERE LOWER(email) = :email AND (staff_id IS NULL OR staff_id = 0)'
        );
        $stmt->execute(['staff_id' => $staffId, 'email' => $email]);
    } catch (PDOException $e) {
        error_log('[EventStaff] linkStaffIdToRegistrationsByEmail: ' . $e->getMessage());
    }
}

/**
 * Ensure a staff row exists for a registered email (bootstrap from latest registration).
 */
function ensureStaffRecordForEmail(PDO $pdo, string $email): ?int
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    $staff = getStaffByEmail($pdo, $email);
    if ($staff !== null) {
        $staffId = (int) $staff['id'];
        linkStaffIdToRegistrationsByEmail($pdo, $email, $staffId);

        return $staffId;
    }

    $reg = getLatestRegistrationByEmail($pdo, $email);
    if ($reg === null) {
        return null;
    }

    try {
        $staffId = findOrCreateStaff($pdo, [
            'surname'       => (string) ($reg['surname'] ?? ''),
            'first_name'    => (string) ($reg['first_name'] ?? ''),
            'full_address'  => (string) ($reg['full_address'] ?? ''),
            'eircode'       => (string) ($reg['eircode'] ?? ''),
            'location_lat'  => $reg['location_lat'] ?? null,
            'location_lng'  => $reg['location_lng'] ?? null,
            'email'         => $email,
            'mobile'        => (string) ($reg['mobile'] ?? ''),
            'date_of_birth' => (string) ($reg['date_of_birth'] ?? ''),
            'gender'        => (string) ($reg['gender'] ?? 'prefer_not_to_say'),
            'pps_number'    => (string) ($reg['pps_number'] ?? ''),
            'bank_iban'     => (string) ($reg['bank_iban'] ?? ''),
            'staff_role'    => (string) ($reg['staff_role'] ?? 'steward'),
        ]);
        if ($staffId > 0) {
            linkStaffIdToRegistrationsByEmail($pdo, $email, $staffId);
        }

        return $staffId > 0 ? $staffId : null;
    } catch (Throwable $e) {
        error_log('[EventStaff] ensureStaffRecordForEmail: ' . $e->getMessage());

        return null;
    }
}

/**
 * @internal
 */
function ensureStaffProfileTokenAfterSave(PDO $pdo, int $staffId): void
{
    if ($staffId < 1) {
        return;
    }

    try {
        require_once __DIR__ . '/staff-onboarding.php';
        ensureStaffProfileToken($pdo, $staffId);
    } catch (Throwable $e) {
        error_log('[EventStaff] ensureStaffProfileToken: ' . $e->getMessage());
    }
}

/**
 * @param array<string, mixed> $filters
 */
function buildStaffDirectoryWhereClause(array $filters): array
{
    $where  = ['1=1'];
    $params = [];

    if (!empty($filters['q'])) {
        $needle = '%' . $filters['q'] . '%';
        $where[] = '(s.surname LIKE :q_surname OR s.first_name LIKE :q_first OR s.email LIKE :q_email OR s.mobile LIKE :q_mobile)';
        $params['q_surname'] = $needle;
        $params['q_first']   = $needle;
        $params['q_email']   = $needle;
        $params['q_mobile']  = $needle;
    }

    if (!empty($filters['role']) && in_array($filters['role'], ['dsp', 'static', 'steward'], true)) {
        $where[] = 's.staff_role = :role';
        $params['role'] = $filters['role'];
    }

    if (isset($filters['blacklisted'])) {
        $where[] = 's.is_blacklisted = :blacklisted';
        $params['blacklisted'] = $filters['blacklisted'] ? 1 : 0;
    }

    return [implode(' AND ', $where), $params];
}

/**
 * @param array<string, mixed> $filters
 */
function countStaffDirectory(PDO $pdo, array $filters = []): int
{
    try {
        [$where, $params] = buildStaffDirectoryWhereClause($filters);
        $sql              = 'SELECT COUNT(*) FROM staff s WHERE ' . $where;
        $stmt             = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[EventStaff] countStaffDirectory: ' . $e->getMessage());

        return 0;
    }
}

/**
 * Get all staff members from the staff table.
 * @return array<int, array<string, mixed>>
 */
function getAllStaff(PDO $pdo, ?int $limit = null, int $offset = 0): array
{
    return getStaffWithFilters($pdo, [], $limit, $offset);
}

/**
 * Get staff members with filters.
 * @param array<string, mixed> $filters
 * @return array<int, array<string, mixed>>
 */
function getStaffWithFilters(PDO $pdo, array $filters, ?int $limit = null, int $offset = 0): array
{
    try {
        [$where, $params] = buildStaffDirectoryWhereClause($filters);

        $sql = 'SELECT s.*,
                       (SELECT COUNT(*) FROM staff_registrations sr WHERE LOWER(sr.email) = LOWER(s.email)) AS registration_count,
                       (SELECT COUNT(*) FROM staff_registrations sr WHERE LOWER(sr.email) = LOWER(s.email) AND sr.status = "approved") AS approved_count,
                       (SELECT COUNT(*) FROM staff_registrations sr WHERE LOWER(sr.email) = LOWER(s.email) AND sr.status = "pending") AS pending_count
                FROM staff s
                WHERE ' . $where . '
                ORDER BY s.surname ASC, s.first_name ASC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('[EventStaff] getStaffWithFilters: ' . $e->getMessage());

        return [];
    }
}

/**
 * Staff ids eligible for bulk profile-link email (registered, not blacklisted).
 *
 * @param array<string, mixed> $filters
 * @return array<int, int>
 */
function getStaffIdsForProfileLinkBulk(PDO $pdo, array $filters = []): array
{
    $list = getStaffWithFilters($pdo, $filters);
    $ids  = [];

    foreach ($list as $staff) {
        if ((int) ($staff['is_blacklisted'] ?? 0) === 1) {
            continue;
        }
        if ((int) ($staff['registration_count'] ?? 0) < 1) {
            continue;
        }
        $ids[] = (int) $staff['id'];
    }

    return $ids;
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
    if (array_intersect_key($data, array_flip(['psa_licence', 'psa_expiry_date', 'psa_front_image', 'psa_back_image'])) !== []) {
        require_once __DIR__ . '/staff-psa.php';
        ensureStaffPsaSchema($pdo);
    }

    $allowedFields = [
        'surname', 'first_name', 'full_address', 'eircode',
        'location_lat', 'location_lng', 'mobile', 'gender',
        'pps_number', 'bank_iban', 'staff_role',
        'psa_licence', 'psa_expiry_date', 'psa_front_image', 'psa_back_image'
    ];

    $updates = [];
    $params = ['id' => $staffId];

    require_once __DIR__ . '/financial-field-validation.php';
    $data = normalizeFinancialStaffFields($data);

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = :$field";
            $params[$field] = $data[$field];
        }
    }

    if (isset($data['date_of_birth']) && trim((string) $data['date_of_birth']) !== '') {
        $current = getStaffById($pdo, $staffId);
        if ($current !== null && trim((string) ($current['date_of_birth'] ?? '')) === '') {
            $updates[] = 'date_of_birth = :date_of_birth';
            $params['date_of_birth'] = $data['date_of_birth'];
        }
    }

    if ($updates === []) {
        return false;
    }

    $sql = 'UPDATE staff SET ' . implode(', ', $updates) . ', updated_at = CURRENT_TIMESTAMP WHERE id = :id';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $ok = $stmt->rowCount() > 0;
        if ($ok) {
            syncStaffPersonalDataToRegistrations($pdo, $staffId, $data);
            try {
                require_once __DIR__ . '/google-sheets-sync.php';
                syncStaffProfileToLinkedGoogleSheets($pdo, $staffId);
            } catch (Throwable $e) {
                error_log('[EventStaff] Google Sheets sync after profile save: ' . $e->getMessage());
            }
        }

        return $ok;
    } catch (PDOException $e) {
        error_log('[EventStaff] updateStaffProfile: ' . $e->getMessage());
        return false;
    }
}

/**
 * Check if staff has completed required onboarding fields.
 * @return bool
 */
function isStaffProfileComplete(PDO $pdo, int $staffId): bool
{
    require_once __DIR__ . '/staff-onboarding.php';

    return isStaffOnboardingCompleteById($pdo, $staffId);
}

/**
 * Mark staff profile as completed.
 * @return bool
 */
function markStaffProfileCompleted(PDO $pdo, int $staffId): bool
{
    try {
        $stmt = $pdo->prepare('UPDATE staff SET profile_completed = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute(['id' => $staffId]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log('[EventStaff] markStaffProfileCompleted: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get staff data for Google Sheets export.
 * @return array<int, array<string, mixed>>
 */
function getStaffForGoogleSheets(PDO $pdo): array
{
    try {
        $stmt = $pdo->query(
            'SELECT id, first_name, surname, email, mobile, full_address, eircode,
                    date_of_birth, gender, pps_number, bank_iban, staff_role,
                    psa_licence, psa_expiry_date, profile_completed, created_at, updated_at
             FROM staff
             ORDER BY surname ASC, first_name ASC'
        );
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('[EventStaff] getStaffForGoogleSheets: ' . $e->getMessage());
        return [];
    }
}

/**
 * Sync staff data to Google Sheets.
 * Requires Google Sheets API credentials to be configured.
 * @return array{success: bool, message: string, synced: int}
 */
function syncStaffToGoogleSheets(PDO $pdo): array
{
    try {
        $staffData = getStaffForGoogleSheets($pdo);
        if (empty($staffData)) {
            return ['success' => false, 'message' => 'No staff data to sync', 'synced' => 0];
        }

        // Check if Google Sheets API is configured
        $googleSheetsId = getSetting($pdo, 'google_sheets_staff_id');
        if (empty($googleSheetsId)) {
            return ['success' => false, 'message' => 'Google Sheets ID not configured in settings', 'synced' => 0];
        }

        // This would require Google Sheets API client library
        // For now, return a placeholder response
        error_log('[EventStaff] Google Sheets sync requested for ' . count($staffData) . ' staff members');
        
        return [
            'success' => true,
            'message' => 'Google Sheets sync functionality requires Google Sheets API setup. ' . count($staffData) . ' staff members ready to sync.',
            'synced' => 0
        ];
    } catch (Exception $e) {
        error_log('[EventStaff] syncStaffToGoogleSheets: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'synced' => 0];
    }
}
