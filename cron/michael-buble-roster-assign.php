<?php

declare(strict_types=1);

/**
 * Michael Bublé (Thomond Park) — final roster assignment on existing production event.
 *
 * Dry run (default):
 *   /cron/michael-buble-roster-assign.php?key=...
 *
 * Apply roster + Google Sheets sync:
 *   /cron/michael-buble-roster-assign.php?key=...&dry_run=0&apply=1
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';
require_once dirname(__DIR__) . '/includes/validation.php';
require_once dirname(__DIR__) . '/includes/staff-allocation.php';
require_once dirname(__DIR__) . '/includes/status-change-post-save.php';
require_once dirname(__DIR__) . '/includes/google-sheets-sync.php';
require_once dirname(__DIR__) . '/includes/apply-remote-sync.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';

header('Content-Type: application/json; charset=UTF-8');

/** @var list<array{label: string, first: string, surname: string, hints?: list<string>}> */
const ROSTER = [
    ['label' => 'Salim Abukar Mursal', 'first' => 'Salim', 'surname' => 'Mursal', 'hints' => ['Abukar', 'Milaasyarow']],
    ['label' => 'Ahmed Ali Saiid', 'first' => 'Ahmed ali', 'surname' => 'Saiad', 'hints' => ['Saiid', 'Sayid']],
    ['label' => 'Chinomso Paschaline Aguh', 'first' => 'Chinomso', 'surname' => 'Aguh', 'hints' => ['Paschaline', 'chinomsoaguh']],
    ['label' => 'Maureen Chigozie Agwuna', 'first' => 'Maureen', 'surname' => 'Agwuna', 'hints' => ['Chigozie']],
    ['label' => 'Samson Victor Faboade', 'first' => 'Samson', 'surname' => 'Faboade', 'hints' => ['Samsun', 'Victor']],
    ['label' => 'Dare Adeelaja', 'first' => 'Dare', 'surname' => 'Adelaja', 'hints' => ['Adeelaja', 'darea9775']],
    ['label' => 'Roy Ajibade', 'first' => 'Roy', 'surname' => 'Ajibade', 'hints' => ['AJibade', 'Ajibaee']],
    ['label' => 'Godwin Igbinedion', 'first' => 'Godwin', 'surname' => 'Igbinedion', 'hints' => ['godwyne101']],
    ['label' => 'Oladimeji Samson Kuku', 'first' => 'Oladimeji', 'surname' => 'Kuku', 'hints' => ['samson Kuku', 'omoiyaladi']],
    ['label' => 'Rafiu Salau', 'first' => 'Rafiu', 'surname' => 'Salau', 'hints' => ['rafiusalau']],
];

function mbAuthorize(PDO $pdo): void
{
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT);
        exit;
    }
}

