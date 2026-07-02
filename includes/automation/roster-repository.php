<?php

declare(strict_types=1);

require_once __DIR__ . '/automation-schema.php';
require_once __DIR__ . '/../events-repository.php';
require_once __DIR__ . '/../staff-repository.php';

/** @param array<string, mixed> $filters @return list<array<string, mixed>> */
function roster_get_events_filtered(PDO $pdo, array $filters = [], int $limit = 100): array
{
    $where  = ['e.is_active = 1'];
    $params = [];

    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    $dateTo   = trim((string) ($filters['date_to'] ?? ''));
    if ($dateFrom !== '') {
        $where[]           = 'e.event_date >= :date_from';
        $params['date_from'] = $dateFrom;
    } else {
        $where[] = 'e.event_date >= CURDATE()';
    }
    if ($dateTo !== '') {
        $where[]         = 'e.event_date <= :date_to';
        $params['date_to'] = $dateTo;
    }

    $venue = trim((string) ($filters['venue'] ?? ''));
    if ($venue !== '') {
        $where[]       = 'e.location LIKE :venue';
        $params['venue'] = '%' . $venue . '%';
    }

    $eventId = (int) ($filters['event_id'] ?? 0);
    if ($eventId > 0) {
        $where[]           = 'e.id = :event_id';
        $params['event_id'] = $eventId;
    }

    $sql = 'SELECT e.id, e.name, e.event_date, e.location, e.staff_needed, e.start_time, e.end_time, e.venue_id
            FROM events e
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY e.event_date ASC, e.name ASC
            LIMIT ' . max(1, min($limit, 200));

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return roster_get_events($pdo, $limit);
    }
}

/**
 * Detect double-booking: staff already assigned to another event on same date.
 *
 * @return list<array<string, mixed>>
 */
function roster_detect_conflicts(PDO $pdo, int $eventId, int $registrationId): array
{
    if ($eventId < 1 || $registrationId < 1) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT e.event_date, sr.staff_id, sr.email FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id WHERE sr.id = :rid LIMIT 1'
        );
        $stmt->execute(['rid' => $registrationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [];
        }

        $date  = (string) ($row['event_date'] ?? '');
        $email = strtolower(trim((string) ($row['email'] ?? '')));

        $stmt = $pdo->prepare(
            "SELECT e.id, e.name, e.event_date, sr.id AS registration_id
             FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id
             WHERE sr.status = 'approved' AND e.event_date = :d AND e.id <> :eid
               AND LOWER(sr.email) = :email"
        );
        $stmt->execute(['d' => $date, 'eid' => $eventId, 'email' => $email]);
        $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($conflicts !== [] && tableExists($pdo, 'event_roster_assignments')) {
            foreach ($conflicts as &$c) {
                $c['type'] = 'double_booking';
            }
        }

        return $conflicts;
    } catch (Throwable $e) {
        return [];
    }
}

/** @return array{ok: bool, conflicts: list<array<string, mixed>>} */
function roster_assign_staff_safe(PDO $pdo, int $slotId, int $registrationId): array
{
    if ($slotId < 1 || $registrationId < 1) {
        return ['ok' => false, 'conflicts' => []];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT s.event_id FROM event_roster_slots s WHERE s.id = :sid LIMIT 1'
        );
        $stmt->execute(['sid' => $slotId]);
        $eventId = (int) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $eventId = 0;
    }

    $conflicts = roster_detect_conflicts($pdo, $eventId, $registrationId);
    if ($conflicts !== []) {
        return ['ok' => false, 'conflicts' => $conflicts];
    }

    // Prevent duplicate assignment in same event slot pool
    if (tableExists($pdo, 'event_roster_assignments')) {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM event_roster_assignments WHERE registration_id = :rid
                 AND slot_id IN (SELECT id FROM event_roster_slots WHERE event_id = :eid)'
            );
            $stmt->execute(['rid' => $registrationId, 'eid' => $eventId]);
            if ((int) $stmt->fetchColumn() > 0) {
                return ['ok' => false, 'conflicts' => [['type' => 'already_assigned', 'name' => 'Already on roster']]];
            }
        } catch (Throwable $e) {
            // continue
        }
    }

    return ['ok' => roster_assign_staff($pdo, $slotId, $registrationId), 'conflicts' => []];
}

