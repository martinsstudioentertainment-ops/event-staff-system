<?php

declare(strict_types=1);

require_once __DIR__ . '/data-integrity.php';
require_once dirname(__DIR__) . '/registrant-complete-purge.php';
require_once dirname(__DIR__) . '/google-sheets-sync.php';
require_once dirname(__DIR__) . '/attendance-gps-phase15.php';
require_once dirname(__DIR__) . '/attendance-gps-phase1.php';
require_once dirname(__DIR__) . '/maps.php';

/** @return list<string> */
function collectTestEmailsForCleanup(PDO $pdo): array
{
    $emails = [];

    try {
        $rows = $pdo->query(
            "SELECT DISTINCT LOWER(TRIM(email)) AS email FROM (
                SELECT email FROM staff WHERE email IS NOT NULL AND TRIM(email) <> ''
                UNION
                SELECT email FROM staff_registrations WHERE email IS NOT NULL AND TRIM(email) <> ''
            ) AS combined"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($rows as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '' && dataIntegrityIsTestEmail($email)) {
                $emails[$email] = true;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    ksort($emails);

    return array_keys($emails);
}

/**
 * Real emails flagged only because of test PSA in Apply vault — do not auto-purge.
 *
 * @return list<array{email: string, name: string, psa: string, vault_id: int}>
 */
function collectVaultReviewAccounts(PDO $pdo, ?PDO $applyPdo = null): array
{
    $applyPdo ??= getApplyVaultPdo();
    if (!$applyPdo instanceof PDO) {
        return [];
    }

    $out = [];
    foreach (detectTestAccounts($pdo, $applyPdo)['accounts'] ?? [] as $account) {
        if (($account['source'] ?? '') !== 'apply_vault') {
            continue;
        }
        $email = strtolower(trim((string) ($account['email'] ?? '')));
        if ($email === '' || dataIntegrityIsTestEmail($email)) {
            continue;
        }
        $out[] = [
            'email'    => $email,
            'name'     => (string) ($account['name'] ?? ''),
            'psa'      => (string) ($account['psa'] ?? ''),
            'vault_id' => (int) ($account['vault_id'] ?? 0),
        ];
    }

    return $out;
}

/**
 * @return array{deleted: int, listed: int, message: string, files: list<array{id: string, name: string}>}
 */
function googleDrivePurgeTestSpreadsheetsInFolder(PDO $pdo, int $maxResults = 500): array
{
    $folderId = getGoogleSheetsDriveParentFolderId($pdo);
    if ($folderId === '') {
        return ['deleted' => 0, 'listed' => 0, 'message' => 'No Drive folder configured.', 'files' => []];
    }

    $serviceAccount = loadGoogleServiceAccount();
    if ($serviceAccount === null) {
        return ['deleted' => 0, 'listed' => 0, 'message' => 'Google service account not configured.', 'files' => []];
    }

    $patterns = [
        'event staff api probe',
        'event staff api test',
        'event staff api',
        'api probe',
        'api test',
    ];

    $files   = googleDriveListSpreadsheetsInFolderMerged($serviceAccount, $folderId, $pdo, $maxResults);
    $matched = [];
    $deleted = 0;

    foreach ($files as $file) {
        $name = mb_strtolower(trim((string) ($file['name'] ?? '')));
        $id   = trim((string) ($file['id'] ?? ''));
        if ($id === '' || googleDriveSpreadsheetNameIsTemplate($name)) {
            continue;
        }
        $isTest = false;
        foreach ($patterns as $pattern) {
            if (str_contains($name, $pattern)) {
                $isTest = true;
                break;
            }
        }
        if (!$isTest) {
            continue;
        }
        $matched[] = ['id' => $id, 'name' => (string) ($file['name'] ?? '')];
        if (googleDriveDeleteFile($serviceAccount, $id)) {
            $deleted++;
        }
    }

    $message = $deleted > 0
        ? "Deleted {$deleted} test spreadsheet(s) from Drive folder."
        : 'No test spreadsheets found in Drive folder (API test / probe names).';

    googleSheetsLog('Purge folder test spreadsheets: ' . $message);

    return [
        'deleted' => $deleted,
        'listed'  => count($files),
        'message' => $message,
        'files'   => $matched,
    ];
}

/** @return array<string, mixed> */
function verifyGpsSignInReadiness(PDO $pdo): array
{
    $checks = [];
    $pass   = 0;
    $fail   = 0;

    $add = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$pass, &$fail): void {
        $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
        if ($ok) {
            $pass++;
        } else {
            $fail++;
        }
    };

    $gpsOn = isGpsAttendanceV2Enabled($pdo);
    $add('GPS sign-in flag ON', $gpsOn, $gpsOn ? 'feature_gps_attendance_v2 enabled' : 'Turn ON from dashboard geo sign-in bar');

    ensureAttendanceGpsPhase1Schema($pdo);
    ensureAttendanceGpsPhase15Schema($pdo);
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $cols = [];
    }
    foreach (['check_in_lat', 'check_in_lng', 'check_in_gps_at', 'last_gps_at', 'attendance_status'] as $col) {
        $add('attendance.' . $col, in_array($col, $cols, true));
    }

    $add('GPS ping API deployed', is_file(dirname(__DIR__, 2) . '/api/attendance-gps-ping.php'));
    $add('attendance-activate cron deployed', is_file(dirname(__DIR__, 2) . '/cron/attendance-activate.php'));

    try {
        $eventsWithGps = (int) $pdo->query(
            "SELECT COUNT(*) FROM events
             WHERE venue_lat IS NOT NULL AND venue_lng IS NOT NULL
               AND TRIM(COALESCE(venue_eircode, '')) <> ''"
        )->fetchColumn();
    } catch (Throwable $e) {
        $eventsWithGps = 0;
    }
    $add('Events with venue GPS configured', $eventsWithGps > 0, (string) $eventsWithGps . ' event(s)');

    $sampleEvent = [
        'venue_lat'       => 53.3498,
        'venue_lng'       => -6.2603,
        'venue_eircode'   => 'D02 X285',
        'signin_radius_m' => 1000,
        'event_date'      => date('Y-m-d'),
        'start_time'      => '18:00:00',
    ];
    $near = validateGpsForCheckin($pdo, $sampleEvent, ['lat' => 53.3498, 'lng' => -6.2603, 'accuracy_m' => 15]);
    $add('GPS validation accepts in-zone check-in', $near['ok'], (string) ($near['message'] ?? ''));

    $far = validateGpsForCheckin($pdo, $sampleEvent, ['lat' => 52.0, 'lng' => -7.0, 'accuracy_m' => 10]);
    $add('GPS validation rejects outside zone', !$far['ok']);

    $null = validateGpsForCheckin($pdo, $sampleEvent, null);
    $add('GPS validation rejects missing coords', !$null['ok']);

    $cronKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $add('Cron secret configured', $cronKey !== '', $cronKey !== '' ? 'reminder_cron_key set' : 'Set in Email settings');

    return [
        'ok'      => $fail === 0,
        'pass'    => $pass,
        'fail'    => $fail,
        'checks'  => $checks,
        'gps_on'  => $gpsOn,
        'events_with_gps' => $eventsWithGps,
    ];
}