function mbResolveEvent(PDO $pdo): ?array
{
    $stmt = $pdo->query(
        "SELECT e.*, v.name AS venue_name
         FROM events e
         LEFT JOIN venues v ON v.id = e.venue_id
         WHERE e.name LIKE '%Michael Bubl%'
           AND (
             LOWER(COALESCE(e.location, '')) LIKE '%thomond%'
             OR LOWER(COALESCE(e.location, '')) LIKE '%thomas%'
             OR LOWER(COALESCE(v.name, '')) LIKE '%thomond%'
             OR LOWER(COALESCE(v.name, '')) LIKE '%thomas%'
           )
         ORDER BY e.event_date ASC, e.id ASC
         LIMIT 1"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @return list<array<string, mixed>>
 */
function mbSearchEventRegistrations(PDO $pdo, int $eventId, array $entry): array
{
    $first   = trim((string) ($entry['first'] ?? ''));
    $surname = trim((string) ($entry['surname'] ?? ''));
    $label   = trim((string) ($entry['label'] ?? ''));

    $stmt = $pdo->prepare(
        "SELECT sr.id AS registration_id, sr.staff_id, sr.first_name, sr.surname, sr.email,
                sr.staff_role, sr.status, sr.mobile, sr.pps_number
         FROM staff_registrations sr
         WHERE sr.event_id = :event_id
           AND (
             (LOWER(sr.surname) LIKE LOWER(:s1) AND LOWER(sr.first_name) LIKE LOWER(:f1))
             OR (LOWER(sr.surname) LIKE LOWER(:f2) AND LOWER(sr.first_name) LIKE LOWER(:s2))
             OR LOWER(CONCAT(sr.first_name, ' ', sr.surname)) LIKE LOWER(:full)
             OR LOWER(CONCAT(sr.surname, ' ', sr.first_name)) LIKE LOWER(:full2)
           )
         ORDER BY (sr.status = 'approved') DESC, sr.id ASC
         LIMIT 5"
    );
    $stmt->execute([
        'event_id' => $eventId,
        's1'       => '%' . $surname . '%',
        'f1'       => '%' . $first . '%',
        'f2'       => '%' . $surname . '%',
        's2'       => '%' . $first . '%',
        'full'     => '%' . str_replace(' ', '%', $label) . '%',
        'full2'    => '%' . str_replace(' ', '%', $label) . '%',
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function mbNormalizeName(string $value): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $entry
 */
function mbScoreNameMatch(array $row, array $entry): int
{
    $first   = mbNormalizeName((string) ($entry['first'] ?? ''));
    $surname = mbNormalizeName((string) ($entry['surname'] ?? ''));
    $label   = mbNormalizeName((string) ($entry['label'] ?? ''));
    $hints   = $entry['hints'] ?? [];

    $rowFirst   = mbNormalizeName((string) ($row['first_name'] ?? ''));
    $rowSurname = mbNormalizeName((string) ($row['surname'] ?? ''));
    $rowFull    = trim($rowFirst . ' ' . $rowSurname);

    $score = 0;

    if ($rowSurname === $surname) {
        $score += 60;
    } elseif ($surname !== '' && (str_contains($rowSurname, $surname) || str_contains($surname, $rowSurname))) {
        $score += 35;
    } else {
        return 0;
    }

    if ($rowFirst === $first) {
        $score += 40;
    } elseif ($first !== '' && (str_contains($rowFirst, $first) || str_contains($first, $rowFirst))) {
        $score += 25;
    }

    foreach (preg_split('/\s+/', $first, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
        if (strlen($token) >= 3 && str_contains($rowFull, $token)) {
            $score += 8;
        }
    }

    if ($label !== '' && str_contains($rowFull, $label)) {
        $score += 30;
    }

    foreach (is_array($hints) ? $hints : [] as $hint) {
        $hintNorm = mbNormalizeName((string) $hint);
        if ($hintNorm !== '' && str_contains($rowFull, $hintNorm)) {
            $score += 12;
        }
    }

    if ((int) ($row['profile_completed'] ?? 0) === 1) {
        $score += 5;
    }

    return $score;
}

/**
 * @return list<array<string, mixed>>
 */
function mbSearchStaff(PDO $pdo, int $eventId, array $entry): array
{
    $first   = trim((string) ($entry['first'] ?? ''));
    $surname = trim((string) ($entry['surname'] ?? ''));
    $label   = trim((string) ($entry['label'] ?? ''));

    $matches = [];
    $seen    = [];

    $sql = "SELECT id, first_name, surname, email, mobile, pps_number, psa_licence, staff_role, profile_completed, created_at
            FROM staff
            WHERE (
                (LOWER(surname) LIKE LOWER(:s1) AND LOWER(first_name) LIKE LOWER(:f1))
                OR (LOWER(surname) LIKE LOWER(:f2) AND LOWER(first_name) LIKE LOWER(:s2))
                OR LOWER(CONCAT(first_name, ' ', surname)) LIKE LOWER(:full)
                OR LOWER(CONCAT(surname, ' ', first_name)) LIKE LOWER(:full2)
            )
            ORDER BY id ASC
            LIMIT 20";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        's1'    => '%' . $surname . '%',
        'f1'    => '%' . $first . '%',
        'f2'    => '%' . $surname . '%',
        's2'    => '%' . $first . '%',
        'full'  => '%' . str_replace(' ', '%', $label) . '%',
        'full2' => '%' . str_replace(' ', '%', $label) . '%',
    ]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0 && !isset($seen[$id])) {
            $seen[$id] = true;
            $row['_score'] = mbScoreNameMatch($row, $entry);
            if ((int) $row['_score'] >= 45) {
                $matches[] = $row;
            }
        }
    }

    foreach (mbSearchEventRegistrations($pdo, $eventId, $entry) as $reg) {
        $staffId = (int) ($reg['staff_id'] ?? 0);
        if ($staffId > 0) {
            $staff = getStaffById($pdo, $staffId);
            if ($staff !== null && !isset($seen[$staffId])) {
                $seen[$staffId] = true;
                $staff['_score'] = mbScoreNameMatch($staff, $entry) + 25;
                if ((int) $staff['_score'] >= 45) {
                    $matches[] = $staff;
                }
            }
        }
    }

    usort($matches, static function (array $a, array $b): int {
        return ((int) ($b['_score'] ?? 0)) <=> ((int) ($a['_score'] ?? 0));
    });

    return $matches;
}

function mbPickMasterStaff(array $matches): ?array
{
    if ($matches === []) {
        return null;
    }

    return $matches[0];
}

function mbRoleLabel(string $role): string
{
    return in_array(normalizeStaffRole($role), ['steward'], true) ? 'Steward' : 'PSA Holder';
}

/**
 * @return array{registration_id: int, created: bool, duplicate_skipped?: bool}
 */
function mbEnsureApprovedRegistration(PDO $pdo, int $eventId, array $staff, bool $dryRun): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    $email   = strtolower(trim((string) ($staff['email'] ?? '')));

    $stmt = $pdo->prepare(
        'SELECT id, status, staff_id FROM staff_registrations
         WHERE event_id = :event_id
           AND (staff_id = :staff_id OR (staff_id IS NULL AND LOWER(email) = :email) OR LOWER(email) = :email2)
         ORDER BY (status = \'approved\') DESC, id ASC'
    );
    $stmt->execute([
        'event_id' => $eventId,
        'staff_id' => $staffId,
        'email'    => $email,
        'email2'   => $email,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($rows !== []) {
        $primary = $rows[0];
        $regId   = (int) ($primary['id'] ?? 0);
        $skipped = count($rows) - 1;

        if (!$dryRun && $regId > 0) {
            if ((string) ($primary['status'] ?? '') !== 'approved') {
                updateStaffStatus($pdo, $regId, 'approved', true);
            }
            if ((int) ($primary['staff_id'] ?? 0) !== $staffId && $staffId > 0) {
                $pdo->prepare('UPDATE staff_registrations SET staff_id = :sid WHERE id = :id')
                    ->execute(['sid' => $staffId, 'id' => $regId]);
            }
            ensureCheckinToken($pdo, $regId);
            for ($i = 1, $c = count($rows); $i < $c; $i++) {
                $dupId = (int) ($rows[$i]['id'] ?? 0);
                if ($dupId > 0 && $dupId !== $regId) {
                    updateStaffStatus($pdo, $dupId, 'rejected', true);
                }
            }
        }

        return [
            'registration_id'   => $regId,
            'created'           => false,
            'duplicate_skipped' => $skipped > 0 ? $skipped : 0,
        ];
    }

    if ($dryRun) {
        return ['registration_id' => 0, 'created' => true, 'would_create' => true];
    }

    $assign = adminAssignStaffToEvent(
        $pdo,
        $staffId,
        $eventId,
        'Michael Bublé Thomond Park final roster assignment',
        true,
        true
    );
    if (empty($assign['ok'])) {
        return ['registration_id' => 0, 'created' => false, 'error' => (string) ($assign['error'] ?? 'assign failed')];
    }

    $regId = (int) ($assign['registration_id'] ?? 0);
    if ($regId > 0) {
        updateStaffStatus($pdo, $regId, 'approved', true);
        ensureCheckinToken($pdo, $regId);
    }

    return ['registration_id' => $regId, 'created' => true];
}

/**
 * @return array{pending_checkin: bool, attendance_id: int|null, note: string}
 */
function mbEnsurePendingAttendance(PDO $pdo, int $registrationId, bool $dryRun): array
{
    $att = getAttendanceByRegistration($pdo, $registrationId);
    if ($att === null) {
        return [
            'pending_checkin' => true,
            'attendance_id'   => null,
            'note'            => 'No attendance row — pending check-in until mobile/GPS sign-in.',
        ];
    }

    $status = strtolower(trim((string) ($att['attendance_status'] ?? '')));
    $hours  = (float) ($att['hours_paid'] ?? 0);
    $out    = [
        'pending_checkin' => !in_array($status, ['completed'], true) && $hours <= 0,
        'attendance_id'   => (int) ($att['id'] ?? 0),
        'attendance_status' => $status,
        'hours_paid'      => $hours,
        'note'            => '',
    ];

    if ($status === 'completed' || $hours > 0) {
        $out['note'] = 'Existing completed attendance preserved (not modified).';
        $out['pending_checkin'] = false;
    } else {
        $out['note'] = 'Attendance exists — awaiting check-in/checkout.';
        $out['pending_checkin'] = true;
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function mbListEventRegistrations(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare(
        "SELECT sr.id, sr.staff_id, sr.first_name, sr.surname, sr.email, sr.status, sr.staff_role,
                a.id AS attendance_id, a.attendance_status, a.hours_paid
         FROM staff_registrations sr
         LEFT JOIN attendance a ON a.registration_id = sr.id
         WHERE sr.event_id = :event_id
         ORDER BY sr.status DESC, sr.surname ASC, sr.first_name ASC"
    );
    $stmt->execute(['event_id' => $eventId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

try {
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }

    $pdo     = getDB();
    mbAuthorize($pdo);

    $dryRun = !isset($_GET['dry_run']) || (string) $_GET['dry_run'] !== '0';
    $apply  = isset($_GET['apply']) && (string) $_GET['apply'] === '1';

    $event = mbResolveEvent($pdo);
    if ($event === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Michael Bublé Thomond Park event not found.'], JSON_PRETTY_PRINT);
        exit;
    }

    $eventId = (int) ($event['id'] ?? 0);

    if (!$dryRun && $apply && (int) ($event['is_active'] ?? 0) !== 1) {
        $pdo->prepare('UPDATE events SET is_active = 1 WHERE id = :id')->execute(['id' => $eventId]);
        $event['is_active'] = 1;
    }

    $assigned          = [];
    $manualReview      = [];
    $duplicateSkipped  = 0;
    $attendancePending = 0;
    $errors            = [];

    $rosterStaffIds = [];
    $rosterEmails   = [];

    foreach (ROSTER as $entry) {
        $label   = (string) ($entry['label'] ?? '');
        $matches = mbSearchStaff($pdo, $eventId, $entry);
        $master  = mbPickMasterStaff($matches);

        if ($master === null) {
            $eventRegs = mbSearchEventRegistrations($pdo, $eventId, $entry);
            $manualReview[] = [
                'roster_name' => $label,
                'reason'      => 'Not found in production staff (no new record created)',
                'matches'     => count($matches),
                'event_registration_hints' => array_map(static fn (array $r): string => trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['surname'] ?? '')), $eventRegs),
            ];
            continue;
        }

        if (count($matches) > 1) {
            $duplicateSkipped++;
        }

        $staffId = (int) ($master['id'] ?? 0);
        $email   = strtolower(trim((string) ($master['email'] ?? '')));
        if ($staffId > 0) {
            $rosterStaffIds[$staffId] = true;
        }
        if ($email !== '') {
            $rosterEmails[$email] = true;
        }

        $regResult = mbEnsureApprovedRegistration($pdo, $eventId, $master, $dryRun);
        if (!empty($regResult['error'])) {
            $errors[] = ['roster_name' => $label, 'error' => (string) $regResult['error']];
            continue;
        }

        if ($dryRun && !empty($regResult['would_create'])) {
            $assigned[] = [
                'roster_name'       => $label,
                'staff_id'          => $staffId,
                'matched_name'      => trim((string) ($master['first_name'] ?? '') . ' ' . (string) ($master['surname'] ?? '')),
                'email'             => $email,
                'role'              => mbRoleLabel((string) ($master['staff_role'] ?? '')),
                'registration_id'   => null,
                'action'            => 'would_create_approved_registration',
                'attendance'        => ['pending_checkin' => true, 'note' => 'Would remain pending until check-in'],
            ];
            continue;
        }

        $regId = (int) ($regResult['registration_id'] ?? 0);
        if ($regId < 1) {
            $manualReview[] = ['roster_name' => $label, 'reason' => 'Could not resolve registration'];
            continue;
        }

        if (!empty($regResult['duplicate_skipped'])) {
            $duplicateSkipped += (int) $regResult['duplicate_skipped'];
        }

        $att = mbEnsurePendingAttendance($pdo, $regId, $dryRun);
        if ($att['pending_checkin']) {
            $attendancePending++;
        }

        $assigned[] = [
            'roster_name'     => $label,
            'staff_id'        => $staffId,
            'matched_name'    => trim((string) ($master['first_name'] ?? '') . ' ' . (string) ($master['surname'] ?? '')),
            'email'           => $email,
            'role'            => mbRoleLabel((string) ($master['staff_role'] ?? '')),
            'registration_id' => $regId,
            'created'         => !empty($regResult['created']),
            'action'          => !empty($regResult['created']) ? 'registered_and_approved' : 'approved_existing',
            'attendance'      => $att,
        ];
    }

    $removed = [];
    $before  = mbListEventRegistrations($pdo, $eventId);

    foreach ($before as $row) {
        $regId   = (int) ($row['id'] ?? 0);
        $status  = (string) ($row['status'] ?? '');
        $staffId = (int) ($row['staff_id'] ?? 0);
        $email   = strtolower(trim((string) ($row['email'] ?? '')));
        $name    = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? ''));

        if (!in_array($status, ['approved', 'pending'], true)) {
            continue;
        }

        $onRoster = ($staffId > 0 && isset($rosterStaffIds[$staffId]))
            || ($email !== '' && isset($rosterEmails[$email]));

        if ($onRoster) {
            continue;
        }

        $removed[] = [
            'registration_id' => $regId,
            'staff_id'        => $staffId,
            'name'            => $name,
            'email'           => $email,
            'previous_status' => $status,
            'attendance_id'   => (int) ($row['attendance_id'] ?? 0) ?: null,
        ];

        if (!$dryRun) {
            updateStaffStatus($pdo, $regId, 'rejected', true);
        }
    }

    $validation = [
        'duplicate_staff_created'     => 0,
        'duplicate_registrations'     => 0,
        'orphan_attendance'           => 0,
        'orphan_commission'           => 0,
        'approved_roster_count'       => 0,
        'unexpected_approved'         => 0,
    ];

    $after = $dryRun ? $before : mbListEventRegistrations($pdo, $eventId);
    foreach ($after as $row) {
        if ((string) ($row['status'] ?? '') !== 'approved') {
            continue;
        }
        $staffId = (int) ($row['staff_id'] ?? 0);
        $email   = strtolower(trim((string) ($row['email'] ?? '')));
        $onRoster = ($staffId > 0 && isset($rosterStaffIds[$staffId]))
            || ($email !== '' && isset($rosterEmails[$email]));
        if ($onRoster) {
            $validation['approved_roster_count']++;
        } else {
            $validation['unexpected_approved']++;
        }
    }

    $dupStmt = $pdo->prepare(
        "SELECT staff_id, LOWER(email) AS email, COUNT(*) AS cnt
         FROM staff_registrations
         WHERE event_id = :event_id AND status = 'approved'
         GROUP BY staff_id, LOWER(email)
         HAVING cnt > 1"
    );
    $dupStmt->execute(['event_id' => $eventId]);
    foreach ($dupStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $dupRow) {
        $validation['duplicate_registrations'] += (int) ($dupRow['cnt'] ?? 0) - 1;
    }

    $orphAtt = $pdo->prepare(
        'SELECT COUNT(*) FROM attendance a
         LEFT JOIN staff_registrations sr ON sr.id = a.registration_id
         WHERE sr.id IS NULL'
    );
    $validation['orphan_attendance'] = (int) ($orphAtt->fetchColumn() ?: 0);

    if ($pdo->query("SHOW TABLES LIKE 'commission_invoice_lines'")->fetchColumn()) {
        $orphComm = $pdo->prepare(
            'SELECT COUNT(*) FROM commission_invoice_lines cil
             LEFT JOIN staff_registrations sr ON sr.id = cil.registration_id
             WHERE sr.id IS NULL'
        );
        $validation['orphan_commission'] = (int) ($orphComm->fetchColumn() ?: 0);
    }

    $googleSheets = null;
    $mobileSync   = ['status' => 'pending', 'note' => 'Dry run — no sync performed'];

    if (!$dryRun && $apply) {
        triggerApplyPortalSyncAsync($pdo, true);

        $applyUrl = rtrim(getSetting($pdo, 'apply_site_base_url', 'https://apply.olasentra.com'), '/')
            . '/admin/cron/sheets-cleanup-production.php?key=' . urlencode(trim((string) ($_GET['key'] ?? '')))
            . '&phase=sync&apply=1';

        $ctx = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 300, 'ignore_errors' => true],
        ]);
        $applyBody = @file_get_contents($applyUrl, false, $ctx);
        $applySync = is_string($applyBody) ? json_decode($applyBody, true) : null;

        $eventSheet = googleSheetsRebuildEventTab($pdo, $eventId, null, false);

        $googleSheets = [
            'apply_vault_payroll_psa_master' => is_array($applySync) ? [
                'ok'     => !empty($applySync['ok']),
                'status' => (string) ($applySync['google_sheets_status'] ?? $applySync['verification']['status'] ?? ''),
                'sync'   => $applySync['sync'] ?? null,
            ] : ['ok' => false, 'error' => 'Apply sync unreachable'],
            'event_sheet' => [
                'event_id' => $eventId,
                'ok'       => !empty($eventSheet['ok']),
                'rows'     => (int) ($eventSheet['rows'] ?? 0),
                'message'  => (string) ($eventSheet['message'] ?? ''),
            ],
        ];

        $mobileSync = [
            'status' => 'ready',
            'note'   => 'Approved registrations + check-in tokens; upcoming shifts visible when event_date >= today.',
            'approved_on_event' => $validation['approved_roster_count'],
        ];
    }

    $checksPass = (count($assigned) + count($manualReview)) === count(ROSTER)
        && $errors === []
        && $validation['unexpected_approved'] === 0
        && $validation['duplicate_registrations'] === 0
        && $validation['orphan_attendance'] === 0
        && $validation['orphan_commission'] === 0
        && (!$apply || $dryRun || (
            is_array($googleSheets)
            && !empty($googleSheets['event_sheet']['ok'])
            && !empty($googleSheets['apply_vault_payroll_psa_master']['ok'])
        ));

    $report = [
        'ok'        => true,
        'dry_run'   => $dryRun,
        'applied'   => !$dryRun && $apply,
        'event'     => [
            'id'           => $eventId,
            'name'         => (string) ($event['name'] ?? ''),
            'date'         => (string) ($event['event_date'] ?? ''),
            'venue'        => (string) ($event['venue_name'] ?? $event['location'] ?? ''),
            'start_time'   => (string) ($event['start_time'] ?? ''),
            'end_time'     => (string) ($event['end_time'] ?? ''),
            'is_active'    => (int) ($event['is_active'] ?? 0),
            'registrations_before' => count($before),
        ],
        'staff_assigned'              => $assigned,
        'staff_removed'               => $removed,
        'staff_not_found'             => $manualReview,
        'duplicate_registrations_skipped' => $duplicateSkipped,
        'mobile_sync_status'          => $mobileSync,
        'google_sheets_sync_status'   => $googleSheets,
        'attendance_records_created'  => 0,
        'attendance_pending_checkin'  => $attendancePending,
        'validation'                  => $validation,
        'errors'                      => $errors,
        'event_ready'                 => $checksPass && !$dryRun && $apply,
        'ready_message'               => ($checksPass && !$dryRun && $apply)
            ? 'MICHAEL BUBLÉ EVENT READY ✅'
            : ($dryRun ? 'Dry run complete — review then run with dry_run=0&apply=1' : 'Checks incomplete — review report'),
    ];

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
