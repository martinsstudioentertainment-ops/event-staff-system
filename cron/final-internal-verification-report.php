<?php

declare(strict_types=1);

/**
 * Final internal verification — read-only staff profiles + zero-hour attendance.
 *
 *   ?key=CRON_KEY
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/platform/canonical-identity.php';
require_once dirname(__DIR__) . '/includes/staff-onboarding.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/staff-profile-gate.php';
require_once dirname(__DIR__) . '/includes/commission-invoice-repository.php';
require_once dirname(__DIR__) . '/includes/work-hours-repository.php';
require_once dirname(__DIR__) . '/cron/final-production-integrity-audit.php';

header('Content-Type: application/json; charset=UTF-8');

/**
 * @param array<string, mixed> $staff
 */
function fivStaffOperationalStatus(PDO $pdo, array $staff): string
{
    $staffId = (int) ($staff['id'] ?? 0);
    if ((int) ($staff['is_blacklisted'] ?? 0) === 1) {
        return 'Blacklisted';
    }

    $apStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM staff_registrations WHERE staff_id = :sid AND status = 'approved'"
    );
    $apStmt->execute(['sid' => $staffId]);
    $approved = (int) $apStmt->fetchColumn();

    $pendStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM staff_registrations WHERE staff_id = :sid AND status = 'pending'"
    );
    $pendStmt->execute(['sid' => $staffId]);
    $pending = (int) $pendStmt->fetchColumn();

    $attStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM attendance a
         INNER JOIN staff_registrations sr ON sr.id = a.registration_id
         WHERE sr.staff_id = :sid AND COALESCE(a.hours_paid, a.hours_worked, 0) > 0'
    );
    $attStmt->execute(['sid' => $staffId]);
    $paidShifts = (int) $attStmt->fetchColumn();

    if ($paidShifts > 0) {
        return 'Active (paid attendance history)';
    }
    if ($approved > 0) {
        return 'Approved';
    }
    if ($pending > 0) {
        return 'Applicant (pending registrations)';
    }

    return 'Registered (no shift history)';
}

/**
 * @param array<string, mixed> $staff
 * @return array<string, bool>
 */
function fivProfileImpact(array $staff, PDO $pdo): array
{
    $missing = getStaffOnboardingMissingFields($staff);
    $complete = $missing === [];
    $hasApproved = false;
    $staffId = (int) ($staff['id'] ?? 0);
    if ($staffId > 0) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM staff_registrations WHERE staff_id = :sid AND status = 'approved'"
        );
        $stmt->execute(['sid' => $staffId]);
        $hasApproved = (int) $stmt->fetchColumn() > 0;
    }

    $gateBlocked = staffNeedsProfileForm($pdo, $staff);

    return [
        'blocks_standard_approval' => !$complete,
        'blocks_mobile_profile_features' => $gateBlocked,
        'blocks_mobile_login' => false,
        'blocks_attendance_if_approved' => false,
        'blocks_payroll' => !$complete && !$hasApproved,
        'blocks_commission' => false,
    ];
}

/**
 * @return string
 */
function fivProfileGroup(array $staff, array $impact, PDO $pdo): string
{
    $staffId = (int) ($staff['id'] ?? 0);
    $futureApproved = 0;
    if ($staffId > 0) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id
             WHERE sr.staff_id = :sid AND sr.status = 'approved'
               AND e.event_date >= CURDATE() AND e.is_active = 1"
        );
        $stmt->execute(['sid' => $staffId]);
        $futureApproved = (int) $stmt->fetchColumn();
    }

    $missingLabels = getStaffOnboardingMissingFields($staff);
    $psaOnly = $missingLabels !== [] && !array_diff($missingLabels, [
        'PSA licence number', 'PSA expiry date', 'PSA card front photo', 'PSA card back photo',
    ]);

    if ($futureApproved > 0 && ($impact['blocks_mobile_profile_features'] || !$psaOnly)) {
        return 'must_fix_before_working_events';
    }

    if ($missingLabels === []) {
        return 'safe_to_ignore';
    }

    if ($psaOnly) {
        return 'requires_staff_follow_up';
    }

    return 'requires_staff_follow_up';
}

/**
 * @param array<string, mixed> $row
 */