/** @return list<array<string, mixed>> */
function roster_get_events(PDO $pdo, int $limit = 50): array
{
    try {
        return $pdo->query(
            "SELECT id, name, event_date, location, staff_needed, start_time, end_time
             FROM events WHERE is_active = 1 AND event_date >= CURDATE()
             ORDER BY event_date ASC LIMIT " . max(1, min($limit, 100))
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array<string, mixed>> */
function roster_get_slots(PDO $pdo, int $eventId): array
{
    if (!tableExists($pdo, 'event_roster_slots') || $eventId < 1) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM event_roster_slots WHERE event_id = :eid ORDER BY sort_order ASC, role_name ASC, id ASC'
    );
    $stmt->execute(['eid' => $eventId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array<string, mixed>> */
function roster_get_assignments(PDO $pdo, int $eventId): array
{
    if (!tableExists($pdo, 'event_roster_assignments') || $eventId < 1) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT a.*, s.role_name, s.shift_label, s.shift_start, s.shift_end,
                sr.first_name, sr.surname, sr.email, sr.staff_role, sr.status AS reg_status,
                st.id AS linked_staff_id,
                att.id AS attendance_id
         FROM event_roster_assignments a
         INNER JOIN event_roster_slots s ON s.id = a.slot_id
         LEFT JOIN staff_registrations sr ON sr.id = a.registration_id
         LEFT JOIN staff st ON st.id = a.staff_id
         LEFT JOIN attendance att ON att.registration_id = sr.id
         WHERE s.event_id = :eid
         ORDER BY s.sort_order, a.id"
    );
    $stmt->execute(['eid' => $eventId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array{required: int, assigned: int, confirmed: int, checked_in: int, gap: int} */
function roster_coverage_summary(PDO $pdo, int $eventId): array
{
    $event = getEventById($pdo, $eventId);
    $needed = (int) ($event['staff_needed'] ?? 0);

    $approved = 0;
    $checkedIn = 0;
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM staff_registrations WHERE event_id = :eid AND status = 'approved'"
        );
        $stmt->execute(['eid' => $eventId]);
        $approved = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT a.id) FROM attendance a
             INNER JOIN staff_registrations sr ON sr.id = a.registration_id
             WHERE sr.event_id = :eid"
        );
        $stmt->execute(['eid' => $eventId]);
        $checkedIn = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        // optional
    }

    $assigned  = 0;
    $confirmed = 0;
    if (tableExists($pdo, 'event_roster_assignments')) {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*), SUM(CASE WHEN a.assignment_status IN ('confirmed','checked_in') THEN 1 ELSE 0 END)
                 FROM event_roster_assignments a
                 INNER JOIN event_roster_slots s ON s.id = a.slot_id
                 WHERE s.event_id = :eid"
            );
            $stmt->execute(['eid' => $eventId]);
            $row = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
            $assigned  = (int) ($row[0] ?? 0);
            $confirmed = (int) ($row[1] ?? 0);
        } catch (Throwable $e) {
            $assigned  = $approved;
            $confirmed = $approved;
        }
    } else {
        $assigned  = $approved;
        $confirmed = $approved;
    }

    $required = $needed > 0 ? $needed : max($approved, $assigned);
    $gap      = max(0, $required - max($assigned, $approved));

    return [
        'required'   => $required,
        'assigned'   => max($assigned, $approved),
        'confirmed'  => $confirmed,
        'checked_in' => $checkedIn,
        'gap'        => $gap,
    ];
}

function roster_ensure_default_slots(PDO $pdo, int $eventId): void
{
    if (!tableExists($pdo, 'event_roster_slots') || $eventId < 1) {
        return;
    }

    $existing = roster_get_slots($pdo, $eventId);
    if ($existing !== []) {
        return;
    }

    $event = getEventById($pdo, $eventId);
    if (!$event) {
        return;
    }

    $needed = max(1, (int) ($event['staff_needed'] ?? 1));
    $roles  = array_filter(array_map('trim', explode(',', (string) ($event['roles_needed'] ?? 'General'))));
    if ($roles === []) {
        $roles = ['General'];
    }

    $sort = 0;
    foreach ($roles as $role) {
        $stmt = $pdo->prepare(
            'INSERT INTO event_roster_slots (event_id, role_name, shift_label, shift_start, shift_end, slots_needed, sort_order)
             VALUES (:eid, :role, :label, :start, :end, :needed, :sort)'
        );
        $stmt->execute([
            'eid'    => $eventId,
            'role'   => $role,
            'label'  => 'Main shift',
            'start'  => $event['start_time'] ?? null,
            'end'    => $event['end_time'] ?? null,
            'needed' => (int) ceil($needed / count($roles)),
            'sort'   => $sort++,
        ]);
    }
}

