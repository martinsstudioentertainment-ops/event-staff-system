<?php

declare(strict_types=1);

/**
 * Read-only Mobile API QA runner for admin sign-off (no DB writes, no refresh tokens).
 */

require_once __DIR__ . '/schema/mobile-api-schema.php';
require_once __DIR__ . '/mobile-auth.php';
require_once __DIR__ . '/services/MobileConfigService.php';
require_once __DIR__ . '/services/MobileProfileService.php';
require_once __DIR__ . '/services/MobileDashboardService.php';
require_once __DIR__ . '/services/MobileShiftService.php';
require_once __DIR__ . '/services/MobileNotificationService.php';
require_once __DIR__ . '/services/MobileMessageService.php';
require_once __DIR__ . '/services/MobileDocumentService.php';
require_once __DIR__ . '/services/MobileAvailabilityService.php';
require_once __DIR__ . '/mappers/MobileDocumentMapper.php';
require_once __DIR__ . '/../staff-repository.php';
require_once __DIR__ . '/../staff-google-oauth.php';
require_once __DIR__ . '/../site-urls.php';
require_once __DIR__ . '/../settings-repository.php';

/**
 * @return list<array<string, mixed>>
 */
function mobileQaListApprovedStaff(PDO $pdo, int $limit = 500): array
{
    $rows = getStaffWithFilters($pdo, ['blacklisted' => false], $limit);

    return array_values(array_filter(
        $rows,
        static fn (array $row): bool => (int) ($row['approved_count'] ?? 0) > 0
            && (int) ($row['is_blacklisted'] ?? 0) !== 1
    ));
}

function mobileQaOutputDir(): string
{
    $root      = dirname(__DIR__, 2);
    $preferred = $root . '/docs/api/mobile/signoff-screenshots';
    $fallback  = $root . '/storage/mobile-api-qa-signoff';

    foreach ([$preferred, $fallback] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            return $dir;
        }
    }

    return is_dir($fallback) ? $fallback : $preferred;
}

/**
 * @return array{ok: bool, message?: string, path?: string}
 */
function mobileQaEnsureWritableOutputDir(): array
{
    $dir = mobileQaOutputDir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'message' => 'Could not create output directory: ' . $dir];
    }
    if (!is_writable($dir)) {
        return ['ok' => false, 'message' => 'Output directory is not writable: ' . $dir];
    }

    return ['ok' => true, 'path' => $dir];
}

function mobileQaExistingJwtSecret(PDO $pdo): ?string
{
    $secret = trim(getSetting($pdo, 'mobile_jwt_secret', ''));

    return $secret !== '' ? $secret : null;
}

function mobileQaIssueAccessToken(PDO $pdo, array $staff): ?string
{
    if (mobileQaExistingJwtSecret($pdo) === null) {
        return null;
    }

    return mobileIssueAccessToken($pdo, $staff);
}

/**
 * Read-only Google sign-in eligibility (mirrors authenticateStaffPortalByGoogleEmail without writes).
 *
 * @return array{ok: bool, message: string}
 */
