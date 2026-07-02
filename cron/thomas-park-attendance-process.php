<?php

declare(strict_types=1);

/**
 * Thomas Park 2026-06-26 — bulk attendance, work hours, and commission invoice.
 *
 * GET: ?key=...&dry_run=1   Preview only
 * GET: ?key=...&dry_run=0   Apply changes
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';
require_once dirname(__DIR__) . '/includes/work-hours-repository.php';
require_once dirname(__DIR__) . '/includes/admin-manual-signin.php';
require_once dirname(__DIR__) . '/includes/checkin-bib.php';
require_once dirname(__DIR__) . '/includes/commission-invoice-repository.php';
require_once dirname(__DIR__) . '/includes/staff-psa.php';
require_once dirname(__DIR__) . '/includes/validation.php';

header('Content-Type: application/json; charset=UTF-8');

const EVENT_DATE       = '2026-06-26';
const EVENT_NAME_HINT  = 'Thomas Park';
const EVENT_LOCATION   = 'Limerick';
const START_TIME       = '15:00:00';
const END_TIME         = '22:30:00';
const HOURS_WORKED     = 7.5;
const HOURS_NOTE       = 'Thomas Park 2026-06-26 Limerick — manual sign-in 7.5 hrs (staff worked full shift).';

/** Known name → staff.id aliases (verified in production DB). */
const STAFF_ID_ALIASES = [
    'Agwuna Maureen Chigozie'   => 125,
    'Ajibaee Roy'               => 130,
    'Saiad Ahmed Ali'           => 22,
    'Chinomso Paschaline'       => 140,
    'Codwin Osahan Lgbinedion'  => 34,
    'Mahamoud Mahamed David'    => 20,
];

/** @var list<string> */
const TARGET_STAFF = [
    'Rishika Undralla',
    'Llagat Alvaan',
    'Agwuna Maureen Chigozie',
    'Ajibaee Roy',
    'Adeelaja Oludare',
    'Saiad Ahmed Ali',
    'Samsun Victor Faboade',
    'Chinomso Paschaline',
    'Codwin Osahan Lgbinedion',
    'Manishankar Induri',
    'Awe Margret',
    'Mahamoud Mahamed David',
];

function authorizeCronKey(PDO $pdo): void
{
    $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $providedKey = trim((string) ($_GET['key'] ?? $_SERVER['argv'][1] ?? ''));
    $fallbackKey = 'email-encoding-verify-20260606';
    $isCli       = PHP_SAPI === 'cli';

    if ($isCli) {
        return;
    }

    if ($expectedKey !== '' && hash_equals($expectedKey, $providedKey)) {
        return;
    }
    if ($providedKey !== '' && hash_equals($fallbackKey, $providedKey)) {
        return;
    }

    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT);
    exit;
}

function normalizePersonName(string $name): string
{
    $name = strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
    $name = preg_replace('/[^a-z0-9 ]/', '', $name) ?? '';

    return $name;
}

/**
 * @return list<string>
 */
function nameTokens(string $name): array
{
    $norm = normalizePersonName($name);

    return array_values(array_filter(explode(' ', $norm)));
}

function nameMatchScore(string $target, string $candidate): int
{
    $tTokens = nameTokens($target);
    $cTokens = nameTokens($candidate);
    if ($tTokens === [] || $cTokens === []) {
        return 0;
    }

    $score = 0;
    foreach ($tTokens as $t) {
        foreach ($cTokens as $c) {
            if ($t === $c) {
                $score += 10;
            } elseif (str_starts_with($c, $t) || str_starts_with($t, $c)) {
                $score += 6;
            } elseif (levenshtein($t, $c) <= 2 && strlen($t) > 3) {
                $score += 4;
            }
        }
    }

    $tNorm = normalizePersonName($target);
    $cNorm = normalizePersonName($candidate);
    if ($tNorm === $cNorm) {
        $score += 50;
    } elseif (str_contains($cNorm, $tNorm) || str_contains($tNorm, $cNorm)) {
        $score += 20;
    }

    return $score;
}

