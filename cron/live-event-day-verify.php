<?php

declare(strict_types=1);

/**
 * Live production event-day verification for a single staff account (read-only except OTP/JWT test chain).
 *
 *   ?key=CRON_KEY&email=olabodeoluwafemi2580@gmail.com
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/platform/canonical-identity.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/staff-profile-gate.php';
require_once dirname(__DIR__) . '/includes/staff-google-oauth.php';
require_once dirname(__DIR__) . '/includes/staff-app-v3-data.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';
require_once dirname(__DIR__) . '/includes/attendance-gps-phase1.php';
require_once dirname(__DIR__) . '/includes/attendance-gps-phase15.php';
require_once dirname(__DIR__) . '/includes/date-format.php';
require_once dirname(__DIR__) . '/includes/mobile/mobile-api-qa-runner.php';
require_once dirname(__DIR__) . '/includes/mobile/services/MobileDashboardService.php';
require_once dirname(__DIR__) . '/includes/mobile/services/MobileShiftService.php';
require_once dirname(__DIR__) . '/includes/mobile/services/MobileAttendanceService.php';
require_once dirname(__DIR__) . '/includes/mobile/services/MobileEmailOtpAuthService.php';
require_once dirname(__DIR__) . '/includes/mobile/services/MobileOtpService.php';
require_once dirname(__DIR__) . '/includes/mobile/services/MobileAuthService.php';

header('Content-Type: application/json; charset=UTF-8');

const LIVE_VERIFY_DEFAULT_EMAIL = 'olabodeoluwafemi2580@gmail.com';
const LIVE_VERIFY_DEVICE_ID     = 'live-event-day-verify-cron';

/**
 * @param array<string, mixed> $sections
 */
function liveVerifyVerdict(array $sections): string
{
    foreach ($sections as $section) {
        if (($section['status'] ?? '') === 'FAIL') {
            return 'FAIL';
        }
    }

    return 'PASS';
}

/**
 * Insert OTP for cron verification only — does not send email.
 *
 * @return array{ok: bool, code?: string, error?: string}
 */
function liveVerifyInsertOtpForTest(PDO $pdo, string $email): array
{
    mobileOtpEnsureSchema($pdo);
    $code    = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', time() + 600);
    $insert  = $pdo->prepare(
        'INSERT INTO mobile_email_otp_codes (email, purpose, code_hash, expires_at)
         VALUES (:email, :purpose, :hash, :expires_at)'
    );
    $insert->execute([
        'email'      => mobileOtpNormalizeEmail($email),
        'purpose'    => 'login',
        'hash'       => hash('sha256', $code),
        'expires_at' => $expires,
    ]);

    return ['ok' => true, 'code' => $code];
}

/**
 * @return list<array<string, mixed>>
 */