function mobileQaGoogleEligibility(PDO $pdo, array $staff): array
{
    $email = strtolower(trim((string) ($staff['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Invalid staff email.'];
    }

    if (getStaffById($pdo, (int) ($staff['id'] ?? 0)) === null) {
        return ['ok' => false, 'message' => 'Staff record not found.'];
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM staff_registrations WHERE LOWER(email) = :email AND status != 'rejected'"
    );
    $stmt->execute(['email' => $email]);
    $regCount = (int) $stmt->fetchColumn();

    if ($regCount < 1 && (int) ($staff['profile_completed'] ?? 0) !== 1) {
        return [
            'ok'      => false,
            'message' => 'Gmail not on staff list — register for a shift first.',
        ];
    }

    return ['ok' => true, 'message' => 'Eligible for Google sign-in path'];
}

/**
 * @param array{group: string, name: string, status: string, detail: string} $row
 */
function mobileQaRecord(array &$results, array &$counts, string $group, string $name, bool $pass, string $detail = ''): void
{
    $status = $pass ? 'PASS' : 'FAIL';
    $results[] = [
        'group'  => $group,
        'name'   => $name,
        'status' => $status,
        'detail' => $detail,
    ];
    if ($pass) {
        $counts['passed']++;
    } else {
        $counts['failed']++;
    }
    $counts['total']++;
}

function mobileQaHttpGet(string $url, ?string $bearerToken = null, int $timeout = 25): array
{
    if (!function_exists('curl_init')) {
        return ['code' => 0, 'json' => null, 'err' => 'cURL not available'];
    }

    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($bearerToken !== null && $bearerToken !== '') {
        $headers[] = 'Authorization: Bearer ' . $bearerToken;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => min($timeout, 12),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $ca = dirname(__DIR__, 2) . '/cacert.pem';
    if (is_file($ca)) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if (is_string($raw) && str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }

    $json = null;
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode(trim($raw), true);
        if (is_array($decoded)) {
            $json = $decoded;
        }
    }

    return [
        'code' => $code,
        'json' => $json,
        'err'  => $err,
        'raw'  => is_string($raw) ? $raw : '',
    ];
}

/**
 * Run read-only Mobile API QA for an approved staff account.
 *
 * @return array<string, mixed>
 */
function mobileQaRun(PDO $pdo, int $staffId, ?string $googleIdToken = null): array
{
    $results = [];
    $counts  = ['passed' => 0, 'failed' => 0, 'total' => 0];

    $staff = getStaffById($pdo, $staffId);
    if ($staff === null) {
        return [
            'ok'      => false,
            'message' => 'Staff not found.',
            'results' => [],
            'summary' => $counts,
        ];
    }

    $email = strtolower(trim((string) ($staff['email'] ?? '')));
    $name  = trim((string) ($staff['first_name'] ?? '') . ' ' . (string) ($staff['surname'] ?? ''));
    $baseUrl = rtrim(getRegistrationSiteUrl($pdo), '/') . '/api/mobile/v1';
    $runId   = date('Ymd-His') . '-staff-' . $staffId;

    // --- Config ---
    $config = mobileConfigServiceGetPublic($pdo);
    mobileQaRecord(
        $results,
        $counts,
        'Config',
        'Mobile API enabled',
        !empty($config['mobile_api_enabled']),
        !empty($config['mobile_api_enabled']) ? 'Enabled' : 'Disabled in settings'
    );
    mobileQaRecord(
        $results,
        $counts,
        'Config',
        'Google sign-in enabled',
        !empty($config['google_signin_enabled']),
        !empty($config['google_signin_required']) ? 'Required' : 'Optional'
    );

    // --- Google authentication (eligibility only — no token issuance) ---
    mobileQaRecord(
        $results,
        $counts,
        'Google Auth',
        'Staff email eligible for Google sign-in',
        isStaffGoogleSigninEnabled($pdo) && $email !== '',
        $email !== '' ? $email : 'No email on staff record'
    );

    $googleAuth = mobileQaGoogleEligibility($pdo, $staff);
    mobileQaRecord(
        $results,
        $counts,
        'Google Auth',
        'Staff eligible for Google sign-in path',
        !empty($googleAuth['ok']),
        (string) ($googleAuth['message'] ?? '')
    );

    mobileQaRecord(
        $results,
        $counts,
        'Google Auth',
        'Staff not blacklisted',
        !mobileStaffIsBlacklisted($pdo, $email, $staff),
        mobileStaffIsBlacklisted($pdo, $email, $staff) ? 'Blacklisted' : 'OK'
    );

    $googleToken = trim((string) ($googleIdToken ?? ''));
    if ($googleToken !== '') {
        $verified = mobileVerifyGoogleIdToken($pdo, $googleToken);
        $tokenEmail = strtolower(trim((string) ($verified['email'] ?? '')));
        $emailMatch = $verified['ok'] && $tokenEmail === $email;
        mobileQaRecord(
            $results,
            $counts,
            'Google Auth',
            'Google ID token verifies',
            !empty($verified['ok']),
            (string) ($verified['message'] ?? $tokenEmail)
        );
        mobileQaRecord(
            $results,
            $counts,
            'Google Auth',
            'Google token email matches staff',
            $emailMatch,
            $emailMatch ? $tokenEmail : 'Token: ' . $tokenEmail . ' · Staff: ' . $email
        );
    } else {
        mobileQaRecord(
            $results,
            $counts,
            'Google Auth',
            'Google ID token (optional live verify)',
            true,
            'Skipped — paste token to verify live Google OAuth (read-only; no login issued)'
        );
    }

    // --- JWT (in-memory only; no refresh token rows) ---
    $jwtSecret = mobileQaExistingJwtSecret($pdo);
    mobileQaRecord(
        $results,
        $counts,
        'JWT',
        'JWT secret configured',
        $jwtSecret !== null,
        $jwtSecret !== null ? substr($jwtSecret, 0, 8) . '…' : 'Generate in Settings → Mobile API'
    );

    $accessToken = mobileQaIssueAccessToken($pdo, $staff);
    mobileQaRecord(
        $results,
        $counts,
        'JWT',
        'Access token issued',
        $accessToken !== null && $accessToken !== '',
        $accessToken !== null ? strlen($accessToken) . ' chars' : 'Could not issue'
    );

    $payload = ($accessToken !== null && $jwtSecret !== null)
        ? mobileJwtDecode($accessToken, $jwtSecret)
        : null;
    mobileQaRecord(
        $results,
        $counts,
        'JWT',
        'Token decodes with server secret',
        $payload !== null,
        $payload !== null ? 'Valid signature and expiry' : 'Decode failed'
    );

    if ($payload !== null) {
        $subOk  = (int) ($payload['sub'] ?? 0) === $staffId;
        $audOk  = ($payload['aud'] ?? '') === 'olasentra-mobile';
        $emailOk = strtolower(trim((string) ($payload['email'] ?? ''))) === $email;
        mobileQaRecord($results, $counts, 'JWT', 'Token subject matches staff id', $subOk, 'sub=' . (int) ($payload['sub'] ?? 0));
        mobileQaRecord($results, $counts, 'JWT', 'Token audience olasentra-mobile', $audOk, (string) ($payload['aud'] ?? ''));
        mobileQaRecord($results, $counts, 'JWT', 'Token email matches staff', $emailOk, (string) ($payload['email'] ?? ''));
    }

    // --- Service layer (read-only GET equivalents) ---
    if (!mobileApiIsEnabled($pdo)) {
        mobileQaRecord($results, $counts, 'Gate', 'Mobile API disabled — skipping endpoint tests', false, 'Enable Mobile API first');
    } else {
        $profile = mobileProfileServiceBuild($pdo, $staff);
        mobileQaRecord(
            $results,
            $counts,
            'Profile API',
            'GET /me (service)',
            !empty($profile['ok']) && is_array($profile['staff'] ?? null),
            !empty($profile['ok']) ? 'staff.id=' . (int) ($profile['staff']['id'] ?? 0) : (string) ($profile['message'] ?? 'Error')
        );

        $dashboard = mobileDashboardServiceBuild($pdo, $staff);
        mobileQaRecord(
            $results,
            $counts,
            'Dashboard API',
            'GET /dashboard (service)',
            !empty($dashboard['ok']) && isset($dashboard['profile']),
            !empty($dashboard['ok']) ? 'profile + shifts payload' : (string) ($dashboard['message'] ?? 'Error')
        );

        $shifts = mobileShiftServiceList($pdo, $staff, ['filter' => 'all', 'page' => '1', 'per_page' => '20']);
        mobileQaRecord(
            $results,
            $counts,
            'Shifts API',
            'GET /shifts (service)',
            !empty($shifts['ok']) && is_array($shifts['shifts'] ?? null),
            !empty($shifts['ok']) ? count($shifts['shifts']) . ' shift(s)' : (string) ($shifts['message'] ?? 'Error')
        );

        $today = mobileShiftServiceToday($pdo, $staff);
        mobileQaRecord(
            $results,
            $counts,
            'Shifts API',
            'GET /shifts/today (service)',
            !empty($today['ok']),
            !empty($today['ok']) ? ((isset($today['shift']) && $today['shift'] !== null) ? 'Has today shift' : 'No shift today') : (string) ($today['message'] ?? 'Error')
        );

        $messages = mobileMessageServiceList($pdo, $staff, ['limit' => '50']);
        mobileQaRecord(
            $results,
            $counts,
            'Messages API',
            'GET /messages (service, read-only)',
            !empty($messages['ok']) && is_array($messages['thread'] ?? null),
            !empty($messages['ok']) ? count($messages['thread']) . ' message(s)' : (string) ($messages['message'] ?? 'Error')
        );

        $notifications = mobileNotificationServiceList($pdo, $staff, ['page' => '1', 'per_page' => '20']);
        mobileQaRecord(
            $results,
            $counts,
            'Notifications API',
            'GET /notifications (service, read-only)',
            !empty($notifications['ok']) && is_array($notifications['notifications'] ?? null),
            !empty($notifications['ok']) ? count($notifications['notifications']) . ' notification(s)' : (string) ($notifications['message'] ?? 'Error')
        );

        $documents = mobileDocumentServiceList($pdo, $staff);
        mobileQaRecord(
            $results,
            $counts,
            'Documents API',
            'GET /documents (service)',
            !empty($documents['ok']) && is_array($documents['documents'] ?? null),
            !empty($documents['ok']) ? (int) ($documents['summary']['total'] ?? 0) . ' document slot(s)' : (string) ($documents['message'] ?? 'Error')
        );

        $fileKey = 'psa_front';
        $fileMeta = mobileDocumentResolveFileMeta($staff, $fileKey);
        $fileOk = $fileMeta === null
            || (psaImageFilesystemPath((string) $fileMeta['path']) !== '' && is_readable(psaImageFilesystemPath((string) $fileMeta['path'])));
        mobileQaRecord(
            $results,
            $counts,
            'Documents API',
            'GET /documents/psa_front/file (metadata)',
            $fileOk,
            $fileMeta === null ? 'No PSA front on file (404 expected)' : 'File readable on disk'
        );

        $month = date('Y-m');
        $availability = mobileAvailabilityServiceGetMonth($pdo, $staff, ['month' => $month]);
        mobileQaRecord(
            $results,
            $counts,
            'Availability API',
            'GET /availability (service, read-only)',
            !empty($availability['ok']) && is_array($availability['days'] ?? null),
            !empty($availability['ok']) ? 'month=' . $month . ', ' . count($availability['days']) . ' day(s)' : (string) ($availability['message'] ?? 'Error')
        );

        // --- HTTP layer (read-only GET) ---
        if ($accessToken !== null) {
            $httpMe = mobileQaHttpGet($baseUrl . '/me', $accessToken);
            mobileQaRecord(
                $results,
                $counts,
                'Profile API',
                'GET /me (HTTP)',
                $httpMe['code'] === 200 && is_array($httpMe['json'] ?? null) && is_array($httpMe['json']['staff'] ?? null),
                $httpMe['err'] !== '' ? $httpMe['err'] : 'HTTP ' . $httpMe['code']
            );

            $httpDash = mobileQaHttpGet($baseUrl . '/dashboard', $accessToken);
            mobileQaRecord(
                $results,
                $counts,
                'Dashboard API',
                'GET /dashboard (HTTP)',
                $httpDash['code'] === 200 && is_array($httpDash['json'] ?? null) && isset($httpDash['json']['profile']),
                $httpDash['err'] !== '' ? $httpDash['err'] : 'HTTP ' . $httpDash['code']
            );

            $httpShifts = mobileQaHttpGet($baseUrl . '/shifts?filter=all&page=1&per_page=20', $accessToken);
            mobileQaRecord(
                $results,
                $counts,
                'Shifts API',
                'GET /shifts (HTTP)',
                $httpShifts['code'] === 200 && is_array($httpShifts['json'] ?? null) && is_array($httpShifts['json']['shifts'] ?? null),
                $httpShifts['err'] !== '' ? $httpShifts['err'] : 'HTTP ' . $httpShifts['code']
            );

            $httpMsgs = mobileQaHttpGet($baseUrl . '/messages?limit=50', $accessToken);
            mobileQaRecord(
                $results,
                $counts,
                'Messages API',
                'GET /messages (HTTP)',
                $httpMsgs['code'] === 200 && is_array($httpMsgs['json'] ?? null) && is_array($httpMsgs['json']['thread'] ?? null),
                $httpMsgs['err'] !== '' ? $httpMsgs['err'] : 'HTTP ' . $httpMsgs['code']
            );

            $httpNotifs = mobileQaHttpGet($baseUrl . '/notifications?page=1&per_page=20', $accessToken);
            mobileQaRecord(
                $results,
                $counts,
                'Notifications API',
                'GET /notifications (HTTP)',
                $httpNotifs['code'] === 200 && is_array($httpNotifs['json'] ?? null) && is_array($httpNotifs['json']['notifications'] ?? null),
                $httpNotifs['err'] !== '' ? $httpNotifs['err'] : 'HTTP ' . $httpNotifs['code']
            );

            $httpDocs = mobileQaHttpGet($baseUrl . '/documents', $accessToken);
            mobileQaRecord(
                $results,
                $counts,
                'Documents API',
                'GET /documents (HTTP)',
                $httpDocs['code'] === 200 && is_array($httpDocs['json'] ?? null) && is_array($httpDocs['json']['documents'] ?? null),
                $httpDocs['err'] !== '' ? $httpDocs['err'] : 'HTTP ' . $httpDocs['code']
            );

            $httpDocFile = mobileQaHttpGet($baseUrl . '/documents/psa_front/file', $accessToken);
            mobileQaRecord(
                $results,
                $counts,
                'Documents API',
                'GET /documents/psa_front/file (HTTP)',
                in_array($httpDocFile['code'], [200, 404], true),
                $httpDocFile['err'] !== '' ? $httpDocFile['err'] : 'HTTP ' . $httpDocFile['code']
            );

            $httpAvail = mobileQaHttpGet($baseUrl . '/availability?month=' . $month, $accessToken);
            mobileQaRecord(
                $results,
                $counts,
                'Availability API',
                'GET /availability (HTTP)',
                $httpAvail['code'] === 200 && is_array($httpAvail['json'] ?? null) && is_array($httpAvail['json']['days'] ?? null),
                $httpAvail['err'] !== '' ? $httpAvail['err'] : 'HTTP ' . $httpAvail['code']
            );
        }
    }

    $overall = $counts['failed'] === 0 ? 'PASS' : 'FAIL';

    return [
        'ok'        => true,
        'run_id'    => $runId,
        'timestamp' => date('c'),
        'overall'   => $overall,
        'base_url'  => $baseUrl,
        'staff'     => [
            'id'    => $staffId,
            'email' => $email,
            'name'  => $name,
        ],
        'read_only' => true,
        'summary'   => $counts,
        'results'   => $results,
    ];
}
