<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-allocation-schema.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/event-capacity.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/audit-log.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/staff-psa.php';

function logStaffShiftAssignment(
    PDO $pdo,
    string $action,
    array $context
): void {
    ensureStaffAllocationSchema($pdo);
    if (!staffAllocationTableExists($pdo, 'staff_shift_assignment_log')) {
        return;
    }

    $admin = getAdminUser();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO staff_shift_assignment_log
             (admin_id, admin_username, staff_id, registration_id, waitlist_id, email, action,
              from_event_id, to_event_id, reason, details, ip_address)
             VALUES
             (:admin_id, :admin_username, :staff_id, :registration_id, :waitlist_id, :email, :action,
              :from_event_id, :to_event_id, :reason, :details, :ip_address)'
        );
        $stmt->execute([
            'admin_id'        => $admin ? (int) ($admin['id'] ?? 0) : null,
            'admin_username'  => $admin ? (string) ($admin['username'] ?? '') : 'system',
            'staff_id'        => isset($context['staff_id']) ? (int) $context['staff_id'] : null,
            'registration_id' => isset($context['registration_id']) ? (int) $context['registration_id'] : null,
            'waitlist_id'     => isset($context['waitlist_id']) ? (int) $context['waitlist_id'] : null,
            'email'           => isset($context['email']) ? strtolower(trim((string) $context['email'])) : null,
            'action'          => substr($action, 0, 64),
            'from_event_id'   => isset($context['from_event_id']) ? (int) $context['from_event_id'] : null,
            'to_event_id'     => isset($context['to_event_id']) ? (int) $context['to_event_id'] : null,
            'reason'          => isset($context['reason']) ? trim((string) $context['reason']) : null,
            'details'         => isset($context['details']) ? trim((string) $context['details']) : null,
            'ip_address'      => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        ]);
    } catch (Throwable $e) {
        error_log('[EventStaff] logStaffShiftAssignment: ' . $e->getMessage());
    }

    $auditDetails = json_encode(array_filter([
        'action'          => $action,
        'staff_id'        => $context['staff_id'] ?? null,
        'registration_id' => $context['registration_id'] ?? null,
        'waitlist_id'     => $context['waitlist_id'] ?? null,
        'email'           => $context['email'] ?? null,
        'from_event_id'   => $context['from_event_id'] ?? null,
        'to_event_id'     => $context['to_event_id'] ?? null,
        'reason'          => $context['reason'] ?? null,
        'details'         => $context['details'] ?? null,
    ], static fn ($v): bool => $v !== null && $v !== ''), JSON_UNESCAPED_UNICODE);

    logAdminAudit(
        $pdo,
        'shift_allocation',
        'registration',
        isset($context['registration_id']) ? (int) $context['registration_id'] : null,
        is_string($auditDetails) ? $auditDetails : $action
    );
}

/**
 * @return array{closed: int[], full: int[], available: int[]}
 */
function splitEventIdsByAvailability(PDO $pdo, array $eventIds): array
{
    $closed    = [];
    $full      = [];
    $available = [];

    foreach ($eventIds as $eventId) {
        $eventId = (int) $eventId;
        if ($eventId < 1) {
            continue;
        }
        $event = getEventById($pdo, $eventId);
        if ($event === null || !isEventOpenForRegistration($event)) {
            $closed[] = $eventId;
            continue;
        }
        if (isEventStaffCapacityFull($pdo, $event)) {
            $full[] = $eventId;
            continue;
        }
        $available[] = $eventId;
    }

    return [
        'closed'    => $closed,
        'full'      => $full,
        'available' => $available,
    ];
}

/**
 * @param array<string, mixed> $data
 * @return array{ok: bool, waitlist_id?: int, staff_id?: int, error?: string}
 */
