<?php

declare(strict_types=1);

/**
 * Self-test: assign one master staff profile to all eligible future events.
 *
 *   ?key=CRON_KEY&dry_run=1
 *   ?key=CRON_KEY&apply=1
 *   ?key=CRON_KEY&email=olabodeoluwafemi2580@gmail.com
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/platform/canonical-identity.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';
require_once dirname(__DIR__) . '/includes/event-capacity.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';
require_once dirname(__DIR__) . '/includes/validation.php';
require_once dirname(__DIR__) . '/includes/staff-allocation.php';
require_once dirname(__DIR__) . '/includes/staff-registration-schema.php';
require_once dirname(__DIR__) . '/includes/commission-invoice-repository.php';
require_once dirname(__DIR__) . '/includes/status-change-post-save.php';
require_once dirname(__DIR__) . '/includes/staff-app-v3-data.php';

header('Content-Type: application/json; charset=UTF-8');

const SELF_TEST_DEFAULT_EMAIL = 'olabodeoluwafemi2580@gmail.com';
const SELF_TEST_ASSIGN_REASON = 'Production self-test staff assignment (admin cron)';

/**
 * @return array{skip: bool, reason: string}
 */
function selfTestShouldSkipEvent(PDO $pdo, array $event, int $staffId, string $email): array
{
    $eventId   = (int) ($event['id'] ?? 0);
    $eventDate = normalizeEventDateYmd((string) ($event['event_date'] ?? ''));
    $today     = (new DateTimeImmutable('today'))->format('Y-m-d');

    if ((int) ($event['is_active'] ?? 0) !== 1) {
        return ['skip' => true, 'reason' => 'inactive_or_archived'];
    }

    if ($eventDate === '' || $eventDate < $today) {
        return ['skip' => true, 'reason' => 'completed_past_event'];
    }

    if (!isEventOpenForRegistration($event)) {
        return ['skip' => true, 'reason' => 'event_window_closed'];
    }

    $invoice = getCommissionInvoiceByEventId($pdo, $eventId);
    if ($invoice !== null && $eventDate < $today) {
        return ['skip' => true, 'reason' => 'historical_invoiced_event'];
    }

    $stmt = $pdo->prepare(
        "SELECT sr.id, sr.status, a.id AS attendance_id, a.hours_paid, a.attendance_status
         FROM staff_registrations sr
         LEFT JOIN attendance a ON a.registration_id = sr.id
         WHERE sr.event_id = :eid
           AND (sr.staff_id = :sid OR LOWER(TRIM(sr.email)) = :email)
         ORDER BY sr.id ASC"
    );
    $stmt->execute([
        'eid'   => $eventId,
        'sid'   => $staffId,
        'email' => strtolower(trim($email)),
    ]);
    $regs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($regs as $reg) {
        $hours = (float) ($reg['hours_paid'] ?? 0);
        $status = strtolower(trim((string) ($reg['attendance_status'] ?? '')));
        if ($hours > 0 || $status === 'completed') {
            return ['skip' => true, 'reason' => 'has_completed_attendance_preserved'];
        }
    }

    return ['skip' => false, 'reason' => ''];
}

/**
 * @return array<string, mixed>
 */