/**
 * @param array{dry_run?: bool, purge_sheets?: bool, purge_service_account_sheets?: bool} $options
 * @return array<string, mixed>
 */
function runProductionTestDataCleanup(PDO $pdo, array $options = []): array
{
    $dryRun = !empty($options['dry_run']);
    $emails = collectTestEmailsForCleanup($pdo);
    $purged = [];
    $errors = [];

    foreach ($emails as $email) {
        if ($dryRun) {
            $scan = scanRegistrantEverywhere($pdo, $email);
            $purged[] = [
                'email'          => $email,
                'dry_run'        => true,
                'total_rows'     => (int) ($scan['total_rows'] ?? 0),
                'registration_ids' => $scan['registration_ids'] ?? [],
            ];
            continue;
        }

        try {
            $result = purgeRegistrantCompletely($pdo, $email, false);
            $purged[] = [
                'email'           => $email,
                'remaining_rows'  => (int) ($result['remaining_rows'] ?? 0),
                'ok'              => !empty($result['ok']),
            ];
            if ((int) ($result['remaining_rows'] ?? 0) > 0) {
                $errors[] = $email . ' still has ' . (int) $result['remaining_rows'] . ' row(s) after purge';
            }
        } catch (Throwable $e) {
            $errors[] = $email . ': ' . $e->getMessage();
            $purged[] = ['email' => $email, 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    $sheets = ['deleted' => 0, 'message' => 'Skipped'];
    if (!empty($options['purge_sheets']) && !$dryRun) {
        $folderPurge = googleDrivePurgeTestSpreadsheetsInFolder($pdo);
        $sheets = $folderPurge;
        if (!empty($options['purge_service_account_sheets'])) {
            $sa = loadGoogleServiceAccount();
            if ($sa !== null) {
                $saPurge = googleDrivePurgeTestSpreadsheets($sa);
                $sheets['deleted'] += (int) ($saPurge['deleted'] ?? 0);
                $sheets['message'] .= ' ' . ($saPurge['message'] ?? '');
            }
        }
    }

    $gps = verifyGpsSignInReadiness($pdo);
    $testDataAfter = detectTestAccounts($pdo, getApplyVaultPdo());

    return [
        'ok'                   => $errors === [] && ($gps['ok'] ?? false),
        'dry_run'              => $dryRun,
        'test_emails_found'    => count($emails),
        'purged'               => $purged,
        'errors'               => $errors,
        'sheets'               => $sheets,
        'gps_sign_in'          => $gps,
        'test_accounts_remaining' => count($testDataAfter['accounts'] ?? []),
        'generated_at'         => gmdate('c'),
    ];
}