function saveWaitlistRegistration(PDO $pdo, array $data, array $files = []): array
{
    ensureStaffAllocationSchema($pdo);
    if (!staffAllocationTableExists($pdo, 'staff_waitlist')) {
        return ['ok' => false, 'error' => 'Waiting list is not available yet. Please contact support.'];
    }

    $email = normalizeRegistrationEmail((string) ($data['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Valid email is required.'];
    }

    $allocationType = normalizeWaitlistAllocationType($data);
    $preferredEventId = (int) ($data['preferred_event_id'] ?? 0);
    if ($preferredEventId < 1) {
        $ids = normalizeEventIds($data);
        $preferredEventId = $ids[0] ?? 0;
    }

    $staffId = ensureStaffRecordForEmail($pdo, $email);
    if ($staffId !== null) {
        $psaSaveErrors = saveStaffPsaFromForm($pdo, $staffId, $data, $files, false);
        if ($psaSaveErrors !== []) {
            error_log('[EventStaff] waitlist PSA save for ' . $email . ': ' . json_encode($psaSaveErrors));
        }
    }

    $existing = $pdo->prepare(
        "SELECT id FROM staff_waitlist
         WHERE LOWER(email) = :email AND status = 'active'
           AND (preferred_event_id <=> :event_id)
         LIMIT 1"
    );
    $existing->execute([
        'email'    => $email,
        'event_id' => $preferredEventId > 0 ? $preferredEventId : null,
    ]);
    $existingId = (int) ($existing->fetchColumn() ?: 0);
    if ($existingId > 0) {
        return ['ok' => true, 'waitlist_id' => $existingId, 'staff_id' => (int) ($staffId ?? 0), 'error' => ''];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO staff_waitlist
         (staff_id, email, surname, first_name, staff_role, form_slug, venue_id, preferred_event_id,
          allocation_type, status, notes)
         VALUES
         (:staff_id, :email, :surname, :first_name, :staff_role, :form_slug, :venue_id, :preferred_event_id,
          :allocation_type, :status, :notes)'
    );
    $stmt->execute([
        'staff_id'           => $staffId > 0 ? $staffId : null,
        'email'              => $email,
        'surname'            => trim((string) ($data['surname'] ?? '')),
        'first_name'         => trim((string) ($data['first_name'] ?? '')),
        'staff_role'         => normalizeStaffRole((string) ($data['staff_role'] ?? 'dsp')),
        'form_slug'          => trim((string) ($data['form_slug'] ?? '')) ?: null,
        'venue_id'           => (int) ($data['venue_id'] ?? 0) > 0 ? (int) $data['venue_id'] : null,
        'preferred_event_id' => $preferredEventId > 0 ? $preferredEventId : null,
        'allocation_type'    => $allocationType,
        'status'             => 'active',
        'notes'              => trim((string) ($data['waitlist_notes'] ?? '')) ?: null,
    ]);

    $waitlistId = (int) $pdo->lastInsertId();

    logStaffShiftAssignment($pdo, 'waitlist_join', [
        'staff_id'     => $staffId,
        'waitlist_id'  => $waitlistId,
        'email'        => $email,
        'to_event_id'  => $preferredEventId > 0 ? $preferredEventId : null,
        'reason'       => 'Self-registration waiting list',
        'details'      => formatAllocationTypeLabel($allocationType),
    ]);

    return ['ok' => true, 'waitlist_id' => $waitlistId, 'staff_id' => (int) ($staffId ?? 0)];
}

/**
 * @return list<array<string, mixed>>
 */
function searchStaffForAllocation(PDO $pdo, string $query, int $limit = 25): array
{
    ensureStaffAllocationSchema($pdo);
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    $limit = max(1, min(50, $limit));

    $params = [];
    $where  = ['1=0'];
    if (ctype_digit($query)) {
        $where[]           = 's.id = :staff_id';
        $params['staff_id'] = (int) $query;
    }
    $like = '%' . $query . '%';
    $where[] = 'LOWER(CONCAT(s.first_name, \' \', s.surname)) LIKE LOWER(:name_like)';
    $where[] = 'LOWER(s.email) LIKE LOWER(:email_like)';
    $where[] = 'REPLACE(s.mobile, \' \', \'\') LIKE :phone_like';
    $where[] = 'LOWER(s.pps_number) LIKE LOWER(:pps_like)';
    $where[] = 'LOWER(s.psa_licence) LIKE LOWER(:psa_like)';
    $params['name_like']  = $like;
    $params['email_like'] = $like;
    $params['phone_like'] = '%' . preg_replace('/\s+/', '', $query) . '%';
    $params['pps_like']   = $like;
    $params['psa_like']   = $like;

    $sql = 'SELECT s.id, s.first_name, s.surname, s.email, s.mobile, s.pps_number, s.psa_licence, s.staff_role
            FROM staff s
            WHERE (' . implode(' OR ', $where) . ')
            ORDER BY s.surname ASC, s.first_name ASC
            LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string, mixed>>
 */
function getAllocationCentreEventRows(PDO $pdo, ?int $eventId = null, int $limit = 100): array
{
    ensureStaffAllocationSchema($pdo);
    $limit = max(1, min(200, $limit));

    $params = [];
    $filter = '';
    if ($eventId !== null && $eventId > 0) {
        $filter = ' AND e.id = :event_id';
        $params['event_id'] = $eventId;
    }

    $sql = "SELECT e.id, e.name, e.event_date, e.is_active, e.staff_needed
            FROM events e
            WHERE e.is_active = 1
              {$filter}
            ORDER BY e.event_date ASC, e.name ASC
            LIMIT {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rows = [];
    foreach ($events as $event) {
        $summary = getEventCapacitySummary($pdo, $event);
        $eventId = (int) ($event['id'] ?? 0);
        $waitlistCount = 0;
        if (staffAllocationTableExists($pdo, 'staff_waitlist')) {
            $w = $pdo->prepare(
                "SELECT COUNT(*) FROM staff_waitlist
                 WHERE status = 'active'
                   AND (preferred_event_id = :event_id OR preferred_event_id IS NULL)"
            );
            $w->execute(['event_id' => $eventId]);
            $waitlistCount = (int) $w->fetchColumn();
        }

        $rows[] = [
            'event_id'        => $eventId,
            'event_name'      => (string) ($event['name'] ?? ''),
            'event_date'      => (string) ($event['event_date'] ?? ''),
            'needed'          => $summary['needed'],
            'filled'          => $summary['filled'],
            'approved'        => $summary['approved'],
            'remaining'       => $summary['remaining'],
            'is_full'         => $summary['is_full'],
            'waitlist_count'  => $waitlistCount,
        ];
    }

    return $rows;
}

/**
 * @return list<array<string, mixed>>
 */
function getWaitingListEntries(PDO $pdo, array $filters = [], int $limit = 100): array
{
    ensureStaffAllocationSchema($pdo);
    if (!staffAllocationTableExists($pdo, 'staff_waitlist')) {
        return [];
    }

    $limit = max(1, min(200, $limit));
    $where = ["w.status = 'active'"];
    $params = [];

    $eventId = (int) ($filters['event_id'] ?? 0);
    if ($eventId > 0) {
        $where[] = '(w.preferred_event_id = :event_id OR w.preferred_event_id IS NULL)';
        $params['event_id'] = $eventId;
    }

    $type = trim((string) ($filters['allocation_type'] ?? ''));
    if ($type !== '' && isWaitlistAllocationType($type)) {
        $where[] = 'w.allocation_type = :allocation_type';
        $params['allocation_type'] = $type;
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $pattern = '%' . strtolower($q) . '%';
        $where[] = '(LOWER(w.email) LIKE :q_email OR LOWER(CONCAT(w.first_name, \' \', w.surname)) LIKE :q_name)';
        $params['q_email'] = $pattern;
        $params['q_name']  = $pattern;
    }

    $sql = 'SELECT w.*, e.name AS preferred_event_name, e.event_date AS preferred_event_date
            FROM staff_waitlist w
            LEFT JOIN events e ON e.id = w.preferred_event_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY w.created_at ASC
            LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string, mixed>>
 */
function getStaffAssignmentHistory(PDO $pdo, ?int $staffId = null, ?string $email = null, ?int $registrationId = null, int $limit = 50): array
{
    ensureStaffAllocationSchema($pdo);
    if (!staffAllocationTableExists($pdo, 'staff_shift_assignment_log')) {
        return [];
    }

    $limit = max(1, min(100, $limit));
    $where = ['1=1'];
    $params = [];

    if ($registrationId !== null && $registrationId > 0) {
        $where[] = 'l.registration_id = :registration_id';
        $params['registration_id'] = $registrationId;
    }
    if ($staffId !== null && $staffId > 0) {
        $where[] = 'l.staff_id = :staff_id';
        $params['staff_id'] = $staffId;
    }
    if ($email !== null && trim($email) !== '') {
        $where[] = 'LOWER(l.email) = :email';
        $params['email'] = normalizeRegistrationEmail($email);
    }

    $sql = 'SELECT l.*,
                   fe.name AS from_event_name,
                   te.name AS to_event_name
            FROM staff_shift_assignment_log l
            LEFT JOIN events fe ON fe.id = l.from_event_id
            LEFT JOIN events te ON te.id = l.to_event_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY l.created_at DESC, l.id DESC
            LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string, mixed>>
 */
function getEventAssignmentHistory(PDO $pdo, int $eventId, int $limit = 50): array
{
    ensureStaffAllocationSchema($pdo);
    if (!staffAllocationTableExists($pdo, 'staff_shift_assignment_log') || $eventId < 1) {
        return [];
    }

    $limit = max(1, min(100, $limit));

    $sql = 'SELECT l.*,
                   fe.name AS from_event_name,
                   te.name AS to_event_name
            FROM staff_shift_assignment_log l
            LEFT JOIN events fe ON fe.id = l.from_event_id
            LEFT JOIN events te ON te.id = l.to_event_id
            WHERE l.from_event_id = :event_id OR l.to_event_id = :event_id2
            ORDER BY l.created_at DESC, l.id DESC
            LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'event_id'  => $eventId,
        'event_id2' => $eventId,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Pending registrations for bulk approve in Allocation Centre.
 *
 * @return list<array<string, mixed>>
 */
function getPendingRegistrationsForAllocation(PDO $pdo, ?int $eventId = null, int $limit = 100): array
{
    $limit = max(1, min(200, $limit));
    $where = [pendingRegistrationStatusSql('sr')];
    $params = [];

    if ($eventId !== null && $eventId > 0) {
        $where[] = 'sr.event_id = :event_id';
        $params['event_id'] = $eventId;
    }

    $sql = 'SELECT sr.id, sr.first_name, sr.surname, sr.email, sr.event_id, sr.created_at,
                   e.name AS event_name, e.event_date
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY sr.created_at ASC
            LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{ok: bool, registration_id?: int, error?: string, needs_confirm?: string}
 */
function adminAssignStaffToEvent(
    PDO $pdo,
    int $staffId,
    int $eventId,
    string $reason,
    bool $confirmDuplicate = false,
    bool $confirmSameDay = false
): array {
    ensureStaffAllocationSchema($pdo);

    if ($staffId < 1 || $eventId < 1) {
        return ['ok' => false, 'error' => 'Staff and event are required.'];
    }
    if (trim($reason) === '') {
        return ['ok' => false, 'error' => 'Reason for override is required.'];
    }

    $staff = getStaffById($pdo, $staffId);
    $event = getEventById($pdo, $eventId);
    if ($staff === null) {
        return ['ok' => false, 'error' => 'Staff member not found.'];
    }
    if ($event === null) {
        return ['ok' => false, 'error' => 'Event not found.'];
    }

    $email = normalizeRegistrationEmail((string) ($staff['email'] ?? ''));
    if ($email === '') {
        return ['ok' => false, 'error' => 'Staff email is missing.'];
    }

    require_once __DIR__ . '/platform/canonical-identity.php';
    $existingByStaff = canonicalIdentityFindActiveRegistrationForStaffOnEvent($pdo, $staffId, $eventId);
    if ($existingByStaff !== null) {
        $registrationId = (int) ($existingByStaff['id'] ?? 0);
        if ($registrationId > 0) {
            $admin = getAdminUser();
            $adminId = $admin ? (int) ($admin['id'] ?? 0) : null;
            $upd = $pdo->prepare(
                "UPDATE staff_registrations
                 SET allocation_type = 'admin_assigned',
                     admin_assigned_by = :admin_id,
                     admin_assigned_at = NOW(),
                     override_reason = :reason,
                     staff_id = :staff_id,
                     status = IF(status = 'rejected', 'pending', status),
                     updated_at = NOW()
                 WHERE id = :id"
            );
            $upd->execute([
                'admin_id' => $adminId,
                'reason'   => trim($reason),
                'staff_id' => $staffId,
                'id'       => $registrationId,
            ]);
            canonicalIdentityEnforceOnRegistration($pdo, $registrationId, 'admin_assign_existing');

            return ['ok' => true, 'registration_id' => $registrationId];
        }
    }

    if (registrationExistsForEmail($pdo, $email, $eventId)) {
        if (!$confirmDuplicate) {
            return ['ok' => false, 'needs_confirm' => 'duplicate', 'error' => 'Staff is already registered for this event. Confirm override to continue.'];
        }
    }

    if (!$confirmSameDay) {
        $sameDayError = validateNoExistingShiftOnSameDay($pdo, $email, [$eventId]);
        if ($sameDayError !== null) {
            return ['ok' => false, 'needs_confirm' => 'same_day', 'error' => $sameDayError . ' Confirm override to continue.'];
        }
    }

    $admin = getAdminUser();
    $adminId = $admin ? (int) ($admin['id'] ?? 0) : null;

    $data = array_merge($staff, [
        'email'       => $email,
        'staff_role'  => (string) ($staff['staff_role'] ?? 'dsp'),
        'privacy_consent' => '1',
    ]);

    try {
        if (registrationExistsForEmail($pdo, $email, $eventId)) {
            $stmt = $pdo->prepare(
                'SELECT id FROM staff_registrations
                 WHERE LOWER(email) = :email AND event_id = :event_id LIMIT 1'
            );
            $stmt->execute(['email' => $email, 'event_id' => $eventId]);
            $registrationId = (int) ($stmt->fetchColumn() ?: 0);
            $upd = $pdo->prepare(
                "UPDATE staff_registrations
                 SET allocation_type = 'admin_assigned',
                     admin_assigned_by = :admin_id,
                     admin_assigned_at = NOW(),
                     override_reason = :reason,
                     status = IF(status = 'rejected', 'pending', status),
                     updated_at = NOW()
                 WHERE id = :id"
            );
            $upd->execute([
                'admin_id' => $adminId,
                'reason'   => trim($reason),
                'id'       => $registrationId,
            ]);
        } else {
            $registrationId = saveRegistration($pdo, $data, $eventId, (string) ($staff['staff_role'] ?? 'dsp'));
            $upd = $pdo->prepare(
                "UPDATE staff_registrations
                 SET allocation_type = 'admin_assigned',
                     admin_assigned_by = :admin_id,
                     admin_assigned_at = NOW(),
                     override_reason = :reason
                 WHERE id = :id"
            );
            $upd->execute([
                'admin_id' => $adminId,
                'reason'   => trim($reason),
                'id'       => $registrationId,
            ]);
        }

        logStaffShiftAssignment($pdo, 'assign', [
            'staff_id'        => $staffId,
            'registration_id' => $registrationId,
            'email'           => $email,
            'to_event_id'     => $eventId,
            'reason'          => $reason,
        ]);

        return ['ok' => true, 'registration_id' => $registrationId];
    } catch (Throwable $e) {
        error_log('[EventStaff] adminAssignStaffToEvent: ' . $e->getMessage());

        return ['ok' => false, 'error' => 'Assignment failed: ' . $e->getMessage()];
    }
}

/**
 * @return array{ok: bool, registration_id?: int, error?: string, needs_confirm?: string}
 */
function adminMoveStaffAssignment(
    PDO $pdo,
    int $registrationId,
    int $newEventId,
    string $reason,
    bool $confirmDuplicate = false,
    bool $confirmSameDay = false
): array {
    ensureStaffAllocationSchema($pdo);

    if ($registrationId < 1 || $newEventId < 1) {
        return ['ok' => false, 'error' => 'Registration and target event are required.'];
    }
    if (trim($reason) === '') {
        return ['ok' => false, 'error' => 'Reason for move is required.'];
    }

    $row = getStaffRegistrationById($pdo, $registrationId);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Registration not found.'];
    }

    $fromEventId = (int) ($row['event_id'] ?? 0);
    if ($fromEventId === $newEventId) {
        return ['ok' => false, 'error' => 'Staff is already on that event.'];
    }

    $email = normalizeRegistrationEmail((string) ($row['email'] ?? ''));
    if (registrationExistsForEmail($pdo, $email, $newEventId)) {
        if (!$confirmDuplicate) {
            return ['ok' => false, 'needs_confirm' => 'duplicate', 'error' => 'Staff already has a registration on the target event. Confirm override to continue.'];
        }
    }

    if (!$confirmSameDay) {
        $sameDayError = validateNoExistingShiftOnSameDay($pdo, $email, [$newEventId]);
        if ($sameDayError !== null) {
            return ['ok' => false, 'needs_confirm' => 'same_day', 'error' => $sameDayError . ' Confirm override to continue.'];
        }
    }

    $admin = getAdminUser();
    $adminId = $admin ? (int) ($admin['id'] ?? 0) : null;

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "UPDATE staff_registrations
             SET event_id = :new_event_id,
                 previous_event_id = :from_event_id,
                 allocation_type = 'admin_assigned',
                 admin_assigned_by = :admin_id,
                 admin_assigned_at = NOW(),
                 override_reason = :reason,
                 updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            'new_event_id'  => $newEventId,
            'from_event_id' => $fromEventId,
            'admin_id'      => $adminId,
            'reason'        => trim($reason),
            'id'            => $registrationId,
        ]);
        $pdo->commit();

        logStaffShiftAssignment($pdo, 'move', [
            'staff_id'        => (int) ($row['staff_id'] ?? 0),
            'registration_id' => $registrationId,
            'email'           => $email,
            'from_event_id'   => $fromEventId,
            'to_event_id'     => $newEventId,
            'reason'          => $reason,
        ]);

        return ['ok' => true, 'registration_id' => $registrationId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => 'Move failed: ' . $e->getMessage()];
    }
}

/**
 * @return array{ok: bool, error?: string}
 */
function adminRemoveStaffAssignment(PDO $pdo, int $registrationId, string $reason): array
{
    ensureStaffAllocationSchema($pdo);

    if ($registrationId < 1) {
        return ['ok' => false, 'error' => 'Registration is required.'];
    }
    if (trim($reason) === '') {
        return ['ok' => false, 'error' => 'Reason for removal is required.'];
    }

    $row = getStaffRegistrationById($pdo, $registrationId);
    if ($row === null) {
        return ['ok' => false, 'error' => 'Registration not found.'];
    }

    if (!updateStaffStatus($pdo, $registrationId, 'rejected', true)) {
        return ['ok' => false, 'error' => 'Could not remove assignment.'];
    }

    logStaffShiftAssignment($pdo, 'remove', [
        'staff_id'        => (int) ($row['staff_id'] ?? 0),
        'registration_id' => $registrationId,
        'email'           => (string) ($row['email'] ?? ''),
        'from_event_id'   => (int) ($row['event_id'] ?? 0),
        'reason'          => $reason,
    ]);

    return ['ok' => true];
}

/**
 * @return array{ok: bool, registration_id?: int, error?: string}
 */
function adminAllocateWaitlistEntry(PDO $pdo, int $waitlistId, int $eventId, string $reason): array
{
    ensureStaffAllocationSchema($pdo);

    if ($waitlistId < 1 || $eventId < 1) {
        return ['ok' => false, 'error' => 'Waitlist entry and event are required.'];
    }
    if (trim($reason) === '') {
        return ['ok' => false, 'error' => 'Reason is required.'];
    }

    if (!staffAllocationTableExists($pdo, 'staff_waitlist')) {
        return ['ok' => false, 'error' => 'Waiting list table not found.'];
    }

    $stmt = $pdo->prepare('SELECT * FROM staff_waitlist WHERE id = :id AND status = \'active\' LIMIT 1');
    $stmt->execute(['id' => $waitlistId]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$entry) {
        return ['ok' => false, 'error' => 'Waiting list entry not found or already allocated.'];
    }

    $staffId = (int) ($entry['staff_id'] ?? 0);
    if ($staffId < 1) {
        $staffId = (int) (ensureStaffRecordForEmail($pdo, (string) ($entry['email'] ?? '')) ?? 0);
    }
    if ($staffId < 1) {
        return ['ok' => false, 'error' => 'Could not resolve staff record for this waiting list entry.'];
    }

    $assign = adminAssignStaffToEvent($pdo, $staffId, $eventId, $reason, true, true);
    if (!($assign['ok'] ?? false)) {
        return $assign;
    }

    $registrationId = (int) ($assign['registration_id'] ?? 0);
    $upd = $pdo->prepare(
        "UPDATE staff_waitlist
         SET status = 'allocated',
             allocated_registration_id = :registration_id,
             updated_at = NOW()
         WHERE id = :id"
    );
    $upd->execute([
        'registration_id' => $registrationId > 0 ? $registrationId : null,
        'id'              => $waitlistId,
    ]);

    logStaffShiftAssignment($pdo, 'allocate_waitlist', [
        'staff_id'        => $staffId,
        'waitlist_id'     => $waitlistId,
        'registration_id' => $registrationId,
        'email'           => (string) ($entry['email'] ?? ''),
        'to_event_id'     => $eventId,
        'reason'          => $reason,
    ]);

    return ['ok' => true, 'registration_id' => $registrationId];
}

function buildWaitlistSuccessMessage(string $allocationType, int $preferredEventId = 0): string
{
    $label = formatAllocationTypeLabel($allocationType);
    $base  = 'Thank you — you have been added to the ' . $label . '. We will contact you when a shift becomes available.';

    if ($preferredEventId > 0) {
        try {
            $pdo = getDB();
            $event = getEventById($pdo, $preferredEventId);
            if ($event !== null) {
                $base .= ' Preferred event: ' . formatEventLabel($event) . '.';
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    return $base;
}
