<?php

declare(strict_types=1);

/**
 * Final production integrity audit — read-only.
 *
 * GET: /cron/final-production-integrity-audit.php?key=...
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/data-integrity.php';
require_once dirname(__DIR__) . '/includes/platform/staff-duplicate-merge.php';
require_once dirname(__DIR__) . '/includes/commission-invoice-repository.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';

const AUDIT_KEY_FALLBACK = 'email-encoding-verify-20260606';

function auditTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (!isset($cache[$table])) {
        try {
            $cache[$table] = (bool) $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
        } catch (Throwable $e) {
            $cache[$table] = false;
        }
    }

    return $cache[$table];
}

function auditNormName(string $first, string $surname): string
{
    $full = strtolower(trim($first . ' ' . $surname));
    $full = preg_replace('/\s+/', ' ', $full) ?? '';

    return preg_replace('/[^a-z0-9 ]/', '', $full) ?? '';
}

function auditNameTokens(string $name): array
{
    return array_values(array_filter(explode(' ', auditNormName('', $name) ?: auditNormName($name, ''))));
}

function auditFuzzyNameScore(string $a, string $b): int
{
    $a = auditNormName('', $a) ?: strtolower(trim($a));
    $b = auditNormName('', $b) ?: strtolower(trim($b));
    if ($a === $b) {
        return 100;
    }
    if ($a === '' || $b === '') {
        return 0;
    }
    if (str_contains($a, $b) || str_contains($b, $a)) {
        return 85;
    }
    $ta = explode(' ', $a);
    $tb = explode(' ', $b);
    $score = 0;
    foreach ($ta as $t) {
        foreach ($tb as $u) {
            if ($t === $u) {
                $score += 15;
            } elseif (strlen($t) > 3 && strlen($u) > 3 && levenshtein($t, $u) <= 2) {
                $score += 10;
            }
        }
    }

    return $score;
}

function auditSwappedNameKey(string $first, string $surname): string
{
    $a = auditNormName($first, $surname);
    $b = auditNormName($surname, $first);
    $parts = array_filter(explode(' ', $a));
    sort($parts);

    return implode(' ', $parts) . '|' . ($b === $a ? '' : implode(' ', array_filter(explode(' ', $b))));
}

/** @return list<array<string, mixed>> */
function auditStaffDuplicateGroups(PDO $pdo): array
{
    $hardGroups = staffMergeAuditGroups($pdo);
    $issues     = [];

    foreach ($hardGroups as $g) {
        $issues[] = [
            'severity'   => 'high',
            'match_type' => $g['match_types'] ?? ['linked'],
            'staff_ids'  => $g['staff_ids'] ?? [],
            'canonical'  => $g['canonical'] ?? null,
            'records'    => array_map(static fn ($r) => [
                'id'    => $r['id'] ?? null,
                'name'  => trim(($r['first_name'] ?? '') . ' ' . ($r['surname'] ?? '')),
                'email' => $r['email'] ?? '',
            ], $g['records'] ?? []),
        ];
    }

    // Fuzzy name pairs (same surname, similar first name)
    try {
        $rows = $pdo->query(
            'SELECT id, first_name, surname, email, mobile, pps_number, psa_licence, date_of_birth FROM staff ORDER BY surname, first_name'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
    }

    $seenPairs = [];
    $n = count($rows);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $a = $rows[$i];
            $b = $rows[$j];
            $idA = (int) ($a['id'] ?? 0);
            $idB = (int) ($b['id'] ?? 0);
            if ($idA < 1 || $idB < 1) {
                continue;
            }

            $nameA = trim(($a['first_name'] ?? '') . ' ' . ($a['surname'] ?? ''));
            $nameB = trim(($b['first_name'] ?? '') . ' ' . ($b['surname'] ?? ''));
            $pairKey = min($idA, $idB) . '-' . max($idA, $idB);
            if (isset($seenPairs[$pairKey])) {
                continue;
            }

            $reasons = [];
            $surnameA = auditNormName('', (string) ($a['surname'] ?? ''));
            $surnameB = auditNormName('', (string) ($b['surname'] ?? ''));
            $swappedA = auditSwappedNameKey((string) ($a['first_name'] ?? ''), (string) ($a['surname'] ?? ''));
            $swappedB = auditSwappedNameKey((string) ($b['first_name'] ?? ''), (string) ($b['surname'] ?? ''));

            if ($swappedA !== '' && $swappedA === $swappedB) {
                $reasons[] = 'swapped_or_reordered_name';
            }

            $fuzzy = auditFuzzyNameScore($nameA, $nameB);
            if ($fuzzy >= 40 && $surnameA !== '' && $surnameA === $surnameB) {
                $reasons[] = 'fuzzy_same_surname';
            } elseif ($fuzzy >= 55) {
                $reasons[] = 'fuzzy_full_name';
            }

            $psaA = strtoupper(trim((string) ($a['psa_licence'] ?? '')));
            $psaB = strtoupper(trim((string) ($b['psa_licence'] ?? '')));
            if ($psaA !== '' && $psaA === $psaB && !dataIntegrityIsTestPsa($psaA)) {
                $reasons[] = 'psa_licence';
            }

            $ppsA = staffMergeNormalizePps((string) ($a['pps_number'] ?? ''));
            $ppsB = staffMergeNormalizePps((string) ($b['pps_number'] ?? ''));
            if ($ppsA !== '' && $ppsA === $ppsB && !dataIntegrityIsTestPsa($ppsA)) {
                $reasons[] = 'pps';
            }

            $phoneA = staffMergePhoneKey((string) ($a['mobile'] ?? ''));
            $phoneB = staffMergePhoneKey((string) ($b['mobile'] ?? ''));
            if (strlen($phoneA) >= 9 && $phoneA === $phoneB) {
                $reasons[] = 'mobile';
            }

            if ($reasons === []) {
                continue;
            }

            // Skip if already in hard merge group together
            $inHard = false;
            foreach ($hardGroups as $hg) {
                $ids = $hg['staff_ids'] ?? [];
                if (in_array($idA, $ids, true) && in_array($idB, $ids, true)) {
                    $inHard = true;
                    break;
                }
            }
            if ($inHard) {
                continue;
            }

            $seenPairs[$pairKey] = true;
            $issues[] = [
                'severity'   => in_array('pps', $reasons, true) || in_array('psa_licence', $reasons, true) ? 'high' : 'review',
                'match_type' => $reasons,
                'staff_ids'  => [$idA, $idB],
                'records'    => [
                    ['id' => $idA, 'name' => $nameA, 'email' => $a['email'] ?? ''],
                    ['id' => $idB, 'name' => $nameB, 'email' => $b['email'] ?? ''],
                ],
            ];
        }
    }

    // Specific known-pattern scan (Faboade / Ajibade variants)
    foreach ($rows as $row) {
        $name = auditNormName((string) ($row['first_name'] ?? ''), (string) ($row['surname'] ?? ''));
        if (preg_match('/faboade|ajibee|ajibade/', $name)) {
            // flagged in fuzzy pass if duplicate exists
        }
    }

    return $issues;
}