/** @return list<array<string, mixed>> */
function roster_available_staff(PDO $pdo, int $eventId): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT sr.id AS registration_id, sr.staff_id, sr.first_name, sr.surname, sr.email, sr.staff_role, sr.status,
                    sr.shift_response
             FROM staff_registrations sr
             WHERE sr.event_id = :eid AND sr.status = 'approved'
             ORDER BY sr.surname, sr.first_name"
        );
        $stmt->execute(['eid' => $eventId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function roster_assign_staff(PDO $pdo, int $slotId, int $registrationId): bool
{
    if (!tableExists($pdo, 'event_roster_assignments') || $slotId < 1 || $registrationId < 1) {
        return false;
    }

    try {
        $reg = $pdo->prepare('SELECT staff_id FROM staff_registrations WHERE id = :id LIMIT 1');
        $reg->execute(['id' => $registrationId]);
        $staffId = $reg->fetchColumn();

        $stmt = $pdo->prepare(
            'INSERT INTO event_roster_assignments (slot_id, registration_id, staff_id, assignment_status)
             VALUES (:slot, :reg, :staff, \'assigned\')
             ON DUPLICATE KEY UPDATE staff_id = VALUES(staff_id), assignment_status = \'assigned\''
        );

        return $stmt->execute([
            'slot'  => $slotId,
            'reg'   => $registrationId,
            'staff' => $staffId ?: null,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function roster_unassign(PDO $pdo, int $assignmentId): bool
{
    if (!tableExists($pdo, 'event_roster_assignments') || $assignmentId < 1) {
        return false;
    }

    try {
        return $pdo->prepare('DELETE FROM event_roster_assignments WHERE id = :id')->execute(['id' => $assignmentId]);
    } catch (Throwable $e) {
        return false;
    }
}

/** Auto-fill vacancies from approved staff not yet assigned. */
function roster_auto_fill(PDO $pdo, int $eventId): int
{
    if (!tableExists($pdo, 'event_roster_assignments') || $eventId < 1) {
        return 0;
    }

    roster_ensure_default_slots($pdo, $eventId);
    $slots = roster_get_slots($pdo, $eventId);
    $staff = roster_available_staff($pdo, $eventId);

    $assignedRegs = array_map(
        static fn (array $a): int => (int) ($a['registration_id'] ?? 0),
        roster_get_assignments($pdo, $eventId)
    );

    $pool = array_values(array_filter(
        $staff,
        static fn (array $s): bool => !in_array((int) ($s['registration_id'] ?? 0), $assignedRegs, true)
    ));

    $filled = 0;
    foreach ($slots as $slot) {
        $slotId = (int) ($slot['id'] ?? 0);
        $need   = (int) ($slot['slots_needed'] ?? 1);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM event_roster_assignments WHERE slot_id = :sid');
        $stmt->execute(['sid' => $slotId]);
        $current = (int) $stmt->fetchColumn();
        $open    = max(0, $need - $current);

        for ($i = 0; $i < $open && $pool !== []; $i++) {
            $pick = array_shift($pool);
            if (roster_assign_staff($pdo, $slotId, (int) ($pick['registration_id'] ?? 0))) {
                $filled++;
            }
        }
    }

    return $filled;
}

function roster_move_assignment(PDO $pdo, int $assignmentId, int $targetSlotId): bool
{
    if (!tableExists($pdo, 'event_roster_assignments') || $assignmentId < 1 || $targetSlotId < 1) {
        return false;
    }

    try {
        return $pdo->prepare(
            'UPDATE event_roster_assignments SET slot_id = :slot WHERE id = :id'
        )->execute(['slot' => $targetSlotId, 'id' => $assignmentId]);
    } catch (Throwable $e) {
        return false;
    }
}