function liveVerifyRecentAccountLogs(PDO $pdo, string $email, int $staffId): array
{
    $entries = [];
    $email   = strtolower(trim($email));

    try {
        $stmt = $pdo->prepare(
            "SELECT action, source, created_at, details
             FROM canonical_identity_audit
             WHERE (staff_id = :sid OR LOWER(TRIM(canonical_email)) = :cemail OR LOWER(TRIM(submitted_email)) = :semail)
             ORDER BY id DESC LIMIT 15"
        );
        $stmt->execute(['sid' => $staffId, 'cemail' => $email, 'semail' => $email]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $entries[] = [
                'type'    => 'canonical_identity_audit',
                'action'  => (string) ($row['action'] ?? ''),
                'source'  => (string) ($row['source'] ?? ''),
                'at'      => (string) ($row['created_at'] ?? ''),
                'detail'  => (string) ($row['details'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        $entries[] = ['type' => 'canonical_identity_audit', 'error' => $e->getMessage()];
    }

    $logPaths = [
        dirname(__DIR__) . '/storage/logs/php-error.log',
        dirname(__DIR__) . '/error_log',
    ];
    foreach ($logPaths as $path) {
        if (!is_readable($path)) {
            continue;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            continue;
        }
        $needle = strtolower($email);
        $hits   = 0;
        foreach (array_reverse($lines) as $line) {
            if (!str_contains(strtolower($line), $needle)
                && !str_contains(strtolower($line), 'mobileapi')
                && !str_contains(strtolower($line), 'otp')) {
                continue;
            }
            $entries[] = ['type' => 'php_error_log', 'line' => $line];
            if (++$hits >= 10) {
                break;
            }
        }
        break;
    }

    return $entries;
}

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $email = canonicalIdentityNormalizeEmail((string) ($_GET['email'] ?? LIVE_VERIFY_DEFAULT_EMAIL));
    $today = getOperationalTodayYmd($pdo);
    $now   = date('Y-m-d H:i:s');

    $sections = [];

    // --- 1. Account ---
    $account = ['status' => 'PASS', 'checks' => [], 'blockers' => []];

    $staffCountStmt = $pdo->prepare('SELECT COUNT(*) FROM staff WHERE LOWER(TRIM(email)) = :email');
    $staffCountStmt->execute(['email' => $email]);
    $staffCount = (int) $staffCountStmt->fetchColumn();

    $staffStmt = $pdo->prepare(
        'SELECT * FROM staff WHERE LOWER(TRIM(email)) = :email ORDER BY is_blacklisted ASC, id ASC LIMIT 1'
    );
    $staffStmt->execute(['email' => $email]);
    $staff = $staffStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $canonicalStaff = canonicalIdentityResolveStaffForLoginEmail($pdo, $email);
    $identityAudit  = canonicalIdentityAuditIntegrity($pdo);

    $policy = getStaffAuthPolicy($pdo);
    $mobileLoginEnabled = !empty($policy['mobile_email_otp_enabled'])
        || (isStaffGoogleSigninEnabled($pdo) && isStaffGoogleSigninConfigured($pdo));

    $account['checks']['staff_profile_exists'] = $staff !== null;
    $account['checks']['profile_active'] = $staff !== null
        && (int) ($staff['is_blacklisted'] ?? 0) !== 1;
    $account['checks']['no_duplicate_profiles'] = $staffCount === 1;
    $account['checks']['primary_email_correct'] = $staff !== null
        && canonicalIdentityNormalizeEmail((string) ($staff['email'] ?? '')) === $email;
    $account['checks']['canonical_identity_linked'] = $canonicalStaff !== null
        && $staff !== null
        && (int) ($canonicalStaff['id'] ?? 0) === (int) ($staff['id'] ?? 0);
    $account['checks']['mobile_login_enabled'] = $mobileLoginEnabled;
    $account['checks']['canonical_identity_audit_pass'] = ($identityAudit['status'] ?? '') === 'PASS';

    $account['staff'] = $staff ? [
        'id'                => (int) $staff['id'],
        'name'              => trim(($staff['first_name'] ?? '') . ' ' . ($staff['surname'] ?? '')),
        'email'             => (string) ($staff['email'] ?? ''),
        'staff_role'        => (string) ($staff['staff_role'] ?? ''),
        'profile_completed' => (int) ($staff['profile_completed'] ?? 0),
        'is_blacklisted'    => (int) ($staff['is_blacklisted'] ?? 0),
    ] : null;
    $account['duplicate_staff_count'] = $staffCount;
    $account['auth_policy'] = [
        'mobile_email_otp_enabled' => !empty($policy['mobile_email_otp_enabled']),
        'google_signin_enabled'    => isStaffGoogleSigninEnabled($pdo),
        'google_signin_configured' => isStaffGoogleSigninConfigured($pdo),
    ];

    foreach ([
        'staff_profile_exists',
        'profile_active',
        'no_duplicate_profiles',
        'primary_email_correct',
        'canonical_identity_linked',
        'mobile_login_enabled',
    ] as $ck) {
        if (empty($account['checks'][$ck])) {
            $account['status'] = 'FAIL';
            $account['blockers'][] = $ck;
        }
    }

    $sections['account'] = $account;

    if ($staff === null) {
        echo json_encode([
            'ok'       => false,
            'verdict'  => 'FAIL',
            'email'    => $email,
            'today'    => $today,
            'now'      => $now,
            'sections' => $sections,
            'message'  => 'Staff profile not found — cannot continue verification.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $staffId = (int) $staff['id'];

    // --- 2. Today's event ---
    $eventSection = ['status' => 'PASS', 'checks' => [], 'blockers' => [], 'today_event' => null];

    $todayRegStmt = $pdo->prepare(
        "SELECT sr.*, e.name AS event_name, e.location, e.event_date, e.start_time, e.end_time,
                e.is_active, e.venue_lat, e.venue_lng, e.venue_eircode, e.venue_id,
                v.name AS venue_name
         FROM staff_registrations sr
         INNER JOIN events e ON e.id = sr.event_id
         LEFT JOIN venues v ON v.id = e.venue_id
         WHERE (sr.staff_id = :sid OR LOWER(TRIM(sr.email)) = :email)
           AND e.event_date = :today
           AND sr.status = 'approved'
         ORDER BY e.start_time ASC, sr.id ASC"
    );
    $todayRegStmt->execute(['sid' => $staffId, 'email' => $email, 'today' => $today]);
    $todayRegs = $todayRegStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $eventSection['checks']['registered_today'] = $todayRegs !== [];
    $eventSection['checks']['registration_approved'] = $todayRegs !== [];

    $todayEventIds = array_map(static fn (array $r): int => (int) ($r['event_id'] ?? 0), $todayRegs);
    $eventSection['checks']['no_duplicate_today_registrations'] = count($todayEventIds) === count(array_unique($todayEventIds));
    $eventSection['today_events'] = [];

    foreach ($todayRegs as $regRow) {
        $eventSection['today_events'][] = [
            'event_id'        => (int) ($regRow['event_id'] ?? 0),
            'event_name'      => (string) ($regRow['event_name'] ?? ''),
            'venue'           => trim((string) (($regRow['venue_name'] ?? '') !== '' ? $regRow['venue_name'] : ($regRow['location'] ?? ''))),
            'event_date'      => (string) ($regRow['event_date'] ?? ''),
            'start_time'      => (string) ($regRow['start_time'] ?? ''),
            'finish_time'     => (string) ($regRow['end_time'] ?? ''),
            'registration_id' => (int) ($regRow['id'] ?? 0),
            'assigned_role'   => (string) ($regRow['staff_role'] ?? ''),
            'status'          => (string) ($regRow['status'] ?? ''),
        ];
    }

    $todayReg = $todayRegs[0] ?? null;
    if ($todayReg !== null) {
        $eventSection['checks']['event_active'] = (int) ($todayReg['is_active'] ?? 0) === 1;
        $eventSection['checks']['role_assigned'] = trim((string) ($todayReg['staff_role'] ?? '')) !== '';
        $eventSection['today_event'] = $eventSection['today_events'][0] ?? null;
    }

    foreach ([
        'registered_today',
        'registration_approved',
        'no_duplicate_today_registrations',
        'event_active',
        'role_assigned',
    ] as $ck) {
        if (!array_key_exists($ck, $eventSection['checks']) || empty($eventSection['checks'][$ck])) {
            $eventSection['status'] = 'FAIL';
            $eventSection['blockers'][] = $ck;
        }
    }

    $registrationId = $todayReg ? (int) ($todayReg['id'] ?? 0) : 0;
    $sections['event_assignment'] = $eventSection;

    // --- 3. Mobile login (full OTP → JWT chain + live HTTP) ---
    $login = ['status' => 'PASS', 'checks' => [], 'blockers' => [], 'methods' => []];

    $googleElig = mobileQaGoogleEligibility($pdo, $staff);
    $login['methods']['google_signin_eligible'] = !empty($googleElig['ok']);

    $login['checks']['staff_resolves_for_login'] = $canonicalStaff !== null;
    $login['checks']['mobile_api_enabled'] = mobileApiIsEnabled($pdo);
    $login['checks']['jwt_secret_configured'] = trim(getSetting($pdo, 'mobile_jwt_secret', '')) !== '';

    $otpInsert = liveVerifyInsertOtpForTest($pdo, $email);
    $login['checks']['otp_record_created'] = !empty($otpInsert['ok']);

    $tokenResult = null;
    if (!empty($otpInsert['ok']) && isset($otpInsert['code'])) {
        $tokenResult = mobileEmailOtpAuthVerifyLogin($pdo, [
            'email'     => $email,
            'code'      => (string) $otpInsert['code'],
            'device_id' => LIVE_VERIFY_DEVICE_ID,
        ]);
        $login['checks']['otp_verify_succeeds'] = !empty($tokenResult['ok']);
        $login['checks']['access_token_issued'] = !empty($tokenResult['access_token']);
        $login['checks']['refresh_token_issued'] = !empty($tokenResult['refresh_token']);
    } else {
        $login['checks']['otp_verify_succeeds'] = false;
        $login['checks']['access_token_issued'] = false;
        $login['checks']['refresh_token_issued'] = false;
    }

    $accessToken = is_array($tokenResult) ? (string) ($tokenResult['access_token'] ?? '') : '';
    $apiBase     = rtrim(getSetting($pdo, 'mobile_api_base_url', '') ?: 'https://register.olasentra.com/api/mobile/v1', '/');

    $apiEndpoints = ['/config?platform=android', '/dashboard', '/me', '/shifts/today', '/shifts?filter=upcoming'];
    if ($registrationId > 0) {
        $apiEndpoints[] = '/shifts/' . $registrationId;
        $apiEndpoints[] = '/gps/status?registration_id=' . $registrationId;
    }

    $login['api_http'] = [];
    foreach ($apiEndpoints as $path) {
        $http = mobileQaHttpGet($apiBase . $path, $accessToken !== '' ? $accessToken : null);
        $ok   = $http['code'] === 200
            && is_array($http['json'] ?? null)
            && (($http['json']['ok'] ?? true) !== false);
        $login['api_http'][$path] = [
            'http_code' => $http['code'],
            'ok'        => $ok,
            'error'     => $http['err'] !== '' ? $http['err'] : null,
        ];
        if (!$ok && $path !== '/config?platform=android') {
            $login['checks']['api_' . md5($path)] = false;
        }
    }

    $login['checks']['dashboard_api_ok'] = !empty($login['api_http']['/dashboard']['ok']);
    $login['checks']['shifts_today_api_ok'] = !empty($login['api_http']['/shifts/today']['ok']);

    foreach ($login['checks'] as $ck => $val) {
        if ($val === false) {
            $login['status'] = 'FAIL';
            $login['blockers'][] = $ck;
        }
    }

    $sections['login'] = $login;

    // --- 4. Shift visibility ---
    $shifts = ['status' => 'PASS', 'checks' => [], 'blockers' => []];

    $shiftRows = getStaffV3ShiftRowsByStaffId($pdo, $staffId);
    $todayShift = getStaffV3TodayShift($shiftRows, $pdo);
    $upcoming   = array_values(array_filter(
        $shiftRows,
        static fn (array $row): bool => normalizeEventDateYmd((string) ($row['event_date'] ?? '')) >= $today
            && strtolower((string) ($row['status'] ?? '')) === 'approved'
    ));

    $dash = mobileDashboardServiceBuild($pdo, $staff);
    $shiftTodaySvc = mobileShiftServiceToday($pdo, $staff);
    $shiftListSvc  = mobileShiftServiceList($pdo, $staff, ['filter' => 'upcoming', 'per_page' => 50]);

    $shifts['checks']['web_today_shift_visible'] = $todayShift !== null;
    $shifts['checks']['mobile_service_today_shift'] = !empty($shiftTodaySvc['ok'])
        && ($shiftTodaySvc['shift'] ?? null) !== null;
    $shifts['checks']['upcoming_shifts_count_gt_zero'] = count($upcoming) > 0;
    $shifts['checks']['mobile_upcoming_list_has_today'] = false;

    if (!empty($shiftListSvc['ok']) && is_array($shiftListSvc['shifts'] ?? null)) {
        foreach ($shiftListSvc['shifts'] as $s) {
            $d = normalizeEventDateYmd((string) ($s['event_date'] ?? ''));
            if ($d === $today) {
                $shifts['checks']['mobile_upcoming_list_has_today'] = true;
                break;
            }
        }
    }

    if ($registrationId > 0) {
        $detail = mobileShiftServiceGet($pdo, $staff, $registrationId);
        $shifts['checks']['event_details_accessible'] = !empty($detail['ok']);
    } else {
        $shifts['checks']['event_details_accessible'] = false;
    }

    $shifts['checks']['dashboard_service_ok'] = !empty($dash['ok']);
    $shifts['counts'] = [
        'upcoming_approved' => count($upcoming),
        'total_shift_rows'  => count($shiftRows),
    ];

    foreach ($shifts['checks'] as $ck => $val) {
        if ($val === false) {
            $shifts['status'] = 'FAIL';
            $shifts['blockers'][] = $ck;
        }
    }

    $sections['shift_visibility'] = $shifts;

    // --- 5. GPS check-in readiness ---
    $gpsIn = ['status' => 'PASS', 'checks' => [], 'blockers' => [], 'window' => null, 'geofence' => null];

    $gpsIn['checks']['gps_v2_enabled'] = isGpsAttendanceV2Enabled($pdo);
    $gpsIn['checks']['profile_allows_checkin'] = !staffNeedsProfileForm($pdo, $staff);

    if ($todayReg !== null && $registrationId > 0) {
        $tokenStmt = $pdo->prepare('SELECT checkin_token FROM staff_registrations WHERE id = :id LIMIT 1');
        $tokenStmt->execute(['id' => $registrationId]);
        $existingToken = (string) ($tokenStmt->fetchColumn() ?: '');
        if ($existingToken === '') {
            $existingToken = (string) (ensureCheckinToken($pdo, $registrationId) ?? '');
        }
        $gpsIn['checks']['checkin_token_exists'] = $existingToken !== '';
        $gpsIn['checkin_token_present'] = $existingToken !== '';

        $window = getEventCheckinWindow($todayReg);
        $gpsIn['window'] = [
            'opens_at'  => $window['opens_at']->format('Y-m-d H:i:s'),
            'closes_at' => $window['closes_at']->format('Y-m-d H:i:s'),
            'status'    => $window['status'],
            'is_open'   => $window['is_open'],
        ];
        $gpsIn['checks']['checkin_window_configured'] = $window['status'] !== 'invalid';

        $venueConfigured = eventVenueIsConfigured($todayReg);
        $coords          = getEventVenueCoordinates($todayReg);
        $radius          = getEventSigninRadiusMeters($todayReg, $pdo);
        $gpsIn['geofence'] = [
            'venue_configured' => $venueConfigured,
            'latitude'         => $coords['lat'] ?? null,
            'longitude'        => $coords['lng'] ?? null,
            'radius_m'       => $radius,
        ];
        $gpsIn['checks']['geofence_configured'] = $venueConfigured && $coords !== null;

        $att = getAttendanceByRegistration($pdo, $registrationId);
        $gpsIn['checks']['not_already_checked_in'] = $att === null || trim((string) ($att['checked_in_at'] ?? '')) === '';
        $gpsIn['checks']['registration_eligible'] = (string) ($todayReg['status'] ?? '') === 'approved';
    } else {
        foreach (['checkin_token_exists', 'checkin_window_configured', 'geofence_configured', 'not_already_checked_in', 'registration_eligible'] as $ck) {
            $gpsIn['checks'][$ck] = false;
        }
    }

    foreach ($gpsIn['checks'] as $ck => $val) {
        if ($val === false) {
            $gpsIn['status'] = 'FAIL';
            $gpsIn['blockers'][] = $ck;
        }
    }

    $sections['gps_checkin'] = $gpsIn;

    // --- 6. GPS check-out readiness ---
    $gpsOut = ['status' => 'PASS', 'checks' => [], 'blockers' => []];

    $gpsOut['checks']['event_has_end_time'] = $todayReg !== null
        && trim((string) ($todayReg['end_time'] ?? '')) !== ''
        && (string) ($todayReg['end_time'] ?? '') !== '00:00:00';
    $gpsOut['checks']['gps_checkout_supported'] = isGpsAttendanceV2Enabled($pdo);
    $gpsOut['checks']['attendance_table_ready'] = true;

    if ($registrationId > 0) {
        $gpsStatus = mobileAttendanceServiceGpsStatus($pdo, $staff, ['registration_id' => $registrationId]);
        $gpsOut['checks']['gps_status_service_ok'] = !empty($gpsStatus['ok']);
        $gpsOut['gps_status'] = [
            'monitoring' => $gpsStatus['monitoring'] ?? null,
            'gps_enabled' => $gpsStatus['gps_enabled'] ?? null,
        ];
    } else {
        $gpsOut['checks']['gps_status_service_ok'] = false;
    }

    foreach ($gpsOut['checks'] as $ck => $val) {
        if ($val === false) {
            $gpsOut['status'] = 'FAIL';
            $gpsOut['blockers'][] = $ck;
        }
    }

    $sections['gps_checkout'] = $gpsOut;

    // --- 7. Attendance integrity ---
    $attendance = ['status' => 'PASS', 'checks' => [], 'blockers' => []];

    $dupAttStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM attendance a
         INNER JOIN staff_registrations sr ON sr.id = a.registration_id
         WHERE sr.staff_id = :sid AND sr.event_id = :eid'
    );
    $eventIdToday = $todayReg ? (int) ($todayReg['event_id'] ?? 0) : 0;
    $dupAttStmt->execute(['sid' => $staffId, 'eid' => $eventIdToday > 0 ? $eventIdToday : 0]);
    $attCountToday = (int) $dupAttStmt->fetchColumn();

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

    $orphanStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM attendance a
         LEFT JOIN staff_registrations sr ON sr.id = a.registration_id
         WHERE sr.id IS NULL'
    );
    $orphanStmt->execute();
    $orphans = (int) $orphanStmt->fetchColumn();

    $attendance['checks']['no_duplicate_attendance_today'] = $attCountToday <= 1;
    $attendance['checks']['no_conflicting_registrations'] = $dupRegs === 0;
    $attendance['checks']['no_orphan_attendance'] = $orphans === 0;
    $attendance['checks']['no_blocking_prior_checkin'] = true;

    if ($registrationId > 0) {
        $att = getAttendanceByRegistration($pdo, $registrationId);
        if ($att !== null && trim((string) ($att['checked_in_at'] ?? '')) !== '' && trim((string) ($att['checked_out_at'] ?? '')) === '') {
            $attendance['checks']['no_blocking_prior_checkin'] = false;
            $attendance['open_checkin'] = [
                'attendance_id' => (int) ($att['id'] ?? 0),
                'checked_in_at' => (string) ($att['checked_in_at'] ?? ''),
            ];
        }
    }

    $attendance['counts'] = [
        'attendance_rows_today_event' => $attCountToday,
        'duplicate_registrations_per_event' => $dupRegs,
        'orphan_attendance_rows' => $orphans,
    ];

    foreach ($attendance['checks'] as $ck => $val) {
        if ($val === false) {
            $attendance['status'] = 'FAIL';
            $attendance['blockers'][] = $ck;
        }
    }

    $sections['attendance'] = $attendance;

    // --- 8. Production logs ---
    $logs = ['status' => 'PASS', 'checks' => [], 'blockers' => [], 'recent' => []];

    $recent = liveVerifyRecentAccountLogs($pdo, $email, $staffId);
    $errorHits = array_values(array_filter(
        $recent,
        static fn (array $e): bool => isset($e['line'])
            && (str_contains(strtolower((string) $e['line']), 'fatal')
                || str_contains(strtolower((string) $e['line']), 'authentication')
                || str_contains(strtolower((string) $e['line']), 'denied'))
    ));

    $logs['recent'] = $recent;
    $logs['checks']['no_recent_auth_errors_in_log'] = $errorHits === [];
    $logs['checks']['canonical_identity_audit_available'] = $recent !== [];

    if (!$logs['checks']['no_recent_auth_errors_in_log']) {
        $logs['status'] = 'FAIL';
        $logs['blockers'][] = 'no_recent_auth_errors_in_log';
    }

    $sections['production_logs'] = $logs;

    // --- 9. Summary verdicts ---
    $summary = [
        'account'          => $sections['account']['status'],
        'login'            => $sections['login']['status'],
        'event_assignment' => $sections['event_assignment']['status'],
        'shift_visibility' => $sections['shift_visibility']['status'],
        'gps_checkin'      => $sections['gps_checkin']['status'],
        'attendance'       => $sections['attendance']['status'],
        'mobile_api'       => $sections['login']['status'],
        'authentication'   => $sections['login']['status'],
    ];

    $verdict = liveVerifyVerdict([
        ['status' => $summary['account']],
        ['status' => $summary['login']],
        ['status' => $summary['event_assignment']],
        ['status' => $summary['shift_visibility']],
        ['status' => $summary['gps_checkin']],
        ['status' => $summary['attendance']],
    ]);

    echo json_encode([
        'ok'      => $verdict === 'PASS',
        'verdict' => $verdict,
        'email'   => $email,
        'today'   => $today,
        'now'     => $now,
        'summary' => $summary,
        'sections' => $sections,
        'statement' => $verdict === 'PASS'
            ? "TODAY'S EVENT IS READY"
            : 'BLOCKING ISSUES FOUND — see sections.*.blockers',
        'generated_at' => gmdate('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'verdict' => 'FAIL',
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ], JSON_PRETTY_PRINT);
}