function fivClassifyZeroHourAttendance(array $row): string
{
    $status = strtolower(trim((string) ($row['attendance_status'] ?? '')));
    $checkedIn = trim((string) ($row['checked_in_at'] ?? ''));
    $checkedOut = trim((string) ($row['checked_out_at'] ?? ''));
    $activated = trim((string) ($row['activated_at'] ?? ''));
    $note = strtolower(trim((string) ($row['hours_note'] ?? '')));
    $regStatus = strtolower(trim((string) ($row['reg_status'] ?? '')));

    if ($status === 'no_show') {
        return 'no_show';
    }

    if ($regStatus === 'rejected' || $regStatus === 'cancelled') {
        return 'cancelled_shift';
    }

    if ($note !== '' && (str_contains($note, 'sent home') || str_contains($note, 'zero') || str_contains($note, 'manual'))) {
        return 'manual_zero_hour_adjustment';
    }

    if ($status === 'pre_checked_in' && ($activated === '' || $activated === '0000-00-00 00:00:00')) {
        return 'pre_check_not_activated';
    }

    if ($checkedIn !== '' && $checkedOut !== '' && $checkedIn !== '0000-00-00 00:00:00') {
        $inTs  = strtotime($checkedIn);
        $outTs = strtotime($checkedOut);
        if ($inTs !== false && $outTs !== false && ($outTs - $inTs) < 300) {
            return 'checked_out_immediately';
        }
        if ($outTs !== false) {
            return 'checked_in_checked_out_zero_hours';
        }
    }

    if ($checkedIn !== '' && $checkedIn !== '0000-00-00 00:00:00' && $checkedOut === '') {
        if (in_array($status, ['active', 'auto_signed_out', 'pre_checked_in'], true)) {
            return 'checked_in_not_checked_out';
        }
    }

    if ($checkedIn === '' && $status === '') {
        return 'awaiting_sign_in';
    }

    if ($status === 'hibernated' || $status === 'awaiting') {
        return 'awaiting_activation';
    }

    return 'uncategorised_review';
}

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    // --- Task 1: Incomplete profiles ---
    $staffRows = $pdo->query(
        "SELECT * FROM staff WHERE is_blacklisted = 0 AND COALESCE(profile_completed, 0) = 0 ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $profiles = [];
    $profileGroups = [
        'safe_to_ignore' => 0,
        'requires_staff_follow_up' => 0,
        'must_fix_before_working_events' => 0,
    ];

    foreach ($staffRows as $staff) {
        $missingLabels = getStaffOnboardingMissingFields($staff);
        $missingKeys = [];
        foreach (getStaffOnboardingRequiredFields($staff) as $field => $label) {
            if (in_array($label, $missingLabels, true)) {
                $missingKeys[] = $field;
            }
        }
        $impact  = fivProfileImpact($staff, $pdo);
        $group   = fivProfileGroup($staff, $impact, $pdo);
        $profileGroups[$group] = ($profileGroups[$group] ?? 0) + 1;

        $profiles[] = [
            'staff_id'        => (int) ($staff['id'] ?? 0),
            'name'            => trim(($staff['first_name'] ?? '') . ' ' . ($staff['surname'] ?? '')),
            'email'           => (string) ($staff['email'] ?? ''),
            'role'            => (string) ($staff['staff_role'] ?? ''),
            'status'          => fivStaffOperationalStatus($pdo, $staff),
            'profile_completed_flag' => (int) ($staff['profile_completed'] ?? 0),
            'missing_field_labels' => $missingLabels,
            'missing_field_keys' => $missingKeys,
            'impact'          => $impact,
            'group'           => $group,
        ];
    }

    // --- Task 2: Zero-hour attendance rows ---
    $zeroRows = $pdo->query(
        "SELECT a.id AS attendance_id, a.event_id, a.registration_id,
                a.hours_worked, a.hours_paid, a.attendance_status,
                a.checked_in_at, a.checked_out_at, a.activated_at,
                a.checked_in_method, a.hours_note,
                sr.staff_id, sr.first_name, sr.surname, sr.email, sr.status AS reg_status,
                e.name AS event_name, e.event_date
         FROM attendance a
         INNER JOIN staff_registrations sr ON sr.id = a.registration_id
         LEFT JOIN events e ON e.id = a.event_id
         WHERE COALESCE(a.hours_paid, 0) <= 0 AND COALESCE(a.hours_worked, 0) <= 0
         ORDER BY a.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $zeroBuckets = [];
    $commissionBillableZero = [];
    $systemDefects = [];

    foreach ($zeroRows as $row) {
        $category = fivClassifyZeroHourAttendance($row);
        if (!isset($zeroBuckets[$category])) {
            $zeroBuckets[$category] = [
                'count' => 0,
                'example_attendance_ids' => [],
                'payroll_affected' => false,
                'commission_affected' => false,
            ];
        }
        $zeroBuckets[$category]['count']++;
        if (count($zeroBuckets[$category]['example_attendance_ids']) < 8) {
            $zeroBuckets[$category]['example_attendance_ids'][] = (int) ($row['attendance_id'] ?? 0);
        }

        $billable = attendanceRowBillableForCommissionInvoice($row);
        if ($billable) {
            $commissionBillableZero[] = [
                'attendance_id' => (int) ($row['attendance_id'] ?? 0),
                'staff' => trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? '')),
                'event' => (string) ($row['event_name'] ?? ''),
                'status' => (string) ($row['attendance_status'] ?? ''),
            ];
        }

        // Payroll: zero hours = no payroll payment for that row
        if ($category === 'checked_in_checked_out_zero_hours' && trim((string) ($row['checked_out_at'] ?? '')) !== '') {
            $zeroBuckets[$category]['payroll_affected'] = true;
        }
        if ($billable) {
            foreach ($zeroBuckets as $cat => &$bucket) {
                if ($cat === $category) {
                    $bucket['commission_affected'] = true;
                }
            }
            unset($bucket);
        }

        // System defect heuristics
        if ($category === 'uncategorised_review') {
            $systemDefects[] = [
                'attendance_id' => (int) ($row['attendance_id'] ?? 0),
                'reason' => 'uncategorised_zero_hour_row',
                'status' => (string) ($row['attendance_status'] ?? ''),
                'checked_in' => (string) ($row['checked_in_at'] ?? ''),
            ];
        }
        if ($category === 'checked_in_checked_out_zero_hours') {
            $systemDefects[] = [
                'attendance_id' => (int) ($row['attendance_id'] ?? 0),
                'reason' => 'checkout_completed_but_zero_hours',
                'event' => (string) ($row['event_name'] ?? ''),
            ];
        }
    }

    // --- Task 3: Health modules ---
    $identity = canonicalIdentityRunE2eVerification($pdo);
    $integrity = [
        'attendance'    => auditAttendance($pdo),
        'payroll'       => auditPayroll($pdo),
        'commission'    => auditCommission($pdo),
        'recruitment'   => auditRecruitment($pdo),
        'mobile'        => auditMobile($pdo),
        'foreign_keys'  => auditForeignKeys($pdo),
    ];
    $sheets = auditGoogleSheetsSynchronization($pdo);

    $blockingBeforeFcm = [];
    if (empty($identity['pass'])) {
        $blockingBeforeFcm[] = 'Master Staff Identity verification failed';
    }
    if (($integrity['commission']['missing_line_count'] ?? 0) > 0) {
        $blockingBeforeFcm[] = 'Commission missing line candidates';
    }
    if (($integrity['foreign_keys']['issues'] ?? 0) > 0) {
        $blockingBeforeFcm[] = 'Foreign key orphans remain';
    }
    if (($integrity['recruitment']['issues'] ?? 0) > 0) {
        $blockingBeforeFcm[] = 'Recruitment pipeline orphans';
    }
    if (($sheets['failed_24h'] ?? 0) > 0) {
        $blockingBeforeFcm[] = 'Google Sheets sync failures in 24h';
    }

    echo json_encode([
        'ok' => true,
        'generated_at' => gmdate('c'),
        'task1_incomplete_profiles' => [
            'total' => count($profiles),
            'groups' => $profileGroups,
            'profiles' => $profiles,
        ],
        'task2_zero_hour_attendance' => [
            'total_rows' => count($zeroRows),
            'categories' => $zeroBuckets,
            'commission_billable_zero_hour' => $commissionBillableZero,
            'system_defect_candidates' => $systemDefects,
        ],
        'task3_health' => [
            'master_staff_identity' => ['pass' => !empty($identity['pass'])],
            'attendance' => ['pass' => ($integrity['attendance']['issues'] ?? 0) === 0, 'data' => $integrity['attendance']],
            'payroll' => ['pass' => ($integrity['payroll']['issues'] ?? 0) === 0, 'data' => $integrity['payroll']],
            'commission' => ['pass' => ($integrity['commission']['issues'] ?? 0) === 0, 'data' => $integrity['commission']],
            'google_sheets' => ['pass' => ($sheets['issues'] ?? 0) === 0, 'data' => $sheets],
            'recruitment' => ['pass' => ($integrity['recruitment']['issues'] ?? 0) === 0, 'data' => $integrity['recruitment']],
            'mobile_auth' => [
                'pass' => ($integrity['mobile']['issues'] ?? 0) === 0,
                'note' => 'Login requires email; FCM tokens not required for auth',
                'data' => $integrity['mobile'],
            ],
            'foreign_keys' => ['pass' => ($integrity['foreign_keys']['issues'] ?? 0) === 0],
        ],
        'blocking_before_fcm' => $blockingBeforeFcm,
        'internal_verification_complete' => $blockingBeforeFcm === [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], JSON_PRETTY_PRINT);
}
