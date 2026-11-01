<?php

declare(strict_types=1);

/**
 * Staff Sign Hub — staff self sign-in / sign-out (standalone app).
 * Method: staff_hub. Does not modify Manual sign-in or Attendant gate flows.
 */

require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/attendance-repository.php';
require_once __DIR__ . '/attendance-roster-helpers.php';
require_once __DIR__ . '/attendance-gps-phase1.php';
require_once __DIR__ . '/attendance-gps-phase15.php';
require_once __DIR__ . '/registration-bib.php';
require_once __DIR__ . '/attendant-actions.php';
require_once __DIR__ . '/maps-core.php';
require_once __DIR__ . '/date-format.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/staff-psa.php';

const STAFF_SIGN_HUB_METHOD = 'staff_hub';
const STAFF_SIGN_HUB_SIGNOUT_REASON = 'staff_hub_signout';

/**
 * @return list<array<string, mixed>>
 */
function staffSignHubEventsForDate(PDO $pdo, string $dateYmd): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
        return [];
    }

    // Full event rows (venue/end_time) — attendance filter only selects id/name/date.
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM events
             WHERE DATE(event_date) = :d
             ORDER BY name ASC'
        );
        $stmt->execute(['d' => $dateYmd]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows !== []) {
            return $rows;
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] staffSignHubEventsForDate: ' . $e->getMessage());
    }

    $out = [];
    foreach (getEventsForAttendanceFilter($pdo) as $ev) {
        $d = substr((string) ($ev['event_date'] ?? ''), 0, 10);
        if ($d === $dateYmd) {
            $full = getEventById($pdo, (int) ($ev['id'] ?? 0));
            $out[] = $full ?: $ev;
        }
    }

    return $out;
}

/**
 * Nearest event date on or after $fromYmd (for empty "today" bootstrap).
 */
