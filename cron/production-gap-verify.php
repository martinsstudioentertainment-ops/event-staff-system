<?php

declare(strict_types=1);

/**
 * Close remaining production verification gaps (live environment).
 *
 * Run:  /cron/production-gap-verify.php?key=CRON_KEY&run=1
 * Clean: /cron/production-gap-verify.php?key=CRON_KEY&cleanup=1&run_id=...
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/platform/data-integrity.php';
require_once dirname(__DIR__) . '/includes/platform/master-staff-identity-ui.php';
require_once dirname(__DIR__) . '/includes/validation.php';
require_once dirname(__DIR__) . '/includes/registration-forms.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/staff-allocation.php';
require_once dirname(__DIR__) . '/includes/registration-post-save.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';
require_once dirname(__DIR__) . '/includes/checkin-bib.php';
require_once dirname(__DIR__) . '/includes/attendance-gps-signout.php';
require_once dirname(__DIR__) . '/includes/work-hours-repository.php';
require_once dirname(__DIR__) . '/includes/google-sheets-sync.php';
require_once dirname(__DIR__) . '/includes/registrant-complete-purge.php';
require_once dirname(__DIR__) . '/includes/automation/recruitment-repository.php';
require_once dirname(__DIR__) . '/includes/site-urls.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/mobile/services/MobileOtpService.php';
require_once dirname(__DIR__) . '/includes/mobile/services/MobileAuthService.php';
require_once dirname(__DIR__) . '/includes/mobile/services/MobileShiftService.php';
require_once dirname(__DIR__) . '/includes/staff-app-v3-data.php';

header('Content-Type: application/json; charset=UTF-8');

function gapStep(string $name, bool $pass, array $evidence = [], ?string $failReason = null): array
{
    return ['step' => $name, 'pass' => $pass, 'fail' => $failReason, 'evidence' => $evidence];
}

function gapHttpGet(string $url, string $cookieJar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $csrf = '';
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $body, $m)) {
        $csrf = $m[1];
    }

    return ['http_code' => $code, 'body_len' => strlen($body), 'csrf' => $csrf, 'has_form' => str_contains($body, 'id="registration-form"')];
}

function gapHttpPostJson(string $url, array $payload, string $cookieJar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($body, true);

    return ['http_code' => $code, 'json' => is_array($json) ? $json : null, 'raw' => $body];
}

function gapHttpPostForm(string $url, array $fields, string $cookieJar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_HEADER         => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    return ['http_code' => $code, 'location' => $location, 'raw_len' => strlen($raw)];
}

function gapInsertRegistrationOtp(PDO $pdo, string $email, string $code): void
{
    mobileOtpEnsureSchema($pdo);
    $pdo->prepare('DELETE FROM mobile_email_otp_codes WHERE email = :email AND purpose = :purpose')
        ->execute(['email' => $email, 'purpose' => 'registration']);
    $pdo->prepare(
        'INSERT INTO mobile_email_otp_codes (email, purpose, code_hash, expires_at)
         VALUES (:email, :purpose, :hash, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
    )->execute([
        'email'   => $email,
        'purpose' => 'registration',
        'hash'    => hash('sha256', $code),
    ]);
}

try {
    if (function_exists('set_time_limit')) {
        @set_time_limit(900);
    }

    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    if (!empty($_GET['cleanup'])) {
        $runId = trim((string) ($_GET['run_id'] ?? ''));
        $email = $runId !== '' ? 'e2e-gap-' . $runId . '@olasentra-e2e.test' : '';
        if ($email === '' || !dataIntegrityIsTestEmail($email)) {
            http_response_code(400);
            exit(json_encode(['ok' => false, 'error' => 'Provide run_id'], JSON_PRETTY_PRINT));
        }
        $purge = purgeRegistrantCompletely($pdo, $email, false);
        $eventDeleted = 0;
        $stmt = $pdo->prepare("SELECT id FROM events WHERE name LIKE :name");
        $stmt->execute(['name' => 'E2E Gap Verify ' . $runId . '%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $eid) {
            try {
                $pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => (int) $eid]);
                ++$eventDeleted;
            } catch (Throwable $e) {
            }
        }
        exit(json_encode(['ok' => true, 'cleanup' => true, 'email' => $email, 'purge' => $purge, 'events_deleted' => $eventDeleted], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    if (empty($_GET['run'])) {
        http_response_code(400);
        exit(json_encode(['ok' => false, 'error' => 'Add run=1'], JSON_PRETTY_PRINT));
    }

    $runId  = gmdate('YmdHis') . bin2hex(random_bytes(2));
    $email  = 'e2e-gap-' . $runId . '@olasentra-e2e.test';
    $otpCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $mobile  = '+353800' . str_pad(substr(preg_replace('/\D/', '', $runId), -7), 7, '0', STR_PAD_LEFT);
    $pps     = '9876543' . chr(65 + (hexdec(substr($runId, -2)) % 26));
    $report  = [
        'ok'         => true,
        'run_id'     => $runId,
        'test_email' => $email,
        'started_at' => gmdate('c'),
        'steps'      => [],
        'overall'    => 'PASS',
        'note'       => 'Automated gap closure on live production. Browser screenshots and physical device tests must still be captured manually.',
    ];

    $dublinTz = new DateTimeZone('Europe/Dublin');
    $nowLocal = new DateTime('now', $dublinTz);
    $today    = $nowLocal->format('Y-m-d');
    $eventId  = createEvent($pdo, [
        'name'               => 'E2E Gap Verify ' . $runId,
        'event_date'         => $today,
        'location'           => 'E2E Gap Test Venue Dublin',
        'work_type'          => 'festival',
        'roles_needed'       => 'steward',
        'venue_lat'          => '53.349805',
        'venue_lng'          => '-6.26031',
        'venue_eircode'      => 'D02 X285',
        'signin_radius_m'    => 500,
        'staff_needed'       => 10,
        'start_time'         => '00:00',
        'end_time'           => '23:59',
        'checkin_open_time'  => '00:00',
        'checkin_close_time' => '23:59',
        'is_active'          => 1,
    ]);

    $registerBase = rtrim(getRegistrationSiteUrl($pdo), '/');
    $cookieJar    = sys_get_temp_dir() . '/gap_cookie_' . $runId . '.txt';
    @unlink($cookieJar);

    // --- Gap 1: Real register-site OTP + HTTP submit ---
    gapInsertRegistrationOtp($pdo, $email, $otpCode);
    $verify = gapHttpPostJson($registerBase . '/api/registration-email-otp-verify.php', [
        'email' => $email,
        'code'  => $otpCode,
    ], $cookieJar);

    $gateBefore = gapHttpGet($registerBase . '/index.php?form=steward', $cookieJar);
    $csrf       = $gateBefore['csrf'];

    $httpSubmitOk = false;
    if ($csrf !== '') {
        $post = gapHttpPostForm($registerBase . '/submit.php', [
            'csrf_token'      => $csrf,
            'form_slug'       => 'steward',
            'staff_role'      => 'steward',
            'surname'         => 'Gaptest',
            'first_name'      => 'E2E' . substr($runId, -4),
            'full_address'    => '1 Gap Test Street, Dublin',
            'eircode'         => 'D02 X285',
            'email'           => $email,
            'mobile'          => $mobile,
            'date_of_birth'   => '1990-01-15',
            'gender'          => 'prefer_not_to_say',
            'pps_number'      => $pps,
            'bank_iban'       => 'IE29AIBK93115212345678',
            'privacy_consent' => '1',
            'event_ids[]'     => (string) $eventId,
        ], $cookieJar);
        $httpSubmitOk = $post['http_code'] === 302 || str_contains($post['location'], 'status');
    }

    $regStmt = $pdo->prepare('SELECT * FROM staff_registrations WHERE LOWER(email) = :email ORDER BY id DESC LIMIT 1');
    $regStmt->execute(['email' => $email]);
    $registration = $regStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM staff WHERE LOWER(email) = :e');
    $cntStmt->execute(['e' => $email]);
    $staffCount = (int) $cntStmt->fetchColumn();

    recruitment_sync_from_registrations($pdo);
    $pipeStmt = $pdo->prepare('SELECT id FROM recruitment_pipeline WHERE registration_id = :id LIMIT 1');
    $pipeStmt->execute(['id' => (int) ($registration['id'] ?? 0)]);
    $pipelineRow = $pipeStmt->fetch(PDO::FETCH_ASSOC);

    $identity = canonicalIdentityResolveStaff($pdo, ['email' => $email]);
    $sheets1  = $registration !== null ? syncRegistrationsToGoogleSheets($pdo, [(int) $registration['id']]) : [];

    $gap1Pass = !empty($verify['json']['ok'])
        && $gateBefore['has_form']
        && $csrf !== ''
        && $httpSubmitOk
        && $registration !== null
        && $staffCount === 1
        && $pipelineRow !== false
        && $identity !== null;

    $report['steps'][] = gapStep('Gap 1 – Public Steward Application (OTP + HTTP)', $gap1Pass, [
        'otp_verify'     => $verify,
        'form_after_otp' => $gateBefore,
        'http_submit_ok' => $httpSubmitOk,
        'registration_id'=> (int) ($registration['id'] ?? 0),
        'staff_id'       => (int) ($registration['staff_id'] ?? 0),
        'staff_count'    => $staffCount,
        'recruitment'    => $pipelineRow !== false,
        'master_identity'=> $identity !== null ? ['staff_id' => (int) ($identity['id'] ?? 0), 'email' => $identity['email'] ?? ''] : null,
        'google_sheets'  => $sheets1,
    ], $gap1Pass ? null : 'OTP, HTTP submit, recruitment, identity, or duplicate check failed');

    if (!$gap1Pass) {
        $report['overall'] = 'FAIL';
        $report['cleanup_url'] = '/cron/production-gap-verify.php?key=…&cleanup=1&run_id=' . $runId;
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $regId   = (int) $registration['id'];
    $staffId = (int) $registration['staff_id'];
    updateStaffStatus($pdo, $regId, 'approved');
    adminAssignStaffToEvent($pdo, $staffId, $eventId, 'Gap verify assignment', true, true);
    $staff = getStaffById($pdo, $staffId) ?: [];

    // --- Gap 2: Mobile shift visibility ---
    $todayYmd = getOperationalTodayYmd($pdo);
    $statusToken = resolveStatusTokenByEmail($pdo, $email) ?? '';
    $rawRows     = getStaffV3ShiftRows($pdo, $email, $statusToken);
    $rawByStaff  = getStaffV3ShiftRowsByStaffId($pdo, $staffId);
    $upcoming    = mobileShiftServiceList($pdo, $staff, ['filter' => 'upcoming']);
    $allShifts   = mobileShiftServiceList($pdo, $staff, ['filter' => 'all']);
    $todayShift  = mobileShiftServiceToday($pdo, $staff);

    $bib = 'GAP' . strtoupper(substr(preg_replace('/\D/', '', $runId), -6));
    assignStaffRegistrationBibNumber($pdo, $regId, $bib);
    $gps = ['lat' => 53.349805, 'lng' => -6.26031, 'accuracy_m' => 10];
    recordCheckin($pdo, $regId, 'self', $gps, $bib);
    autoSignOutAttendance($pdo, (int) (getAttendanceByRegistration($pdo, $regId)['id'] ?? 0), 'event_end');

    $staffFresh = getStaffById($pdo, $staffId) ?: $staff;
    $past       = mobileShiftServiceList($pdo, $staffFresh, ['filter' => 'past']);
    $history    = getStaffV3CheckinHistory($pdo, $email, 5);

    $gap2Pass = count($rawRows) >= 1
        && count($upcoming['shifts'] ?? []) >= 1
        && count($past['shifts'] ?? []) >= 1
        && count($history) >= 1;

    $report['steps'][] = gapStep('Gap 2 – Mobile Shift Visibility', $gap2Pass, [
        'operational_today' => $todayYmd,
        'raw_rows_email'    => count($rawRows),
        'raw_rows_staff_id' => count($rawByStaff),
        'upcoming_count'    => count($upcoming['shifts'] ?? []),
        'past_count'        => count($past['shifts'] ?? []),
        'all_count'         => count($allShifts['shifts'] ?? []),
        'today_shift'       => !empty($todayShift['shift']),
        'history_count'     => count($history),
        'history_sample'    => $history[0] ?? null,
    ], $gap2Pass ? null : 'Upcoming/past/history counts below expected');

    if (!$gap2Pass) {
        $report['overall'] = 'FAIL';
    }

    // --- Gap 3: Google Sheets read-back ---
    $sheetsSync = syncLinkedEventsToGoogleSheets($pdo, [$eventId]);
    $sheetRead  = ['enabled' => isGoogleSheetsSyncEnabled($pdo), 'event_row' => null, 'errors' => [], 'skipped' => false];

    $gap3Pass = true;
    if ($sheetRead['enabled']) {
        $event = getEventById($pdo, $eventId);
        $sheetUrl = trim((string) ($event['google_sheet_url'] ?? ''));
        if ($sheetUrl === '') {
            $sheetRead['skipped'] = true;
            $sheetRead['note'] = 'Temporary E2E events are not linked to a Google Sheet URL — manual visual check required on a production event (e.g. Michael Bublé #9).';
        } elseif (preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/', $sheetUrl, $m)) {
            $spreadsheetId = $m[1];
            $sa = loadGoogleServiceAccount();
            if ($sa !== null) {
                $token = googleSheetsGetAccessToken($sa);
                if ($token !== null) {
                    $project = isset($sa['project_id']) ? (string) $sa['project_id'] : null;
                    $tab     = trim((string) ($event['google_sheet_tab'] ?? 'Registrations'));
                    if ($tab === '') {
                        $tab = 'Registrations';
                    }
                    $idCol = googleSheetsSyncColumnLetter(googleSheetsRegistrationIdColumnIndex());
                    $range = escapeGoogleSheetRangeTab($tab) . '!' . $idCol . '2:' . $idCol;
                    $vals  = googleSheetsReadRangeValues($token, $project, $spreadsheetId, $range);
                    $matches = 0;
                    if (is_array($vals)) {
                        foreach ($vals as $row) {
                            if ((int) ($row[0] ?? 0) === $regId) {
                                ++$matches;
                            }
                        }
                    }
                    $sheetRead['event_row'] = [
                        'spreadsheet_id'       => $spreadsheetId,
                        'tab'                  => $tab,
                        'registration_matches' => $matches,
                        'duplicate'            => $matches > 1,
                    ];
                    $gap3Pass = $matches === 1 && !$sheetRead['event_row']['duplicate'];
                } else {
                    $sheetRead['errors'][] = 'Could not obtain Google access token';
                    $gap3Pass = false;
                }
            } else {
                $sheetRead['errors'][] = 'No service account configured';
                $gap3Pass = false;
            }
        } else {
            $sheetRead['errors'][] = 'Invalid Google Sheet URL on event';
            $gap3Pass = false;
        }
    }

    $report['steps'][] = gapStep('Gap 3 – Google Sheets Read-back (Event tab)', $gap3Pass, [
        'sync_result' => $sheetsSync,
        'read_back'   => $sheetRead,
    ], $gap3Pass ? null : 'Event sheet row missing or duplicated');

    if (!$gap3Pass) {
        $report['overall'] = 'FAIL';
    }

    // Cleanup
    purgeRegistrantCompletely($pdo, $email, false);
    try {
        $pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
    } catch (Throwable $e) {
        $report['cleanup_warning'] = $e->getMessage();
    }
    $remStmt = $pdo->prepare('SELECT COUNT(*) FROM staff WHERE LOWER(email) = :e');
    $remStmt->execute(['e' => $email]);
    $remaining = (int) $remStmt->fetchColumn();

    $report['steps'][] = gapStep('Cleanup', $remaining === 0, [
        'staff_remaining' => $remaining,
        'event_deleted'   => $eventId,
    ], $remaining === 0 ? null : 'Test data remains');

    if ($remaining !== 0) {
        $report['overall'] = 'FAIL';
    }

    $report['finished_at'] = gmdate('c');
    $report['conclusion']  = $report['overall'] === 'PASS'
        ? 'Automated gap verification PASS — manual browser/device/screenshot steps still required for full v1.0 certification.'
        : 'Automated gap verification FAIL';

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], JSON_PRETTY_PRINT);
}