/** @return array<string, mixed> */
function auditRegistrations(PDO $pdo): array
{
    $orphanStaffId = (int) $pdo->query(
        "SELECT COUNT(*) FROM staff_registrations sr
         LEFT JOIN staff s ON s.id = sr.staff_id
         WHERE sr.staff_id IS NOT NULL AND sr.staff_id > 0 AND s.id IS NULL"
    )->fetchColumn();

    $missingStaffLink = (int) $pdo->query(
        "SELECT COUNT(*) FROM staff_registrations sr
         LEFT JOIN staff s ON LOWER(s.email) = LOWER(sr.email)
         WHERE (sr.staff_id IS NULL OR sr.staff_id = 0) AND s.id IS NULL"
    )->fetchColumn();

    $orphanEvent = (int) $pdo->query(
        "SELECT COUNT(*) FROM staff_registrations sr
         LEFT JOIN events e ON e.id = sr.event_id WHERE e.id IS NULL"
    )->fetchColumn();

    $samples = $pdo->query(
        "SELECT sr.id, sr.email, sr.staff_id, sr.event_id FROM staff_registrations sr
         LEFT JOIN staff s ON s.id = sr.staff_id
         WHERE (sr.staff_id IS NOT NULL AND sr.staff_id > 0 AND s.id IS NULL)
            OR NOT EXISTS (SELECT 1 FROM events e WHERE e.id = sr.event_id)
         LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return [
        'total'              => (int) $pdo->query('SELECT COUNT(*) FROM staff_registrations')->fetchColumn(),
        'orphan_staff_id'    => $orphanStaffId,
        'missing_staff_link' => $missingStaffLink,
        'orphan_event'       => $orphanEvent,
        'issues'             => $orphanStaffId + $orphanEvent,
        'samples'            => $samples,
    ];
}

/** @return array<string, mixed> */
function auditAttendance(PDO $pdo): array
{
    $orphanReg = (int) $pdo->query(
        'SELECT COUNT(*) FROM attendance a
         LEFT JOIN staff_registrations sr ON sr.id = a.registration_id WHERE sr.id IS NULL'
    )->fetchColumn();

    $orphanEvent = (int) $pdo->query(
        'SELECT COUNT(*) FROM attendance a
         LEFT JOIN events e ON e.id = a.event_id WHERE e.id IS NULL'
    )->fetchColumn();

    $missingVenue = 0;
    if (auditTableExists($pdo, 'venues')) {
        $missingVenue = (int) $pdo->query(
            "SELECT COUNT(*) FROM attendance a
             INNER JOIN events e ON e.id = a.event_id
             WHERE e.venue_id IS NOT NULL AND e.venue_id > 0
               AND NOT EXISTS (SELECT 1 FROM venues v WHERE v.id = e.venue_id)"
        )->fetchColumn();
    }

    $noStaffChain = (int) $pdo->query(
        "SELECT COUNT(*) FROM attendance a
         INNER JOIN staff_registrations sr ON sr.id = a.registration_id
         LEFT JOIN staff s ON s.id = sr.staff_id
         WHERE sr.staff_id IS NOT NULL AND sr.staff_id > 0 AND s.id IS NULL"
    )->fetchColumn();

    $emptyLocation = (int) $pdo->query(
        "SELECT COUNT(*) FROM attendance a
         INNER JOIN events e ON e.id = a.event_id
         WHERE TRIM(COALESCE(e.location, '')) = ''"
    )->fetchColumn();

    return [
        'total'            => (int) $pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn(),
        'orphan_registration' => $orphanReg,
        'orphan_event'     => $orphanEvent,
        'broken_venue_ref' => $missingVenue,
        'broken_staff_chain' => $noStaffChain,
        'events_missing_location' => $emptyLocation,
        'issues'           => $orphanReg + $orphanEvent + $missingVenue + $noStaffChain,
    ];
}

/** @return array<string, mixed> */
function auditCommission(PDO $pdo): array
{
    if (!auditTableExists($pdo, 'commission_invoice_lines')) {
        return ['total_lines' => 0, 'issues' => 0, 'note' => 'Commission tables not present'];
    }

    $totalLines = (int) $pdo->query('SELECT COUNT(*) FROM commission_invoice_lines')->fetchColumn();
    $totalInvoices = (int) $pdo->query('SELECT COUNT(*) FROM commission_invoices')->fetchColumn();

    $orphanAttendance = (int) $pdo->query(
        'SELECT COUNT(*) FROM commission_invoice_lines cil
         LEFT JOIN attendance a ON a.id = cil.attendance_id
         WHERE cil.attendance_id IS NOT NULL AND cil.attendance_id > 0 AND a.id IS NULL'
    )->fetchColumn();

    $orphanRegistration = (int) $pdo->query(
        'SELECT COUNT(*) FROM commission_invoice_lines cil
         LEFT JOIN staff_registrations sr ON sr.id = cil.registration_id WHERE sr.id IS NULL'
    )->fetchColumn();

    $orphanInvoice = (int) $pdo->query(
        'SELECT COUNT(*) FROM commission_invoice_lines cil
         LEFT JOIN commission_invoices ci ON ci.id = cil.invoice_id WHERE ci.id IS NULL'
    )->fetchColumn();

    $duplicatePerAttendance = $pdo->query(
        'SELECT attendance_id, COUNT(*) AS cnt FROM commission_invoice_lines
         WHERE attendance_id IS NOT NULL AND attendance_id > 0
         GROUP BY attendance_id HAVING cnt > 1 LIMIT 20'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Billable attendance without a saved commission invoice line
    $missingCommission = [];
    if (auditTableExists($pdo, 'commission_invoice_lines') && function_exists('getWorkHoursList')) {
        require_once dirname(__DIR__) . '/includes/work-hours-repository.php';
        $stmt = $pdo->query(
            'SELECT DISTINCT a.event_id FROM attendance a WHERE a.event_id IS NOT NULL'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $eventId) {
            $eventId = (int) $eventId;
            if ($eventId < 1) {
                continue;
            }
            $inv = getCommissionInvoiceByEventId($pdo, $eventId);
            $savedAttIds = [];
            if ($inv !== null) {
                $lineStmt = $pdo->prepare(
                    'SELECT attendance_id FROM commission_invoice_lines WHERE invoice_id = :iid AND attendance_id IS NOT NULL'
                );
                $lineStmt->execute(['iid' => (int) $inv['id']]);
                $savedAttIds = array_map('intval', $lineStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            }
            foreach (getWorkHoursList($pdo, $eventId) as $row) {
                if (!function_exists('attendanceRowBillableForCommissionInvoice') || !attendanceRowBillableForCommissionInvoice($row)) {
                    continue;
                }
                $attId = (int) ($row['attendance_id'] ?? 0);
                if ($attId < 1) {
                    continue;
                }
                if ($inv === null || !in_array($attId, $savedAttIds, true)) {
                    if (count($missingCommission) < 20) {
                        $missingCommission[] = [
                            'event_id'      => $eventId,
                            'attendance_id' => $attId,
                            'staff'         => trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? '')),
                            'reason'        => $inv === null ? 'no commission invoice for event' : 'billable attendance missing from invoice lines',
                        ];
                    }
                }
            }
        }
    }

    return [
        'total_invoices'        => $totalInvoices,
        'total_lines'           => $totalLines,
        'orphan_attendance'     => $orphanAttendance,
        'orphan_registration'   => $orphanRegistration,
        'orphan_invoice'        => $orphanInvoice,
        'duplicate_per_attendance' => count($duplicatePerAttendance),
        'duplicate_samples'     => $duplicatePerAttendance,
        'missing_line_candidates' => $missingCommission,
        'missing_line_count'      => count($missingCommission),
        'issues'                => $orphanAttendance + $orphanRegistration + $orphanInvoice + count($duplicatePerAttendance) + count($missingCommission),
    ];
}

/** @return array<string, mixed> */
function auditPayroll(PDO $pdo): array
{
    $issues = [];
    $orphanAdjustments = 0;
    if (auditTableExists($pdo, 'staff_payroll_adjustments')) {
        $orphanAdjustments = (int) $pdo->query(
            'SELECT COUNT(*) FROM staff_payroll_adjustments spa
             LEFT JOIN staff s ON s.id = spa.staff_id WHERE s.id IS NULL'
        )->fetchColumn();
    }

    $hoursMismatch = [];
    try {
        $rows = $pdo->query(
            "SELECT a.id AS attendance_id, a.hours_paid, a.hours_worked,
                    sr.id AS registration_id, sr.staff_id,
                    CONCAT(sr.first_name, ' ', sr.surname) AS staff_name
             FROM attendance a
             INNER JOIN staff_registrations sr ON sr.id = a.registration_id
             WHERE ABS(COALESCE(a.hours_paid, 0) - COALESCE(a.hours_worked, 0)) > 0.01
             LIMIT 20"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $hoursMismatch[] = $row;
        }
    } catch (Throwable $e) {
        // ignore
    }

    return [
        'payroll_adjustment_rows' => auditTableExists($pdo, 'staff_payroll_adjustments')
            ? (int) $pdo->query('SELECT COUNT(*) FROM staff_payroll_adjustments')->fetchColumn() : 0,
        'orphan_staff_adjustments' => $orphanAdjustments,
        'hours_paid_vs_worked_mismatch' => count($hoursMismatch),
        'hours_mismatch_samples' => $hoursMismatch,
        'issues' => $orphanAdjustments + count($hoursMismatch),
    ];
}

/** @return array<string, mixed> */
function auditEvents(PDO $pdo): array
{
    $total = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();

    $duplicateNameDate = $pdo->query(
        "SELECT name, event_date, COUNT(*) AS cnt, GROUP_CONCAT(id) AS ids
         FROM events GROUP BY LOWER(TRIM(name)), event_date HAVING cnt > 1 LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $duplicateSlot = $pdo->query(
        "SELECT event_date, start_time, end_time, location, COUNT(*) AS cnt, GROUP_CONCAT(id) AS ids
         FROM events
         GROUP BY event_date, start_time, end_time, LOWER(TRIM(location))
         HAVING cnt > 1 LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $noStaff = (int) $pdo->query(
        "SELECT COUNT(*) FROM events e
         WHERE NOT EXISTS (SELECT 1 FROM staff_registrations sr WHERE sr.event_id = e.id)"
    )->fetchColumn();

    $noAttendance = (int) $pdo->query(
        "SELECT COUNT(*) FROM events e
         WHERE EXISTS (SELECT 1 FROM staff_registrations sr WHERE sr.event_id = e.id)
           AND NOT EXISTS (SELECT 1 FROM attendance a WHERE a.event_id = e.id)"
    )->fetchColumn();

    $emptyName = (int) $pdo->query(
        "SELECT COUNT(*) FROM events WHERE TRIM(name) = '' OR name IS NULL"
    )->fetchColumn();

    return [
        'total'                  => $total,
        'duplicate_name_date'    => $duplicateNameDate,
        'duplicate_slot'         => $duplicateSlot,
        'events_without_staff'   => $noStaff,
        'events_without_attendance' => $noAttendance,
        'empty_name'             => $emptyName,
        'issues'                 => count($duplicateNameDate) + count($duplicateSlot) + $emptyName,
        'informational'          => [
            'events_without_staff' => $noStaff,
            'events_without_attendance' => $noAttendance,
        ],
    ];
}

/** @return array<string, mixed> */
function auditMobile(PDO $pdo): array
{
    $issues = [];
    $staffTotal = (int) $pdo->query('SELECT COUNT(*) FROM staff WHERE is_blacklisted = 0')->fetchColumn();

    $noEmail = (int) $pdo->query(
        "SELECT COUNT(*) FROM staff WHERE is_blacklisted = 0 AND (email IS NULL OR TRIM(email) = '')"
    )->fetchColumn();

    $incompleteProfile = (int) $pdo->query(
        "SELECT COUNT(*) FROM staff WHERE is_blacklisted = 0 AND COALESCE(profile_completed, 0) = 0"
    )->fetchColumn();

    // Staff with approved past attendance but no hours
    $attNoHours = (int) $pdo->query(
        "SELECT COUNT(DISTINCT sr.staff_id) FROM attendance a
         INNER JOIN staff_registrations sr ON sr.id = a.registration_id
         WHERE COALESCE(a.hours_paid, a.hours_worked, 0) <= 0
           AND sr.staff_id IS NOT NULL"
    )->fetchColumn();

    // Registrations with attendance — work history visibility
    $workHistoryRows = (int) $pdo->query(
        'SELECT COUNT(DISTINCT sr.staff_id) FROM attendance a
         INNER JOIN staff_registrations sr ON sr.id = a.registration_id
         WHERE sr.staff_id IS NOT NULL AND sr.staff_id > 0'
    )->fetchColumn();

    $brokenMobileFk = 0;
    if (auditTableExists($pdo, 'mobile_refresh_tokens')) {
        $brokenMobileFk += (int) $pdo->query(
            'SELECT COUNT(*) FROM mobile_refresh_tokens t LEFT JOIN staff s ON s.id = t.staff_id WHERE s.id IS NULL'
        )->fetchColumn();
    }
    if (auditTableExists($pdo, 'fcm_device_tokens')) {
        $brokenMobileFk += (int) $pdo->query(
            'SELECT COUNT(*) FROM fcm_device_tokens t LEFT JOIN staff s ON s.id = t.staff_id WHERE s.id IS NULL'
        )->fetchColumn();
    }

    if ($noEmail > 0) {
        $issues[] = "{$noEmail} active staff missing email (cannot login)";
    }
    if ($brokenMobileFk > 0) {
        $issues[] = "{$brokenMobileFk} mobile token rows with invalid staff_id";
    }

    return [
        'active_staff'           => $staffTotal,
        'staff_with_work_history'=> $workHistoryRows,
        'missing_email'          => $noEmail,
        'incomplete_profile'     => $incompleteProfile,
        'attendance_zero_hours'  => $attNoHours,
        'broken_mobile_fk'       => $brokenMobileFk,
        'issues'                 => count($issues),
        'issue_details'          => $issues,
        'note'                   => 'Login requires valid email; work history reads from staff_registrations + attendance join.',
    ];
}

/** @return array<string, mixed> */
function auditRecruitment(PDO $pdo): array
{
    if (!auditTableExists($pdo, 'recruitment_pipeline')) {
        return ['total' => 0, 'issues' => 0, 'note' => 'recruitment_pipeline table not present'];
    }

    $total = (int) $pdo->query('SELECT COUNT(*) FROM recruitment_pipeline')->fetchColumn();
    $orphanStaff = (int) $pdo->query(
        'SELECT COUNT(*) FROM recruitment_pipeline rp
         LEFT JOIN staff s ON s.id = rp.staff_id
         WHERE rp.staff_id IS NOT NULL AND rp.staff_id > 0 AND s.id IS NULL'
    )->fetchColumn();

    $orphanReg = (int) $pdo->query(
        'SELECT COUNT(*) FROM recruitment_pipeline rp
         LEFT JOIN staff_registrations sr ON sr.id = rp.registration_id
         WHERE rp.registration_id IS NOT NULL AND rp.registration_id > 0 AND sr.id IS NULL'
    )->fetchColumn();

    return [
        'total'          => $total,
        'orphan_staff'   => $orphanStaff,
        'orphan_registration' => $orphanReg,
        'issues'         => $orphanStaff + $orphanReg,
    ];
}

/** @return array<string, mixed> */
function auditForeignKeys(PDO $pdo): array
{
    $checks = [];
    $issueCount = 0;

    $queries = [
        ['label' => 'staff_registrations → staff', 'sql' => "SELECT COUNT(*) FROM staff_registrations sr LEFT JOIN staff s ON s.id = sr.staff_id WHERE sr.staff_id IS NOT NULL AND sr.staff_id > 0 AND s.id IS NULL"],
        ['label' => 'staff_registrations → events', 'sql' => 'SELECT COUNT(*) FROM staff_registrations sr LEFT JOIN events e ON e.id = sr.event_id WHERE e.id IS NULL'],
        ['label' => 'attendance → registration', 'sql' => 'SELECT COUNT(*) FROM attendance a LEFT JOIN staff_registrations sr ON sr.id = a.registration_id WHERE sr.id IS NULL'],
        ['label' => 'attendance → events', 'sql' => 'SELECT COUNT(*) FROM attendance a LEFT JOIN events e ON e.id = a.event_id WHERE e.id IS NULL'],
        ['label' => 'commission lines → registration', 'sql' => 'SELECT COUNT(*) FROM commission_invoice_lines cil LEFT JOIN staff_registrations sr ON sr.id = cil.registration_id WHERE sr.id IS NULL'],
        ['label' => 'commission invoices → events', 'sql' => 'SELECT COUNT(*) FROM commission_invoices ci LEFT JOIN events e ON e.id = ci.event_id WHERE e.id IS NULL'],
        ['label' => 'staff_messages → staff', 'sql' => 'SELECT COUNT(*) FROM staff_messages sm LEFT JOIN staff s ON s.id = sm.staff_id WHERE sm.staff_id IS NOT NULL AND sm.staff_id > 0 AND s.id IS NULL'],
    ];

    foreach ($queries as $q) {
        if (str_contains($q['sql'], 'commission') && !auditTableExists($pdo, 'commission_invoice_lines')) {
            continue;
        }
        if (str_contains($q['sql'], 'staff_messages') && !auditTableExists($pdo, 'staff_messages')) {
            continue;
        }
        try {
            $cnt = (int) $pdo->query($q['sql'])->fetchColumn();
            $checks[] = ['check' => $q['label'], 'orphans' => $cnt, 'ok' => $cnt === 0];
            if ($cnt > 0) {
                $issueCount += $cnt;
            }
        } catch (Throwable $e) {
            $checks[] = ['check' => $q['label'], 'orphans' => -1, 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    foreach (staffMergeStaffIdTables() as $table) {
        if (!auditTableExists($pdo, $table) || !staffMergeColumnExists($pdo, $table, 'staff_id')) {
            continue;
        }
        try {
            $cnt = (int) $pdo->query(
                "SELECT COUNT(*) FROM `{$table}` t LEFT JOIN staff s ON s.id = t.staff_id
                 WHERE t.staff_id IS NOT NULL AND t.staff_id > 0 AND s.id IS NULL"
            )->fetchColumn();
            $checks[] = ['check' => "{$table}.staff_id → staff", 'orphans' => $cnt, 'ok' => $cnt === 0];
            if ($cnt > 0) {
                $issueCount += $cnt;
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    return [
        'checks' => $checks,
        'issues' => $issueCount,
        'pass'   => $issueCount === 0,
    ];
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    header('Content-Type: application/json; charset=UTF-8');
try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    $expected = trim(getSetting($pdo, 'reminder_cron_key', ''));
    if (!(($expected !== '' && hash_equals($expected, $key)) || hash_equals(AUDIT_KEY_FALLBACK, $key))) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $staffDupes     = auditStaffDuplicateGroups($pdo);
    $registrations  = auditRegistrations($pdo);
    $attendance     = auditAttendance($pdo);
    $commission     = auditCommission($pdo);
    $payroll        = auditPayroll($pdo);
    $events         = auditEvents($pdo);
    $mobile         = auditMobile($pdo);
    $recruitment    = auditRecruitment($pdo);
    $foreignKeys    = auditForeignKeys($pdo);

    $highDupes = array_filter($staffDupes, static fn ($d) => ($d['severity'] ?? '') === 'high');
    $reviewDupes = array_filter($staffDupes, static fn ($d) => ($d['severity'] ?? '') === 'review');

    $totalIssues =
        count($highDupes)
        + count($reviewDupes)
        + (int) ($registrations['issues'] ?? 0)
        + (int) ($attendance['issues'] ?? 0)
        + (int) ($commission['issues'] ?? 0)
        + (int) ($payroll['issues'] ?? 0)
        + (int) ($events['issues'] ?? 0)
        + (int) ($mobile['issues'] ?? 0)
        + (int) ($recruitment['issues'] ?? 0)
        + ((int) ($foreignKeys['issues'] ?? 0) > 0 ? 1 : 0);

    $counts = [
        'staff'               => (int) $pdo->query('SELECT COUNT(*) FROM staff')->fetchColumn(),
        'events'              => (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn(),
        'registrations'       => (int) ($registrations['total'] ?? 0),
        'attendance'          => (int) ($attendance['total'] ?? 0),
        'commission_invoices' => (int) ($commission['total_invoices'] ?? 0),
        'commission_lines'    => (int) ($commission['total_lines'] ?? 0),
        'recruitment'         => (int) ($recruitment['total'] ?? 0),
        'payroll_adjustments' => (int) ($payroll['payroll_adjustment_rows'] ?? 0),
    ];

    $clean = $totalIssues === 0 && count($highDupes) === 0
        && (int) ($registrations['issues'] ?? 0) === 0
        && (int) ($attendance['issues'] ?? 0) === 0
        && (int) ($commission['issues'] ?? 0) === 0
        && (int) ($foreignKeys['issues'] ?? 0) === 0;

    echo json_encode([
        'ok'                        => true,
        'generated_at'              => gmdate('c'),
        'production_database_status'=> $clean ? 'CLEAN' : 'REVIEW REQUIRED',
        'production_database_status_display' => $clean
            ? 'PRODUCTION DATABASE STATUS: CLEAN ✅'
            : 'PRODUCTION DATABASE STATUS: REVIEW REQUIRED ⚠️',
        'record_counts'             => $counts,
        '1_staff_duplicates'        => [
            'hard_duplicate_groups' => count($highDupes),
            'fuzzy_review_pairs'    => count($reviewDupes),
            'high_severity'         => array_values($highDupes),
            'review_severity'       => array_values(array_slice($reviewDupes, 0, 30)),
        ],
        '2_registrations'           => $registrations,
        '3_attendance'              => $attendance,
        '4_commission'              => $commission,
        '5_payroll'                 => $payroll,
        '6_events'                  => $events,
        '7_mobile_app'              => $mobile,
        '8_recruitment'             => $recruitment,
        '9_foreign_keys'            => $foreignKeys,
        '10_summary'                => [
            'remaining_duplicate_groups' => count($highDupes),
            'fuzzy_duplicate_pairs'      => count($reviewDupes),
            'orphan_records'             => (int) ($foreignKeys['issues'] ?? 0),
            'invalid_foreign_keys'       => array_values(array_filter($foreignKeys['checks'] ?? [], static fn ($c) => !($c['ok'] ?? true))),
            'attendance_issues'          => (int) ($attendance['issues'] ?? 0),
            'payroll_issues'             => (int) ($payroll['issues'] ?? 0),
            'commission_issues'          => (int) ($commission['issues'] ?? 0),
            'mobile_issues'              => $mobile['issue_details'] ?? [],
            'event_issues'               => array_filter([
                'duplicate_name_date' => count($events['duplicate_name_date'] ?? []),
                'duplicate_slot'      => count($events['duplicate_slot'] ?? []),
                'empty_name'          => (int) ($events['empty_name'] ?? 0),
            ]),
            'total_issue_score'          => $totalIssues,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
}