function staffSignHubNextEventDate(PDO $pdo, string $fromYmd): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromYmd)) {
        return '';
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT DATE(event_date) AS d FROM events
             WHERE DATE(event_date) >= :d
             ORDER BY event_date ASC
             LIMIT 1'
        );
        $stmt->execute(['d' => $fromYmd]);
        $d = substr((string) ($stmt->fetchColumn() ?: ''), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * If name matches a non-approved registration on this event, explain why.
 *
 * @return array{ok: bool, message: string, matches: list<array<string, mixed>>, pending?: bool}
 */
function staffSignHubSearchExplain(PDO $pdo, int $eventId, string $nameQ): array
{
    $matches = staffSignHubFilterName(staffSignHubRoster($pdo, $eventId), $nameQ);
    $cards = [];
    foreach (array_slice($matches, 0, 12) as $row) {
        $cards[] = staffSignHubPublicCard($row);
    }

    if ($cards !== []) {
        return ['ok' => true, 'message' => '', 'matches' => $cards];
    }

    // Look for same name not yet approved on this event (pending / other status).
    $pendingHint = '';
    try {
        $stmt = $pdo->prepare(
            "SELECT first_name, surname, status, staff_role
             FROM staff_registrations
             WHERE event_id = :event_id
             ORDER BY surname ASC, first_name ASC
             LIMIT 200"
        );
        $stmt->execute(['event_id' => $eventId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $nameHits = staffSignHubFilterName($rows, $nameQ);
        if ($nameHits !== []) {
            $statuses = [];
            foreach ($nameHits as $hit) {
                $st = strtolower(trim((string) ($hit['status'] ?? '')));
                if ($st !== '' && $st !== 'approved') {
                    $statuses[$st] = true;
                }
            }
            if ($statuses !== []) {
                $label = implode('/', array_keys($statuses));
                $pendingHint = 'Name found on this event but status is "' . $label
                    . '" — admin must approve the signup before Staff Sign can use it.';

                return [
                    'ok'       => true,
                    'message'  => $pendingHint,
                    'matches'  => [],
                    'pending'  => true,
                ];
            }
        }
    } catch (Throwable $e) {
        // fall through
    }

    return [
        'ok'      => true,
        'message' => 'Your name must be on the approved signup for this particular event. '
            . 'Pick the correct event date, or ask admin to approve your registration for this event.',
        'matches' => [],
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function staffSignHubRoster(PDO $pdo, int $eventId): array
{
    if ($eventId < 1) {
        return [];
    }

    ensureAttendanceGateStartTime($pdo);
    $bibOn = registrationBibColumnEnabled($pdo);
    $bibCol = $bibOn ? 'sr.assigned_bib_number' : 'NULL AS assigned_bib_number';
    $startCol = 'NULL AS gate_start_time';
    try {
        if (ensureAttendanceGateStartTime($pdo)) {
            $startCol = 'a.gate_start_time';
        }
    } catch (Throwable $e) {
        $startCol = 'NULL AS gate_start_time';
    }

    // PSA lives on the staff profile table; registration columns are often empty.
    if (function_exists('ensureStaffPsaSchema')) {
        try {
            ensureStaffPsaSchema($pdo);
        } catch (Throwable $e) {
            // continue — merge still falls back to registration columns
        }
    }

    $regCols = [];
    try {
        $regCols = $pdo->query('SHOW COLUMNS FROM staff_registrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $regCols = [];
    }
    $has = static fn (string $c): bool => in_array($c, $regCols, true);

    $psaParts = [];
    $psaParts[] = $has('psa_licence') ? 'sr.psa_licence' : 'NULL AS psa_licence';
    $psaParts[] = $has('psa_expiry_date') ? 'sr.psa_expiry_date' : 'NULL AS psa_expiry_date';
    $psaParts[] = $has('psa_register_status') ? 'sr.psa_register_status' : 'NULL AS psa_register_status';
    $psaParts[] = $has('psa_register_name') ? 'sr.psa_register_name' : 'NULL AS psa_register_name';
    $psaCols = implode(', ', $psaParts);
    $staffIdCol = $has('staff_id') ? 'sr.staff_id' : 'NULL AS staff_id';

    $sql = "SELECT sr.id, sr.first_name, sr.surname, sr.mobile, sr.email, sr.staff_role,
                   {$staffIdCol}, {$bibCol}, {$psaCols},
                   a.id AS attendance_id, a.checked_in_at, a.checked_out_at,
                   a.checked_in_method, a.attendance_status, {$startCol},
                   e.name AS event_name, e.event_date, e.start_time, e.end_time,
                   e.venue_lat, e.venue_lng, e.signin_radius_m, e.venue_eircode
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            LEFT JOIN attendance a ON a.registration_id = sr.id
            WHERE sr.event_id = :event_id AND sr.status = 'approved'
            ORDER BY sr.surname ASC, sr.first_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['event_id' => $eventId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Prefer PSA from staff profile; keep event signup role (steward vs security).
    foreach ($rows as $i => $row) {
        $rows[$i] = staffSignHubMergeProfile($pdo, $row);
    }

    return $rows;
}

/**
 * @param list<array<string, mixed>> $roster
 * @return list<array<string, mixed>>
 */
function staffSignHubFilterName(array $roster, string $q): array
{
    $q = strtolower(trim($q));
    if ($q === '') {
        return [];
    }

    $matches = [];
    foreach ($roster as $row) {
        $first = trim((string) ($row['first_name'] ?? ''));
        $surname = trim((string) ($row['surname'] ?? ''));
        $full = strtolower(trim($first . ' ' . $surname));
        $rev = strtolower(trim($surname . ' ' . $first));
        if (str_contains($full, $q) || str_contains($rev, $q)) {
            $matches[] = $row;
        }
    }

    return $matches;
}

/**
 * Public-safe staff card (no email dump beyond what's needed for confirm).
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function staffSignHubIsStewardRole(string $role): bool
{
    return strtolower(trim($role)) === 'steward';
}

/**
 * Merge PSA/profile fields from staff, but keep this event's signup role.
 * Profile default (e.g. static) must not override event role (e.g. steward).
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function staffSignHubMergeProfile(PDO $pdo, array $row): array
{
    $eventRole = trim((string) ($row['staff_role'] ?? ''));
    $merged = mergeRegistrationWithStaff($pdo, $row);
    if ($eventRole !== '') {
        $merged['staff_role'] = $eventRole;
    }

    return $merged;
}

function staffSignHubPublicCard(array $row): array
{
    $checkedIn = !empty($row['checked_in_at']);
    $checkedOut = !empty($row['checked_out_at']);
    $endTime = trim((string) ($row['end_time'] ?? ''));
    $eventDate = substr((string) ($row['event_date'] ?? ''), 0, 10);
    $role = (string) ($row['staff_role'] ?? '');

    return [
        'registration_id'      => (int) ($row['id'] ?? 0),
        'first_name'           => (string) ($row['first_name'] ?? ''),
        'surname'              => (string) ($row['surname'] ?? ''),
        'name'                 => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? '')),
        'staff_role'           => $role,
        'is_steward'           => staffSignHubIsStewardRole($role),
        'psa_licence'          => (string) ($row['psa_licence'] ?? ''),
        'psa_expiry_date'      => (string) ($row['psa_expiry_date'] ?? ''),
        'psa_register_status'  => (string) ($row['psa_register_status'] ?? ''),
        'psa_register_name'    => (string) ($row['psa_register_name'] ?? ''),
        'psa_register_snapshot'=> (string) ($row['psa_register_snapshot'] ?? ''),
        'psa_details'          => null,
        // Never expose assigned bib to the self-serve app (no prefill / no lock UX).
        'assigned_bib'         => '',
        'attendance_id'        => (int) ($row['attendance_id'] ?? 0),
        'checked_in'           => $checkedIn,
        'checked_out'          => $checkedOut,
        'checked_in_at'        => (string) ($row['checked_in_at'] ?? ''),
        'checked_out_at'       => (string) ($row['checked_out_at'] ?? ''),
        'gate_start_time'      => (string) ($row['gate_start_time'] ?? ''),
        'event_name'           => (string) ($row['event_name'] ?? ''),
        'event_date'           => $eventDate,
        'event_end_time'       => $endTime,
        'signout_due_at'       => staffSignHubSignoutDueIso($eventDate, $endTime),
    ];
}

/**
 * Pull full official PSA licence details when a name is selected (not name-only).
 *
 * @param array<string, mixed> $card
 * @return array<string, mixed>
 */
/**
 * Fast gate for sign-in: use already-verified profile PSA without a second live
 * psa-gov.ie round-trip (that call often times out on mobile → empty JSON).
 *
 * @param array<string, mixed> $card
 * @return array<string, mixed>
 */
function staffSignHubAttachCachedPsaGate(array $card): array
{
    if (!empty($card['is_steward'])) {
        $card['psa_details'] = [
            'source'      => 'steward',
            'status'      => 'steward',
            'message'     => 'Steward — PSA licence not required.',
            'can_proceed' => true,
            'employee'    => null,
        ];
        $card['psa_can_proceed'] = true;

        return $card;
    }

    $licence = trim((string) ($card['psa_licence'] ?? ''));
    $holder  = trim((string) ($card['name'] ?? ''));
    $status  = strtolower(trim((string) ($card['psa_register_status'] ?? '')));

    if ($licence === '') {
        $card['psa_details'] = [
            'source'      => 'on_file',
            'status'      => 'missing',
            'message'     => 'No PSA licence number on file. Cannot sign in until PSA is on your profile.',
            'can_proceed' => false,
            'employee'    => null,
        ];
        $card['psa_can_proceed'] = false;

        return $card;
    }

    $verified = in_array($status, ['verified', 'ok', 'valid'], true);
    $card['psa_details'] = [
        'source'      => 'profile',
        'status'      => $verified ? 'verified' : ($status !== '' ? $status : 'on_file'),
        'message'     => $verified
            ? 'PSA on file is verified. Enter BIB, then sign in.'
            : 'PSA on file — confirm details on the previous screen, then sign in.',
        'can_proceed' => $verified || $licence !== '',
        'employee'    => [
            'licence_no'    => $licence,
            'employee_name' => (string) ($card['psa_register_name'] ?? $holder),
            'expiry_date'   => (string) ($card['psa_expiry_date'] ?? ''),
        ],
    ];
    // Allow sign-in when licence is on profile; live confirm happens on select.
    $card['psa_can_proceed'] = true;

    return $card;
}

function staffSignHubAttachFullPsaDetails(array $card): array
{
    if (!empty($card['is_steward'])) {
        $card['psa_details'] = [
            'source'       => 'steward',
            'status'       => 'steward',
            'message'      => 'Steward — PSA licence not required.',
            'can_proceed'  => true,
            'employee'     => null,
        ];
        $card['psa_can_proceed'] = true;

        return $card;
    }

    $licence = trim((string) ($card['psa_licence'] ?? ''));
    $holder  = trim((string) ($card['name'] ?? ''));

    if ($licence === '') {
        $card['psa_details'] = [
            'source'      => 'on_file',
            'status'      => 'missing',
            'message'     => 'No PSA licence number on file. Cannot sign in until PSA is on your profile.',
            'can_proceed' => false,
            'employee'    => null,
            'on_file'     => [
                'licence_no'      => '',
                'employee_name'   => $holder,
                'expiry_date'     => (string) ($card['psa_expiry_date'] ?? ''),
                'register_name'   => (string) ($card['psa_register_name'] ?? ''),
                'register_status' => (string) ($card['psa_register_status'] ?? ''),
            ],
        ];
        $card['psa_can_proceed'] = false;

        return $card;
    }

    // If profile already verified, use saved official snapshot (full County/Sectors/etc).
    $cachedStatus = strtolower(trim((string) ($card['psa_register_status'] ?? '')));
    if ($cachedStatus === 'verified') {
        require_once __DIR__ . '/psa-licence-verify.php';
        $expiry = (string) ($card['psa_expiry_date'] ?? '');
        $expiryDisplay = $expiry;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
            try {
                $expiryDisplay = (new DateTime($expiry))->format('d/m/Y');
            } catch (Throwable $e) {
                $expiryDisplay = $expiry;
            }
        }
        $nameOnFile = trim((string) ($card['psa_register_name'] ?? $holder));
        $snap = [];
        $rawSnap = trim((string) ($card['psa_register_snapshot'] ?? ''));
        if ($rawSnap !== '') {
            $decoded = json_decode($rawSnap, true);
            if (is_array($decoded)) {
                $snap = $decoded;
            }
        }
        $employee = [
            'title'         => trim((string) ($snap['title'] ?? '')) !== '' ? (string) $snap['title'] : $licence,
            'licence_no'    => trim((string) ($snap['licence_no'] ?? '')) !== '' ? (string) $snap['licence_no'] : $licence,
            'employee_name' => trim((string) ($snap['employee_name'] ?? '')) !== ''
                ? (string) $snap['employee_name']
                : ($nameOnFile !== '' ? $nameOnFile : $holder),
            'county'        => (string) ($snap['county'] ?? ''),
            'country'       => (string) (($snap['country'] ?? '') !== '' ? $snap['country'] : 'Ireland'),
            'sectors'       => (string) ($snap['sectors'] ?? ''),
            'issue_date'    => (string) ($snap['issue_date'] ?? ''),
            'expiry_date'   => trim((string) ($snap['expiry_date'] ?? '')) !== ''
                ? (string) $snap['expiry_date']
                : $expiryDisplay,
        ];
        // Snapshot incomplete → one live enrich (then caller/seed can persist).
        if ($employee['county'] === '' || $employee['sectors'] === '' || $employee['issue_date'] === '') {
            try {
                $live = lookupPsaLicenceOnRegister($licence, $holder !== '' ? $holder : null);
                if (is_array($live) && is_array($live['employee'] ?? null)) {
                    foreach (['county', 'country', 'sectors', 'issue_date', 'expiry_date', 'employee_name', 'licence_no', 'title'] as $k) {
                        $v = trim((string) ($live['employee'][$k] ?? ''));
                        if ($v !== '') {
                            $employee[$k] = $v;
                        }
                    }
                }
            } catch (Throwable $e) {
                // keep partial snapshot
            }
        }
        $card['psa_details'] = [
            'source'      => 'profile_verified',
            'status'      => 'verified',
            'message'     => 'Confirmed on the official PSA register. Check details below, enter BIB, then sign in.',
            'can_proceed' => true,
            'employee'    => $employee,
            'on_file' => [
                'licence_no'      => $licence,
                'employee_name'   => $nameOnFile,
                'expiry_date'     => $expiry,
                'register_name'   => $nameOnFile,
                'register_status' => 'verified',
            ],
        ];
        $card['psa_can_proceed'] = true;

        return $card;
    }

    require_once __DIR__ . '/psa-licence-verify.php';

    try {
        // Live lookup on official PSA registered-employees site (psa-gov.ie).
        $live = lookupPsaLicenceOnRegister($licence, $holder !== '' ? $holder : null);
    } catch (Throwable $e) {
        error_log('[EventStaff] staff sign hub PSA lookup: ' . $e->getMessage());
        $live = null;
    }

    $employee = null;
    if (is_array($live) && is_array($live['employee'] ?? null)) {
        $employee = $live['employee'];
    }

    $status = is_array($live) ? (string) ($live['status'] ?? 'unreachable') : 'unreachable';
    $registerOk = is_array($live) && !empty($live['register_ok']);
    $registerChecked = is_array($live) && !empty($live['register_checked']);
    // Must be verified on official PSA site before security can proceed.
    $canProceed = $status === 'verified' && $registerOk && is_array($employee);

    if ($employee === null) {
        $employee = [
            'title'         => $licence,
            'licence_no'    => $licence,
            'employee_name' => trim((string) ($card['psa_register_name'] ?? '')) !== ''
                ? (string) $card['psa_register_name']
                : $holder,
            'county'        => '',
            'country'       => '',
            'sectors'       => '',
            'issue_date'    => '',
            'expiry_date'   => (string) ($card['psa_expiry_date'] ?? ''),
        ];
    }

    $message = is_array($live)
        ? (string) ($live['message'] ?? '')
        : 'Could not reach the official PSA website. Try again.';
    if ($canProceed) {
        $message = 'Confirmed on the official PSA register. Check details below, enter BIB, then sign in.';
    } elseif ($status === 'unreachable' || !$registerChecked) {
        $message = 'Could not confirm on the official PSA website yet. Tap Retry PSA check before signing in.';
    }

    $card['psa_details'] = [
        'source'           => $registerChecked ? 'psa_register' : 'unreachable',
        'status'           => $status,
        'message'          => $message,
        'can_proceed'      => $canProceed,
        'register_ok'      => $registerOk,
        'register_checked' => $registerChecked,
        'register_url'     => is_array($live) ? (string) ($live['register_url'] ?? 'https://www.psa-gov.ie/psa-registered-employees/') : 'https://www.psa-gov.ie/psa-registered-employees/',
        'employee'         => $employee,
        'on_file'          => [
            'licence_no'      => $licence,
            'employee_name'   => $holder,
            'expiry_date'     => (string) ($card['psa_expiry_date'] ?? ''),
            'register_name'   => (string) ($card['psa_register_name'] ?? ''),
            'register_status' => (string) ($card['psa_register_status'] ?? ''),
        ],
    ];
    $card['psa_can_proceed'] = $canProceed;

    return $card;
}

/**
 * @return array{ok: bool, message: string, data?: array<string, mixed>}
 */
function staffSignHubSelectPerson(PDO $pdo, int $eventId, int $registrationId): array
{
    if ($eventId < 1 || $registrationId < 1) {
        return ['ok' => false, 'message' => 'Select an event and your name.'];
    }

    foreach (staffSignHubRoster($pdo, $eventId) as $row) {
        if ((int) ($row['id'] ?? 0) !== $registrationId) {
            continue;
        }

        $base = staffSignHubPublicCard($row);
        // Prefer profile / cached verified PSA first (fast, reliable on mobile).
        $card = staffSignHubAttachCachedPsaGate($base);
        $status = strtolower(trim((string) ($base['psa_register_status'] ?? '')));
        $needsLive = empty($card['is_steward'])
            && trim((string) ($base['psa_licence'] ?? '')) !== ''
            && $status !== 'verified';

        if ($needsLive) {
            try {
                $live = staffSignHubAttachFullPsaDetails($base);
                if (!empty($live['psa_can_proceed']) || !empty($live['psa_details']['employee'])) {
                    $card = $live;
                }
            } catch (Throwable $e) {
                error_log('[EventStaff] staffSignHubSelectPerson live PSA: ' . $e->getMessage());
                // keep cached card
            }
        } elseif (empty($card['is_steward']) && trim((string) ($base['psa_licence'] ?? '')) !== '') {
            // Already verified — enrich display fields from cached path (already done).
            $card = staffSignHubAttachFullPsaDetails($base);
        }

        return ['ok' => true, 'message' => '', 'data' => $card];
    }

    return ['ok' => false, 'message' => 'Your name must be on the approved signup for this particular event.'];
}

function staffSignHubSignoutDueIso(string $eventDate, string $endTime): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
        return '';
    }
    $endTime = trim($endTime);
    if ($endTime === '') {
        $endTime = '23:00:00';
    }
    if (preg_match('/^\d{2}:\d{2}$/', $endTime)) {
        $endTime .= ':00';
    }
    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $endTime)) {
        return '';
    }

    try {
        $dt = new DateTime($eventDate . ' ' . $endTime);

        return $dt->format(DateTime::ATOM);
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Server-assigned gate start (current hour), staff cannot choose.
 */
function staffSignHubAutoGateStartTime(): string
{
    $hour = (int) (new DateTime('now'))->format('G');

    return sprintf('%02d:00', max(0, min(23, $hour)));
}

/**
 * @param array<string, mixed> $input
 * @return array{ok: bool, message: string, data?: array<string, mixed>}
 */
function staffSignHubPresent(PDO $pdo, int $eventId, int $registrationId, string $bib, array $input): array
{
    if ($eventId < 1 || $registrationId < 1) {
        return ['ok' => false, 'message' => 'Select an event and your name first.'];
    }

    $bib = preg_replace('/\s+/', '', trim($bib)) ?? '';

    // Mandatory: one phone → one person for this event (cannot help colleagues sign in).
    require_once __DIR__ . '/staff-sign-hub-installs.php';
    $visitorKey = normalizeStaffSignHubVisitorKey((string) ($input['visitor_key'] ?? ''));
    if ($visitorKey === '') {
        return ['ok' => false, 'message' => 'Open Staff Sign on your own phone. One user, one sign-in.'];
    }
    $lock = staffSignHubEnforceOneUserOneDevice($pdo, $visitorKey, $eventId, $registrationId);
    if (empty($lock['ok'])) {
        return ['ok' => false, 'message' => (string) ($lock['message'] ?? 'One user, one sign-in.')];
    }

    $event = getEventById($pdo, $eventId);
    if ($event === null) {
        return ['ok' => false, 'message' => 'Event not found.'];
    }

    if (getEventVenueCoordinates($event) === null) {
        return ['ok' => false, 'message' => 'Venue geocode is not set. Ask admin to update geocode in Staff Sign Hub.'];
    }

    // Live DB often has NO psa_* columns on staff_registrations — select only safe cols.
    try {
        $stmt = $pdo->prepare(
            "SELECT id, first_name, surname, email, event_id, status, assigned_bib_number, staff_role, staff_id
             FROM staff_registrations
             WHERE id = :id AND event_id = :event_id AND status = 'approved'
             LIMIT 1"
        );
        $stmt->execute(['id' => $registrationId, 'event_id' => $eventId]);
        $reg = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare(
                "SELECT id, first_name, surname, email, event_id, status, assigned_bib_number, staff_role
                 FROM staff_registrations
                 WHERE id = :id AND event_id = :event_id AND status = 'approved'
                 LIMIT 1"
            );
            $stmt->execute(['id' => $registrationId, 'event_id' => $eventId]);
            $reg = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e2) {
            return ['ok' => false, 'message' => 'Could not load your signup for this event.'];
        }
    }

    if (!$reg) {
        return ['ok' => false, 'message' => 'Your name must be on the approved signup for this particular event.'];
    }

    // PSA number is on staff profile — registration row is often blank.
    // Keep event signup role (do not let profile "static" wipe event "steward").
    $reg = staffSignHubMergeProfile($pdo, $reg);

    $isSteward = staffSignHubIsStewardRole((string) ($reg['staff_role'] ?? ''));
    $assigned = preg_replace('/\s+/', '', trim((string) ($reg['assigned_bib_number'] ?? ''))) ?? '';

    // Security: PSA must be on profile (already live-checked on select). Do NOT
    // re-hit psa-gov.ie here — mobile requests time out and return empty JSON.
    if (!$isSteward) {
        $psaGate = staffSignHubAttachCachedPsaGate(staffSignHubPublicCard(array_merge($reg, [
            'assigned_bib_number' => $assigned,
            'event_name'          => (string) ($event['name'] ?? ''),
            'event_date'          => substr((string) ($event['event_date'] ?? ''), 0, 10),
            'end_time'            => (string) ($event['end_time'] ?? ''),
        ])));
        if (empty($psaGate['psa_can_proceed'])) {
            $msg = (string) (($psaGate['psa_details']['message'] ?? '') ?: 'PSA must be on your profile before sign-in.');

            return ['ok' => false, 'message' => $msg, 'data' => ['psa_details' => $psaGate['psa_details'] ?? null]];
        }
    }

    // Staff Sign Hub: staff type their own BIB each time — never lock/prefill to a
    // previous number (including numbers left by automated tests or prior sign-ins).
    if (!$isSteward) {
        if ($bib === '') {
            return ['ok' => false, 'message' => 'Enter your BIB number.'];
        }
        if (!registrationBibColumnEnabled($pdo)) {
            return ['ok' => false, 'message' => 'Bib numbers are not enabled on this system.'];
        }
    }

    $gps = parseSigninCoordinates($input);
    if ($gps === null) {
        return ['ok' => false, 'message' => getGpsRequiredMessage()];
    }

    // Always enforce venue geocode for Staff Sign Hub (even if GPS v2 flag is off).
    $gpsCheck = validateGpsForCheckin($pdo, $event, $gps);
    if (!$gpsCheck['ok']) {
        return ['ok' => false, 'message' => $gpsCheck['message']];
    }

    if ($bib !== '' && registrationBibColumnEnabled($pdo)) {
        // Only block if another person already holds that bib on this event.
        $dup = $pdo->prepare(
            "SELECT id, first_name, surname
             FROM staff_registrations
             WHERE event_id = :event_id
               AND status = 'approved'
               AND id <> :id
               AND REPLACE(TRIM(COALESCE(assigned_bib_number,'')), ' ', '') = :bib
             LIMIT 1"
        );
        $dup->execute(['event_id' => $eventId, 'id' => $registrationId, 'bib' => $bib]);
        $other = $dup->fetch(PDO::FETCH_ASSOC);
        if ($other) {
            return ['ok' => false, 'message' => 'BIB #' . $bib . ' is already used by another staff member.'];
        }
        // Record the bib they typed for the sheet, but Staff Sign UI never prefills it.
        $bibSave = updateStaffRegistrationBibNumber($pdo, $registrationId, $bib);
        if ($bibSave !== true) {
            return ['ok' => false, 'message' => is_string($bibSave) ? $bibSave : 'Could not save bib.'];
        }
    }

    $result = recordCheckin($pdo, $registrationId, STAFF_SIGN_HUB_METHOD, $gps);
    $name = trim((string) ($reg['first_name'] ?? '') . ' ' . (string) ($reg['surname'] ?? ''));
    $okMsg = $isSteward
        ? ($name . ' · steward signed in')
        : ($name . ' signed in · bib #' . $bib);

    // One complete cycle per event: in → out → done for this event (no second sign-in).
    if ($result === 'Already checked in.') {
        $att = getAttendanceByRegistration($pdo, $registrationId);
        if ($att && empty($att['checked_out_at'])) {
            return [
                'ok'      => true,
                'message' => $name . ' is already signed in. Use Sign out when your shift ends.',
                'data'    => [
                    'registration_id' => $registrationId,
                    'attendance_id'   => (int) ($att['id'] ?? 0),
                    'checked_in_at'   => (string) ($att['checked_in_at'] ?? ''),
                    'gate_start_time' => attendanceGateStartTimeForRegistration($pdo, $registrationId),
                    'bib'             => $bib,
                    'name'            => $name,
                    'already'         => true,
                    'event_end_time'  => trim((string) ($event['end_time'] ?? '')),
                    'signout_due_at'  => staffSignHubSignoutDueIso(
                        substr((string) ($event['event_date'] ?? ''), 0, 10),
                        trim((string) ($event['end_time'] ?? ''))
                    ),
                ],
            ];
        }

        if ($att && !empty($att['checked_out_at'])) {
            return [
                'ok'      => false,
                'message' => 'Already signed in and signed out for this event. You cannot sign in again today.',
                'data'    => [
                    'already_completed' => true,
                    'checked_in_at'     => (string) ($att['checked_in_at'] ?? ''),
                    'checked_out_at'    => (string) ($att['checked_out_at'] ?? ''),
                ],
            ];
        }

        return ['ok' => false, 'message' => 'Already signed in for this event.'];
    }

    if ($result === true) {
        $startTime = staffSignHubAutoGateStartTime();
        saveAttendanceGateStartTime($pdo, $registrationId, $startTime);
        $att = getAttendanceByRegistration($pdo, $registrationId);
        $endTime = trim((string) ($event['end_time'] ?? ''));
        $eventDate = substr((string) ($event['event_date'] ?? ''), 0, 10);

        return [
            'ok'      => true,
            'message' => $okMsg,
            'data'    => [
                'registration_id' => $registrationId,
                'attendance_id'   => (int) ($att['id'] ?? 0),
                'checked_in_at'   => (string) ($att['checked_in_at'] ?? ''),
                'gate_start_time' => $startTime,
                'bib'             => $bib,
                'name'            => $name,
                'is_steward'      => $isSteward,
                'event_end_time'  => $endTime,
                'signout_due_at'  => staffSignHubSignoutDueIso($eventDate, $endTime),
            ],
        ];
    }

    return ['ok' => false, 'message' => is_string($result) ? $result : 'Could not sign in.'];
}

/**
 * @return array{ok: bool, message: string, data?: array<string, mixed>}
 */
function staffSignHubSignOut(PDO $pdo, int $eventId, int $registrationId): array
{
    if ($eventId < 1 || $registrationId < 1) {
        return ['ok' => false, 'message' => 'Missing event or registration.'];
    }

    $att = getAttendanceByRegistration($pdo, $registrationId);
    if (!$att) {
        return ['ok' => false, 'message' => 'No sign-in found. Sign in first.'];
    }

    $attEvent = (int) ($att['event_id'] ?? 0);
    if ($attEvent > 0 && $attEvent !== $eventId) {
        return ['ok' => false, 'message' => 'Attendance does not match this event.'];
    }

    // Confirm registration belongs to event
    $chk = $pdo->prepare(
        "SELECT id FROM staff_registrations
         WHERE id = :id AND event_id = :eid AND status = 'approved' LIMIT 1"
    );
    $chk->execute(['id' => $registrationId, 'eid' => $eventId]);
    if (!(int) $chk->fetchColumn()) {
        return ['ok' => false, 'message' => 'Not on this event roster.'];
    }

    $attId = (int) ($att['id'] ?? 0);
    if ($attId < 1) {
        return ['ok' => false, 'message' => 'Attendance record not found.'];
    }

    if (!empty($att['checked_out_at'])) {
        return [
            'ok'      => true,
            'message' => 'Already signed out.',
            'data'    => [
                'attendance_id'  => $attId,
                'checked_out_at' => (string) $att['checked_out_at'],
                'already'        => true,
            ],
        ];
    }

    $result = attendantClockOut($pdo, $attId, null, STAFF_SIGN_HUB_SIGNOUT_REASON);
    if ($result === true) {
        $fresh = getAttendanceByRegistration($pdo, $registrationId);
        // Keep phone↔person lock for this event (cannot sign a colleague),
        // but shift is complete — no further sign-in for this event.

        return [
            'ok'      => true,
            'message' => 'Signed out. Shift complete for this event — you cannot sign in again today.',
            'data'    => [
                'attendance_id'  => $attId,
                'checked_out_at' => (string) ($fresh['checked_out_at'] ?? ''),
                'completed'      => true,
            ],
        ];
    }

    return ['ok' => false, 'message' => is_string($result) ? $result : 'Could not sign out.'];
}

/**
 * @return array{ok: bool, message: string, data?: array<string, mixed>}
 */
function staffSignHubStatus(PDO $pdo, int $eventId, int $registrationId): array
{
    $roster = staffSignHubRoster($pdo, $eventId);
    foreach ($roster as $row) {
        if ((int) ($row['id'] ?? 0) === $registrationId) {
            return ['ok' => true, 'message' => '', 'data' => staffSignHubPublicCard($row)];
        }
    }

    return ['ok' => false, 'message' => 'Your name must be on the approved signup for this particular event.'];
}