function loadThomasParkEvent(PDO $pdo): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM events
         WHERE event_date = :event_date
           AND (
             name LIKE :name_hint
             OR (location LIKE :location AND name LIKE '%Park%')
             OR (location LIKE :location2 AND name LIKE '%Thomas%')
           )
         ORDER BY id ASC LIMIT 1"
    );
    $stmt->execute([
        'event_date' => EVENT_DATE,
        'name_hint'  => '%' . EVENT_NAME_HINT . '%',
        'location'   => '%' . EVENT_LOCATION . '%',
        'location2'  => '%' . EVENT_LOCATION . '%',
    ]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($event)) {
        return $event;
    }

    $stmt2 = $pdo->prepare(
        "SELECT * FROM events
         WHERE event_date = :event_date AND location LIKE :location
         ORDER BY id ASC LIMIT 1"
    );
    $stmt2->execute([
        'event_date' => EVENT_DATE,
        'location'   => '%' . EVENT_LOCATION . '%',
    ]);

    $event = $stmt2->fetch(PDO::FETCH_ASSOC);

    return is_array($event) ? $event : null;
}

/**
 * @return list<array<string, mixed>>
 */
function loadEventRegistrations(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare(
        "SELECT sr.*, a.id AS attendance_id, a.hours_worked, a.hours_paid,
                a.checked_in_at, a.checked_in_method, a.bib_number, a.attendance_status
         FROM staff_registrations sr
         LEFT JOIN attendance a ON a.registration_id = sr.id
         WHERE sr.event_id = :event_id
         ORDER BY sr.surname, sr.first_name"
    );
    $stmt->execute(['event_id' => $eventId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{registration: ?array<string, mixed>, match_score: int, reason: string}
 */
function resolveTargetRegistration(string $targetName, array $eventRegs, array $usedRegIds): array
{
    $best     = null;
    $bestScore = 0;

    foreach ($eventRegs as $reg) {
        $regId = (int) ($reg['id'] ?? 0);
        if ($regId < 1 || in_array($regId, $usedRegIds, true)) {
            continue;
        }

        $full = trim((string) ($reg['first_name'] ?? '') . ' ' . (string) ($reg['surname'] ?? ''));
        $score = nameMatchScore($targetName, $full);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best      = $reg;
        }
    }

    if ($best !== null && $bestScore >= 10) {
        return [
            'registration' => $best,
            'match_score'  => $bestScore,
            'reason'       => 'matched_event_registration',
        ];
    }

    return [
        'registration' => null,
        'match_score'  => $bestScore,
        'reason'       => 'no_event_registration_match',
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function searchStaffDirectory(PDO $pdo, string $targetName): array
{
    $parts = preg_split('/\s+/', trim($targetName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($parts === []) {
        return [];
    }
    $surname   = array_pop($parts);
    $firstName = implode(' ', $parts);

    $stmt = $pdo->prepare(
        "SELECT id, first_name, surname, email, staff_role
         FROM staff
         WHERE (
            (LOWER(surname) LIKE LOWER(:surname) AND LOWER(first_name) LIKE LOWER(:first))
            OR LOWER(CONCAT(first_name, ' ', surname)) LIKE LOWER(:full)
         )
         ORDER BY id DESC LIMIT 5"
    );
    $stmt->execute([
        'surname' => '%' . $surname . '%',
        'first'   => '%' . $firstName . '%',
        'full'    => '%' . $targetName . '%',
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function classifyRoleLabel(string $staffRole): string
{
    $role = normalizeStaffRole($staffRole);

    return in_array($role, ['steward'], true) ? 'Steward' : 'PSA Holder';
}

/**
 * @return array{ok: bool, registration_id?: int, error?: string, created?: bool, would_create?: bool}
 */
function ensureApprovedEventRegistrationFromStaff(PDO $pdo, int $staffId, int $eventId, bool $dryRun = false): array
{
    $staff = getStaffById($pdo, $staffId);
    if ($staff === null) {
        return ['ok' => false, 'error' => 'Staff profile not found'];
    }

    $email = normalizeRegistrationEmail((string) ($staff['email'] ?? ''));
    if ($email === '') {
        return ['ok' => false, 'error' => 'Staff profile has no email'];
    }

    $existing = $pdo->prepare(
        'SELECT id, status FROM staff_registrations WHERE event_id = :event_id AND (staff_id = :staff_id OR LOWER(email) = :email) LIMIT 1'
    );
    $existing->execute(['event_id' => $eventId, 'staff_id' => $staffId, 'email' => $email]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $regId = (int) ($row['id'] ?? 0);
        if (!$dryRun && (string) ($row['status'] ?? '') !== 'approved') {
            $pdo->prepare("UPDATE staff_registrations SET status = 'approved', updated_at = NOW() WHERE id = :id")
                ->execute(['id' => $regId]);
        }

        return ['ok' => true, 'registration_id' => $regId, 'created' => false, 'would_create' => false];
    }

    if ($dryRun) {
        return ['ok' => true, 'registration_id' => 0, 'created' => false, 'would_create' => true];
    }

    $staffRole = normalizeStaffRole((string) ($staff['staff_role'] ?? 'steward'));
    $statusToken = bin2hex(random_bytes(32));

    $insert = $pdo->prepare(
        'INSERT INTO staff_registrations (
            staff_id, surname, first_name, full_address, eircode, email, mobile,
            date_of_birth, gender, pps_number, bank_iban, staff_role, event_id,
            status, status_token, privacy_consented_at
         ) VALUES (
            :staff_id, :surname, :first_name, :full_address, :eircode, :email, :mobile,
            :date_of_birth, :gender, :pps_number, :bank_iban, :staff_role, :event_id,
            :status, :status_token, NOW()
         )'
    );
    $insert->execute([
        'staff_id'       => $staffId,
        'surname'        => trim((string) ($staff['surname'] ?? '')),
        'first_name'     => trim((string) ($staff['first_name'] ?? '')),
        'full_address'   => trim((string) ($staff['full_address'] ?? '')),
        'eircode'        => trim((string) ($staff['eircode'] ?? '')),
        'email'          => $email,
        'mobile'         => trim((string) ($staff['mobile'] ?? '')),
        'date_of_birth'  => (string) ($staff['date_of_birth'] ?? '1990-01-01'),
        'gender'         => trim((string) ($staff['gender'] ?? 'prefer_not_to_say')),
        'pps_number'     => trim((string) ($staff['pps_number'] ?? '')),
        'bank_iban'      => trim((string) ($staff['bank_iban'] ?? '')),
        'staff_role'     => $staffRole,
        'event_id'       => $eventId,
        'status'         => 'approved',
        'status_token'   => $statusToken,
    ]);

    return ['ok' => true, 'registration_id' => (int) $pdo->lastInsertId(), 'created' => true, 'would_create' => false];
}

/**
 * @return array{registration: ?array<string, mixed>, match_score: int, reason: string, staff_id?: int}
 */
function resolveTargetStaffAndRegistration(
    PDO $pdo,
    string $targetName,
    int $eventId,
    array $eventRegs,
    array $usedRegIds,
    bool $dryRun
): array {
    $match = resolveTargetRegistration($targetName, $eventRegs, $usedRegIds);
    if ($match['registration'] !== null) {
        return array_merge($match, [
            'staff_id' => (int) ($match['registration']['staff_id'] ?? 0),
        ]);
    }

    $aliasStaffId = (int) (STAFF_ID_ALIASES[$targetName] ?? 0);
    if ($aliasStaffId > 0) {
        $staff = getStaffById($pdo, $aliasStaffId);
        if ($staff !== null) {
            $regResult = ensureApprovedEventRegistrationFromStaff($pdo, $aliasStaffId, $eventId, $dryRun);
            if ($regResult['ok']) {
                if ($dryRun && ($regResult['would_create'] ?? false)) {
                    return [
                        'registration' => null,
                        'match_score'  => 100,
                        'reason'       => 'alias_staff_would_create_registration',
                        'staff_id'     => $aliasStaffId,
                        'staff_profile'=> [
                            'name'  => trim((string) ($staff['first_name'] ?? '') . ' ' . (string) ($staff['surname'] ?? '')),
                            'email' => (string) ($staff['email'] ?? ''),
                            'staff_role' => (string) ($staff['staff_role'] ?? ''),
                        ],
                    ];
                }

                $regId = (int) ($regResult['registration_id'] ?? 0);
                if ($regId > 0) {
                    $reg = getStaffRegistrationById($pdo, $regId);

                    return [
                        'registration' => $reg,
                        'match_score'  => 100,
                        'reason'       => ($regResult['created'] ?? false) ? 'alias_staff_registration_created' : 'alias_staff_registration_linked',
                        'staff_id'     => $aliasStaffId,
                    ];
                }
            }
        }
    }

    $dirMatches = searchStaffDirectory($pdo, $targetName);
    if ($dirMatches !== []) {
        $best = $dirMatches[0];
        foreach ($dirMatches as $candidate) {
            $full = trim((string) ($candidate['first_name'] ?? '') . ' ' . (string) ($candidate['surname'] ?? ''));
            if (nameMatchScore($targetName, $full) >= nameMatchScore($targetName, trim((string) ($best['first_name'] ?? '') . ' ' . (string) ($best['surname'] ?? '')))) {
                $best = $candidate;
            }
        }
        $staffId = (int) ($best['id'] ?? 0);
        if ($staffId > 0) {
            return [
                'registration' => null,
                'match_score'  => nameMatchScore($targetName, trim((string) ($best['first_name'] ?? '') . ' ' . (string) ($best['surname'] ?? ''))),
                'reason'       => 'directory_match_no_event_registration',
                'staff_id'     => $staffId,
                'directory_match' => $best,
            ];
        }
    }

    return $match;
}

/**
 * @param array<string, mixed> $event
 */
function applyThomasParkShiftHours(
    PDO $pdo,
    int $attendanceId,
    array $event,
    int $adminId,
    string $bibNumber
): array {
    $date       = (string) ($event['event_date'] ?? EVENT_DATE);
    $eventStart = parseEventDateTime($date, START_TIME) ?? new DateTime($date . ' ' . START_TIME);
    $workEnd    = parseEventDateTime($date, END_TIME) ?? new DateTime($date . ' ' . END_TIME);

    $before = $pdo->prepare('SELECT * FROM attendance WHERE id = :id LIMIT 1');
    $before->execute(['id' => $attendanceId]);
    $beforeRow = $before->fetch(PDO::FETCH_ASSOC) ?: [];

    $update = $pdo->prepare(
        'UPDATE attendance SET
            attendance_status = :status,
            activated_at = :activated_at,
            checked_in_at = :checked_in_at,
            checked_in_method = :method,
            checked_out_at = :checked_out_at,
            work_end_at = :work_end_at,
            scheduled_hours = :scheduled_hours,
            hours_worked = :hours_worked,
            hours_paid = :hours_paid,
            hours_note = :hours_note,
            hours_adjusted_by = :admin_id,
            hours_adjusted_at = NOW()
         WHERE id = :id'
    );
    $update->execute([
        'status'          => 'completed',
        'activated_at'    => $eventStart->format('Y-m-d H:i:s'),
        'checked_in_at'   => $eventStart->format('Y-m-d H:i:s'),
        'method'          => 'admin_manual',
        'checked_out_at'  => $workEnd->format('Y-m-d H:i:s'),
        'work_end_at'     => $workEnd->format('Y-m-d H:i:s'),
        'scheduled_hours' => HOURS_WORKED,
        'hours_worked'    => HOURS_WORKED,
        'hours_paid'      => HOURS_WORKED,
        'hours_note'      => HOURS_NOTE,
        'admin_id'        => $adminId,
        'id'              => $attendanceId,
    ]);

    $regId = (int) ($beforeRow['registration_id'] ?? 0);
    if ($regId > 0 && $bibNumber !== '') {
        saveAttendanceBibNumber($pdo, $regId, $bibNumber);
    }

    return [
        'attendance_id'   => $attendanceId,
        'registration_id' => $regId,
        'hours_paid'      => HOURS_WORKED,
        'checked_in_at'   => $eventStart->format('Y-m-d H:i:s'),
        'checked_out_at'  => $workEnd->format('Y-m-d H:i:s'),
        'bib_number'      => $bibNumber,
    ];
}

try {
    $pdo     = getDB();
    authorizeCronKey($pdo);

    $dryRun  = PHP_SAPI === 'cli'
        ? in_array('--apply', $_SERVER['argv'] ?? [], true) === false
        : !isset($_GET['dry_run']) || (string) $_GET['dry_run'] !== '0';

    $adminId = (int) ($pdo->query('SELECT id FROM admin_users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1);

    $event = loadThomasParkEvent($pdo);
    if ($event === null) {
        throw new RuntimeException('Thomas Park event not found for ' . EVENT_DATE . ' in ' . EVENT_LOCATION . '.');
    }

    $eventId     = (int) $event['id'];
    $eventRegs   = loadEventRegistrations($pdo, $eventId);
    $usedRegIds  = [];
    $processed   = [];
    $needsReview = [];
    $duplicates  = [];
    $errors      = [];
    $psaCount    = 0;
    $stewardCount = 0;
    $attendanceCreated = 0;
    $attendanceSkipped = 0;

    foreach (TARGET_STAFF as $index => $targetName) {
        $entry = [
            'target_name' => $targetName,
            'status'      => 'pending',
        ];

        $match = resolveTargetStaffAndRegistration($pdo, $targetName, $eventId, $eventRegs, $usedRegIds, $dryRun);
        $reg   = $match['registration'];

        if ($reg === null) {
            if (($match['reason'] ?? '') === 'alias_staff_would_create_registration') {
                $staffRole = (string) ($match['staff_profile']['staff_role'] ?? '');
                $roleLabel = classifyRoleLabel($staffRole);
                if ($roleLabel === 'Steward') {
                    $stewardCount++;
                } else {
                    $psaCount++;
                }
                $entry['status']          = 'would_create';
                $entry['staff_id']        = (int) ($match['staff_id'] ?? 0);
                $entry['matched_name']    = (string) ($match['staff_profile']['name'] ?? '');
                $entry['staff_role']      = $staffRole;
                $entry['role_label']      = $roleLabel;
                $entry['match_reason']    = $match['reason'];
                $entry['hours']           = HOURS_WORKED;
                $entry['bib_number']      = 'TP' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
                $entry['registration_action'] = 'would_create_approved_registration';
                $processed[] = $entry;
                $attendanceCreated++;
                continue;
            }

            $dirMatches = [];
            if (isset($match['directory_match']) && is_array($match['directory_match'])) {
                $dirMatches = [$match['directory_match']];
            } else {
                $dirMatches = searchStaffDirectory($pdo, $targetName);
            }
            $entry['status']       = 'needs_review';
            $entry['review_reason'] = $dirMatches === []
                ? 'Staff member not found in database'
                : 'Staff profile exists but no registration for this event';
            $entry['directory_matches'] = array_map(static function (array $s): array {
                return [
                    'staff_id'   => (int) ($s['id'] ?? 0),
                    'name'       => trim((string) ($s['first_name'] ?? '') . ' ' . (string) ($s['surname'] ?? '')),
                    'email'      => (string) ($s['email'] ?? ''),
                    'staff_role' => (string) ($s['staff_role'] ?? ''),
                ];
            }, $dirMatches);
            $entry['best_match_score'] = $match['match_score'];
            $needsReview[] = $entry;
            continue;
        }

        $regId     = (int) $reg['id'];
        $fullName  = trim((string) ($reg['first_name'] ?? '') . ' ' . (string) ($reg['surname'] ?? ''));
        $staffRole = (string) ($reg['staff_role'] ?? '');
        $roleLabel = classifyRoleLabel($staffRole);

        if ($roleLabel === 'Steward') {
            $stewardCount++;
        } else {
            $psaCount++;
        }

        $usedRegIds[] = $regId;

        $entry['registration_id'] = $regId;
        $entry['matched_name']    = $fullName;
        $entry['staff_role']      = $staffRole;
        $entry['role_label']      = $roleLabel;
        $entry['registration_status'] = (string) ($reg['status'] ?? '');
        $entry['match_score']     = $match['match_score'];
        $entry['match_reason']    = (string) ($match['reason'] ?? '');

        if ((string) ($reg['status'] ?? '') !== 'approved') {
            $entry['status'] = 'needs_review';
            $entry['review_reason'] = 'Registration exists but status is not approved';
            $needsReview[] = $entry;
            continue;
        }

        $attId = (int) ($reg['attendance_id'] ?? 0);
        $bib   = 'TP' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);

        if ($attId > 0) {
            $existingHours = (float) ($reg['hours_paid'] ?? $reg['hours_worked'] ?? 0);
            $entry['attendance_id']   = $attId;
            $entry['existing_hours']  = $existingHours;
            $entry['attendance_status'] = 'already_exists';

            if (abs($existingHours - HOURS_WORKED) < 0.01) {
                $entry['status'] = 'skipped_duplicate';
                $attendanceSkipped++;
                $duplicates[] = $entry;
            } else {
                $entry['status'] = 'duplicate_different_hours';
                $duplicates[] = $entry;
                if (!$dryRun) {
                    applyThomasParkShiftHours($pdo, $attId, $event, $adminId, $bib);
                    $entry['status'] = 'updated_existing_hours';
                }
            }
            $processed[] = $entry;
            continue;
        }

        if ($dryRun) {
            $entry['status'] = 'would_create';
            $entry['hours']  = HOURS_WORKED;
            $entry['bib_number'] = $bib;
            $processed[] = $entry;
            $attendanceCreated++;
            continue;
        }

        $result = recordAdminManualCheckin($pdo, $regId, HOURS_WORKED, HOURS_NOTE, $adminId, $eventId);
        if ($result !== true) {
            $entry['status'] = 'error';
            $entry['error']  = (string) $result;
            $errors[] = $entry;
            continue;
        }

        $att = getAttendanceByRegistration($pdo, $regId);
        if ($att === null) {
            $entry['status'] = 'error';
            $entry['error']  = 'Attendance row missing after sign-in';
            $errors[] = $entry;
            continue;
        }

        $hourResult = applyThomasParkShiftHours($pdo, (int) $att['id'], $event, $adminId, $bib);
        $entry['status']        = 'created';
        $entry['attendance_id'] = (int) $att['id'];
        $entry['hours']         = HOURS_WORKED;
        $entry['bib_number']    = $bib;
        $entry['shift']         = $hourResult;
        $processed[] = $entry;
        $attendanceCreated++;
    }

    $commissionResult = null;
    $existingInvoice  = getCommissionInvoiceByEventId($pdo, $eventId);

    if ($dryRun) {
        $previewLines = buildCommissionInvoiceLinesFromEvent($pdo, $eventId);
        $commissionResult = [
            'action'              => $existingInvoice ? 'would_rebuild_existing' : 'would_create_draft',
            'existing_invoice_id' => $existingInvoice ? (int) $existingInvoice['id'] : null,
            'billable_lines_now'  => count($previewLines),
            'payment_status'      => 'pending',
        ];
    } else {
        $lines = buildCommissionInvoiceLinesFromEvent($pdo, $eventId);
        if ($lines !== []) {
            if ($existingInvoice) {
                $rebuild = rebuildCommissionInvoiceLinesFromEvent($pdo, (int) $existingInvoice['id'], $adminId);
                $commissionResult = [
                    'action'       => 'rebuilt',
                    'invoice_id'   => is_int($rebuild) ? $rebuild : (int) $existingInvoice['id'],
                    'lines'        => count($lines),
                    'error'        => is_int($rebuild) ? null : (string) $rebuild,
                    'payment_status' => 'pending',
                ];
            } else {
                $totals = recomputeCommissionInvoiceTotals($lines);
                $save = saveCommissionInvoice($pdo, $eventId, [
                    'invoice_date' => EVENT_DATE,
                    'client_name'  => (string) ($event['name'] ?? EVENT_NAME_HINT),
                    'status'       => 'draft',
                    'notes'        => sprintf(
                        'Thomas Park %s — %d staff × %.1f hrs (%s)',
                        EVENT_DATE,
                        $totals['staff_count'],
                        HOURS_WORKED,
                        EVENT_LOCATION
                    ),
                ], $lines, $adminId);

                $commissionResult = [
                    'action'         => is_int($save) ? 'created' : 'failed',
                    'invoice_id'     => is_int($save) ? $save : null,
                    'lines'          => count($lines),
                    'total_amount'   => $totals['total_amount'],
                    'error'          => is_int($save) ? null : (string) $save,
                    'payment_status' => 'pending',
                ];
            }
        } else {
            $commissionResult = [
                'action' => 'skipped_no_billable_lines',
                'error'  => 'No billable attendance rows for commission',
            ];
        }
    }

    $summary = [
        'total_workers_targeted'     => count(TARGET_STAFF),
        'total_workers_processed'    => count($processed),
        'total_psa_holders'          => $psaCount,
        'total_stewards'             => $stewardCount,
        'attendance_records_created' => $attendanceCreated,
        'attendance_skipped_existing'=> $attendanceSkipped,
        'commission_generated'       => $commissionResult,
        'workers_needs_review'       => count($needsReview),
        'duplicates_detected'        => count($duplicates),
        'errors_count'               => count($errors),
    ];

    echo json_encode([
        'ok'           => true,
        'dry_run'      => $dryRun,
        'event'        => [
            'id'         => $eventId,
            'name'       => (string) ($event['name'] ?? ''),
            'location'   => (string) ($event['location'] ?? ''),
            'event_date' => (string) ($event['event_date'] ?? ''),
            'start_time' => START_TIME,
            'end_time'   => END_TIME,
            'hours'      => HOURS_WORKED,
        ],
        'summary'      => $summary,
        'processed'    => $processed,
        'needs_review' => $needsReview,
        'duplicates'   => $duplicates,
        'errors'       => $errors,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