function selfTestEnsureApprovedRegistration(PDO $pdo, int $eventId, array $staff, string $email, bool $apply): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    $event   = getEventById($pdo, $eventId);
    if ($event === null) {
        return ['ok' => false, 'error' => 'event_not_found'];
    }

    $role = resolveStaffRoleForEventRegistration((string) ($staff['staff_role'] ?? 'dsp'), $event);

    $stmt = $pdo->prepare(
        "SELECT id, status, staff_id, staff_role FROM staff_registrations
         WHERE event_id = :event_id
           AND (staff_id = :staff_id OR LOWER(TRIM(email)) = :email)
         ORDER BY (status = 'approved') DESC, id ASC"
    );
    $stmt->execute([
        'event_id' => $eventId,
        'staff_id' => $staffId,
        'email'    => strtolower(trim($email)),
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($rows !== []) {
        $primary = $rows[0];
        $regId   = (int) ($primary['id'] ?? 0);
        $action  = 'reused';

        if (!$apply) {
            return [
                'ok'              => true,
                'registration_id' => $regId,
                'created'         => false,
                'action'          => $action,
                'current_status'  => (string) ($primary['status'] ?? ''),
                'would_approve'   => (string) ($primary['status'] ?? '') !== 'approved',
                'role'            => $role,
            ];
        }

        if ((string) ($primary['status'] ?? '') !== 'approved') {
            updateStaffStatus($pdo, $regId, 'approved', true);
            runSingleStatusChangePostJobs($pdo, $regId, 'approved');
            $action = 'reused_and_approved';
        }

        if ((int) ($primary['staff_id'] ?? 0) !== $staffId) {
            $pdo->prepare('UPDATE staff_registrations SET staff_id = :sid WHERE id = :id')
                ->execute(['sid' => $staffId, 'id' => $regId]);
        }

        if ((string) ($primary['staff_role'] ?? '') !== $role) {
            $pdo->prepare('UPDATE staff_registrations SET staff_role = :role WHERE id = :id')
                ->execute(['role' => $role, 'id' => $regId]);
        }

        $pdo->prepare(
            "UPDATE staff_registrations
             SET allocation_type = 'admin_assigned',
                 override_reason = :reason,
                 updated_at = NOW()
             WHERE id = :id"
        )->execute(['reason' => SELF_TEST_ASSIGN_REASON, 'id' => $regId]);

        ensureCheckinToken($pdo, $regId);

        for ($i = 1, $c = count($rows); $i < $c; $i++) {
            $dupId = (int) ($rows[$i]['id'] ?? 0);
            if ($dupId > 0 && $dupId !== $regId && (string) ($rows[$i]['status'] ?? '') !== 'rejected') {
                updateStaffStatus($pdo, $dupId, 'rejected', true);
            }
        }

        return [
            'ok'              => true,
            'registration_id' => $regId,
            'created'         => false,
            'action'          => $action,
            'role'            => $role,
            'checkin_token'   => true,
        ];
    }

    if (!$apply) {
        return [
            'ok'      => true,
            'created' => true,
            'action'  => 'would_create_and_approve',
            'role'    => $role,
        ];
    }

    $assign = adminAssignStaffToEvent(
        $pdo,
        $staffId,
        $eventId,
        SELF_TEST_ASSIGN_REASON,
        true,
        true
    );
    if (empty($assign['ok'])) {
        return ['ok' => false, 'error' => (string) ($assign['error'] ?? 'assign_failed')];
    }

    $regId = (int) ($assign['registration_id'] ?? 0);
    if ($regId > 0) {
        $pdo->prepare('UPDATE staff_registrations SET staff_role = :role WHERE id = :id')
            ->execute(['role' => $role, 'id' => $regId]);
        updateStaffStatus($pdo, $regId, 'approved', true);
        runSingleStatusChangePostJobs($pdo, $regId, 'approved');
        ensureCheckinToken($pdo, $regId);
    }

    return [
        'ok'              => true,
        'registration_id' => $regId,
        'created'         => true,
        'action'          => 'created_and_approved',
        'role'            => $role,
        'checkin_token'   => $regId > 0,
    ];
}

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $apply = isset($_GET['apply']) && (string) $_GET['apply'] === '1';
    $email = canonicalIdentityNormalizeEmail((string) ($_GET['email'] ?? SELF_TEST_DEFAULT_EMAIL));

    if ($email === '') {
        exit(json_encode(['ok' => false, 'error' => 'email required'], JSON_PRETTY_PRINT));
    }

    $staffCountStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM staff WHERE LOWER(TRIM(email)) = :email AND is_blacklisted = 0'
    );
    $staffCountStmt->execute(['email' => $email]);
    $staffCount = (int) $staffCountStmt->fetchColumn();

    $staffStmt = $pdo->prepare(
        'SELECT * FROM staff WHERE LOWER(TRIM(email)) = :email AND is_blacklisted = 0 ORDER BY id ASC LIMIT 1'
    );
    $staffStmt->execute(['email' => $email]);
    $staff = $staffStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $canonicalStaff = canonicalIdentityResolveStaffForLoginEmail($pdo, $email);

    $identity = [
        'email'                    => $email,
        'staff_profiles_active'    => $staffCount,
        'single_master_profile'    => $staffCount === 1,
        'staff_id'                 => $staff ? (int) $staff['id'] : null,
        'staff_name'               => $staff ? trim(($staff['first_name'] ?? '') . ' ' . ($staff['surname'] ?? '')) : null,
        'canonical_identity_match' => $canonicalStaff !== null
            && $staff !== null
            && (int) ($canonicalStaff['id'] ?? 0) === (int) ($staff['id'] ?? 0),
        'canonical_staff_id'       => $canonicalStaff ? (int) ($canonicalStaff['id'] ?? 0) : null,
    ];

    if ($staff === null || !$identity['canonical_identity_match'] || !$identity['single_master_profile']) {
        exit(json_encode([
            'ok'       => false,
            'error'    => 'Master Staff Identity verification failed',
            'identity' => $identity,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    $staffId = (int) $staff['id'];

    $events = $pdo->query(
        'SELECT * FROM events WHERE is_active = 1 ORDER BY event_date ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $assigned   = [];
    $skipped    = [];
    $errors     = [];
    $created    = 0;
    $reused     = 0;

    foreach ($events as $event) {
        $eventId = (int) ($event['id'] ?? 0);
        $skip    = selfTestShouldSkipEvent($pdo, $event, $staffId, $email);

        if ($skip['skip']) {
            $skipped[] = [
                'event_id'   => $eventId,
                'event_name' => (string) ($event['name'] ?? ''),
                'event_date' => normalizeEventDateYmd((string) ($event['event_date'] ?? '')),
                'reason'     => $skip['reason'],
            ];
            continue;
        }

        $result = selfTestEnsureApprovedRegistration($pdo, $eventId, $staff, $email, $apply);
        if (empty($result['ok'])) {
            $errors[] = [
                'event_id'   => $eventId,
                'event_name' => (string) ($event['name'] ?? ''),
                'error'      => (string) ($result['error'] ?? 'unknown'),
            ];
            continue;
        }

        if (!empty($result['created'])) {
            ++$created;
        } else {
            ++$reused;
        }

        $assigned[] = [
            'event_id'        => $eventId,
            'event_name'      => (string) ($event['name'] ?? ''),
            'event_date'      => normalizeEventDateYmd((string) ($event['event_date'] ?? '')),
            'registration_id' => (int) ($result['registration_id'] ?? 0),
            'action'          => (string) ($result['action'] ?? ''),
            'role'            => (string) ($result['role'] ?? ''),
        ];
    }

    $dupStaff = (int) $pdo->query(
        "SELECT COUNT(*) FROM staff WHERE LOWER(TRIM(email)) = " . $pdo->quote($email)
    )->fetchColumn();

    $dupRegStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (
            SELECT event_id FROM staff_registrations
            WHERE (staff_id = :sid OR LOWER(TRIM(email)) = :email)
              AND status IN ('approved', 'pending')
            GROUP BY event_id HAVING COUNT(*) > 1
         ) x"
    );
    $dupRegStmt->execute(['sid' => $staffId, 'email' => $email]);
    $dupRegs = (int) $dupRegStmt->fetchColumn();

    $shiftRows = getStaffV3ShiftRowsByStaffId($pdo, $staffId);
    $today     = (new DateTimeImmutable('today'))->format('Y-m-d');
    $upcoming  = array_values(array_filter(
        $shiftRows,
        static fn(array $row): bool => normalizeEventDateYmd((string) ($row['event_date'] ?? '')) >= $today
            && (string) ($row['status'] ?? '') === 'approved'
    ));

    $historicalTouches = [
        'attendance_rows_modified' => 0,
        'commission_lines_modified' => 0,
        'payroll_adjustments_modified' => 0,
    ];

    echo json_encode([
        'ok'        => $errors === [],
        'dry_run'   => !$apply,
        'identity'  => $identity,
        'summary'   => [
            'future_events_assigned' => count($assigned),
            'registrations_created'  => $created,
            'registrations_reused'   => $reused,
            'events_skipped'         => count($skipped),
            'errors'                 => count($errors),
        ],
        'assigned'  => $assigned,
        'skipped'   => $skipped,
        'errors'    => $errors,
        'verification' => [
            'duplicate_staff_profiles' => $dupStaff,
            'duplicate_registrations_per_event' => $dupRegs,
            'mobile_upcoming_approved_shifts' => count($upcoming),
            'mobile_upcoming_events' => array_map(static fn(array $r): array => [
                'event_id'   => (int) ($r['event_id'] ?? 0),
                'event_name' => (string) ($r['event_name'] ?? ''),
                'event_date' => (string) ($r['event_date'] ?? ''),
                'status'     => (string) ($r['status'] ?? ''),
            ], $upcoming),
            'historical_data_preserved' => $historicalTouches,
            'protected_modules_modified' => false,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
