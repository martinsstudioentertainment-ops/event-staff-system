<?php

declare(strict_types=1);

/**
 * Multi-event Google Form Responses Sheet watcher.
 */

/**
 * @param array<string, mixed> $integration
 * @return array<string, mixed>
 */
function googleFormIntakeSyncIntegration(PDO $pdo, array $integration, string $token, string $authLabel): array
{
    $id = (int) ($integration['id'] ?? 0);
    $eventId = (int) ($integration['event_id'] ?? 0);
    $sheetId = (string) ($integration['google_sheet_id'] ?? '');
    $tab = trim((string) ($integration['google_sheet_tab'] ?? '')) ?: GOOGLE_FORM_AVIVA_SHEET_TAB;
    $already = (int) ($integration['processed_rows'] ?? 0);
    $isAviva = $sheetId === GOOGLE_FORM_AVIVA_SHEET_ID;
    $lastPermOk = (int) ($integration['last_permission_ok'] ?? 1);
    $lastSyncTs = strtotime((string) ($integration['last_sync_at'] ?? ''));

    $base = [
        'ok'             => false,
        'action'         => $isAviva ? 'aviva_sheet_sync' : 'google_form_sheet_sync',
        'integration_id' => $id,
        'event_id'       => $eventId,
        'event'          => (string) ($integration['event_name'] ?? ''),
        'sheet_id'       => $sheetId,
        'auth'           => $authLabel,
        'imported'       => 0,
        'skipped'        => 0,
        'failed'         => [],
        'processed_rows' => $already,
    ];

    if ($lastPermOk !== 1 && $lastSyncTs !== false && (time() - $lastSyncTs) < 900) {
        $base['error'] = (string) ($integration['last_error'] ?? 'Could not read the Google Form sheet.');
        $base['http'] = (int) ($integration['last_http_code'] ?? 0);
        $base['permission_ok'] = false;
        $base['backoff'] = true;

        return $base;
    }

    if ($isAviva) {
        $fromSetting = (int) getSetting($pdo, 'google_form_aviva_sheet_rows', '0');
        if ($fromSetting > $already) {
            $already = $fromSetting;
            $base['processed_rows'] = $already;
        }
    } else {
        try {
            require_once __DIR__ . '/google-form-create.php';
            googleFormIntakePullFormResponsesToSheet($pdo, $integration);
        } catch (Throwable $e) {
            if ((string) ($integration['last_error'] ?? '') !== $e->getMessage()) {
                error_log('[EventStaff] Google Form response pull: ' . $e->getMessage());
            }
        }
    }

    if ($eventId <= 0 || $sheetId === '') {
        $base['error'] = 'Integration is missing event or sheet.';
        if ($id > 0) {
            googleFormEventIntegrationRecordSync($pdo, $id, $base + ['failed_count' => 0]);
        }

        return $base;
    }

    $range = rawurlencode("'" . str_replace("'", "''", $tab) . "'");
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/'
        . rawurlencode($sheetId)
        . '/values/' . $range;
    $response = googleSheetsHttpRequest('GET', $url, googleSheetsAuthHeaders($token, null, false));
    if ($response['code'] !== 200) {
        $sa = googleFormEventIntegrationServiceAccountEmail();
        $base['http'] = $response['code'];
        $base['error'] = 'Could not read the Google Form sheet.';
        $base['hint'] = ($response['code'] === 403 || $response['code'] === 404)
            ? 'Share the Responses Sheet as Viewer with ' . $sa . '.'
            : substr(trim(strip_tags($response['body'])), 0, 180);
        $base['permission_ok'] = false;
        if ($id > 0) {
            googleFormEventIntegrationRecordSync($pdo, $id, $base + ['failed_count' => 0]);
        }
        $sameFail = (int) ($integration['last_http_code'] ?? 0) === (int) $response['code']
            && (string) ($integration['last_error'] ?? '') === (string) $base['error'];
        $loggedRecently = $lastSyncTs !== false && (time() - $lastSyncTs) < 3600;
        if (!$sameFail || !$loggedRecently) {
            error_log('[EventStaff] Google Form sheet sync HTTP ' . $response['code'] . ' event ' . $eventId);
        }

        return $base;
    }

    $data = json_decode($response['body'], true);
    $values = is_array($data) ? ($data['values'] ?? []) : [];
    if (!is_array($values) || $values === []) {
        $okEmpty = [
            'ok'             => true,
            'permission_ok'  => true,
            'http'           => 200,
            'total'          => 0,
            'imported'       => 0,
            'skipped'        => 0,
            'failed'         => [],
            'failed_count'   => 0,
            'processed_rows' => $already,
        ];
        if ($id > 0) {
            googleFormEventIntegrationRecordSync($pdo, $id, $okEmpty);
        }

        return array_merge($base, $okEmpty);
    }

    $header = array_map(static fn($h): string => trim((string) $h), $values[0] ?? []);
    $rows = array_slice($values, 1);
    if ($already < 0) {
        $already = 0;
    }
    if ($already === 0 && $rows !== []) {
        $have = (int) $pdo->query('SELECT COUNT(*) FROM staff_registrations WHERE event_id = ' . $eventId)->fetchColumn();
        if ($have >= count($rows)) {
            $already = count($rows);
            if ($isAviva) {
                setSetting($pdo, 'google_form_aviva_sheet_rows', (string) $already);
            }
            $caughtUp = [
                'ok'             => true,
                'permission_ok'  => true,
                'http'           => 200,
                'total'          => count($rows),
                'imported'       => 0,
                'skipped'        => count($rows),
                'failed'         => [],
                'failed_count'   => 0,
                'processed_rows' => $already,
                'note'           => 'Existing form rows already on this event. Watching for new submits.',
            ];
            if ($id > 0) {
                googleFormEventIntegrationRecordSync($pdo, $id, $caughtUp);
            }

            return array_merge($base, $caughtUp);
        }
    }

    $imported = 0;
    $skipped = 0;
    $failed = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        if ($index < $already) {
            $skipped++;
            continue;
        }
        $named = [];
        foreach ($header as $i => $title) {
            $cell = isset($row[$i]) ? trim((string) $row[$i]) : '';
            if ($title === '') {
                continue;
            }
            if (!isset($named[$title]) || $named[$title] === '') {
                $named[$title] = $cell;
            }
        }
        $payload = [
            'event_id'            => $eventId,
            'email'               => $named['Email Address'] ?? $named['Email'] ?? '',
            'first_name'          => $named['First name'] ?? '',
            'surname'             => $named['Surname'] ?? '',
            'mobile'              => $named['Mobile number'] ?? '',
            'full_address'        => $named['Full address'] ?? '',
            'eircode'             => $named['Eircode'] ?? '',
            'gender'              => $named['Gender'] ?? '',
            'bank_iban'           => $named['IBAN'] ?? '',
            'staff_role'          => $named['Role'] ?? '',
            'psa_licence'         => $named['PSA licence number'] ?? '',
            'pps_number'          => $named['PPS number'] ?? '',
            'date_of_birth'       => $named['Date of birth'] ?? '',
            'psa_front_drive_url' => $named['PSA badge, front'] ?? '',
            'psa_back_drive_url'  => $named['PSA badge, back'] ?? '',
        ];
        try {
            $result = processGoogleFormStaffIntake($pdo, $payload);
            if (!empty($result['ok'])) {
                $imported++;
            } else {
                $failed[] = [
                    'email' => $payload['email'],
                    'error' => (string) ($result['error'] ?? 'unknown'),
                ];
            }
        } catch (Throwable $e) {
            $failed[] = ['email' => $payload['email'], 'error' => $e->getMessage()];
        }
        $already = $index + 1;
        if ($isAviva) {
            setSetting($pdo, 'google_form_aviva_sheet_rows', (string) $already);
        }
    }

    $out = [
        'ok'             => true,
        'permission_ok'  => true,
        'http'           => 200,
        'total'          => count($rows),
        'imported'       => $imported,
        'skipped'        => $skipped,
        'failed'         => $failed,
        'failed_count'   => count($failed),
        'processed_rows' => $already,
        'error'          => $failed !== [] ? substr((string) ($failed[0]['error'] ?? 'Row failed'), 0, 500) : null,
    ];
    if ($id > 0) {
        googleFormEventIntegrationRecordSync($pdo, $id, $out);
    }

    return array_merge($base, $out);
}

/**
 * @return array<string, mixed>
 */
function googleFormIntakeSyncFromAvivaSheet(PDO $pdo): array
{
    googleFormIntakeSeedAvivaIntegration($pdo);
    $auth = googleFormIntakeGetReadAuth($pdo);
    if ($auth === null) {
        return ['ok' => false, 'error' => 'Google is not connected for sheet sync.'];
    }
    $row = googleFormEventIntegrationBySheetId($pdo, GOOGLE_FORM_AVIVA_SHEET_ID);
    if ($row === null) {
        $event = googleFormIntakeFindAvivaEvent($pdo);
        $row = [
            'id'               => 0,
            'event_id'         => (int) ($event['id'] ?? 0),
            'event_name'       => GOOGLE_FORM_AVIVA_EVENT_NAME,
            'google_sheet_id'  => GOOGLE_FORM_AVIVA_SHEET_ID,
            'google_sheet_tab' => GOOGLE_FORM_AVIVA_SHEET_TAB,
            'processed_rows'   => (int) getSetting($pdo, 'google_form_aviva_sheet_rows', '0'),
        ];
    }

    return googleFormIntakeSyncIntegration($pdo, $row, $auth['token'], $auth['label']);
}

/**
 * @return array<string, mixed>
 */
function googleFormIntakeSyncAllSheets(PDO $pdo): array
{
    googleFormIntakeSeedAvivaIntegration($pdo);
    $auth = googleFormIntakeGetReadAuth($pdo);
    $rows = googleFormEventIntegrationsEnabled($pdo);
    if ($rows === []) {
        $aviva = googleFormIntakeSyncFromAvivaSheet($pdo);

        return [
            'ok'           => !empty($aviva['ok']),
            'action'       => 'google_form_sheet_sync_all',
            'integrations' => [$aviva],
        ];
    }

    $results = [];
    foreach ($rows as $row) {
        try {
            if ($auth === null) {
                $fail = [
                    'ok'             => false,
                    'action'         => 'google_form_sheet_sync',
                    'integration_id' => (int) $row['id'],
                    'event_id'       => (int) $row['event_id'],
                    'event'          => (string) ($row['event_name'] ?? ''),
                    'error'          => 'Google is not connected for sheet sync.',
                    'processed_rows' => (int) ($row['processed_rows'] ?? 0),
                    'failed_count'   => 0,
                ];
                googleFormEventIntegrationRecordSync($pdo, (int) $row['id'], $fail);
                $results[] = $fail;
                continue;
            }
            $results[] = googleFormIntakeSyncIntegration($pdo, $row, $auth['token'], $auth['label']);
        } catch (Throwable $e) {
            error_log('[EventStaff] Google Form sheet sync event ' . (int) $row['event_id'] . ': ' . $e->getMessage());
            $fail = [
                'ok'             => false,
                'action'         => 'google_form_sheet_sync',
                'integration_id' => (int) ($row['id'] ?? 0),
                'event_id'       => (int) ($row['event_id'] ?? 0),
                'event'          => (string) ($row['event_name'] ?? ''),
                'error'          => $e->getMessage(),
                'processed_rows' => (int) ($row['processed_rows'] ?? 0),
                'failed_count'   => 0,
            ];
            if ((int) ($row['id'] ?? 0) > 0) {
                googleFormEventIntegrationRecordSync($pdo, (int) $row['id'], $fail);
            }
            $results[] = $fail;
        }
    }

    $anyOk = false;
    foreach ($results as $item) {
        if (!empty($item['ok'])) {
            $anyOk = true;
            break;
        }
    }

    return [
        'ok'           => $anyOk || $results === [],
        'action'       => 'google_form_sheet_sync_all',
        'count'        => count($results),
        'integrations' => $results,
    ];
}

function googleFormSheetColumnLetter(int $zeroBased): string
{
    $n = $zeroBased + 1;
    $out = '';
    while ($n > 0) {
        $n--;
        $out = chr(65 + ($n % 26)) . $out;
        $n = intdiv($n, 26);
    }

    return $out !== '' ? $out : 'A';
}

/**
 * @return list<array{token: string, project: ?string, label: string}>
 */
function googleFormIntakeWriteAuthCandidates(PDO $pdo): array
{
    require_once __DIR__ . '/google-drive-oauth.php';
    require_once __DIR__ . '/google-sheets-sync.php';
    $out = [];

    try {
        $gmail = googleDriveGetUserAccessToken($pdo);
        if ($gmail !== '') {
            $out[] = ['token' => $gmail, 'project' => null, 'label' => 'gmail'];
        }
    } catch (Throwable $e) {
        // continue
    }

    $sa = loadGoogleServiceAccount();
    if (is_array($sa)) {
        $token = (string) (googleSheetsGetAccessToken($sa, [
            'https://www.googleapis.com/auth/spreadsheets',
            'https://www.googleapis.com/auth/drive',
        ]) ?? '');
        if ($token !== '') {
            $out[] = [
                'token' => $token,
                'project' => (string) ($sa['project_id'] ?? ''),
                'label' => 'service_account',
            ];
        }
    }

    return $out;
}

function googleFormSheetGrantWriterWithToken(string $token, ?string $project, string $fileId, string $email): bool
{
    $fileId = trim($fileId);
    $email = strtolower(trim($email));
    if ($fileId === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $token === '') {
        return false;
    }

    $payload = json_encode([
        'type' => 'user',
        'role' => 'writer',
        'emailAddress' => $email,
    ], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return false;
    }

    $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId)
        . '/permissions?supportsAllDrives=true&sendNotificationEmail=false';
    $response = googleSheetsHttpRequest(
        'POST',
        $url,
        googleSheetsAuthHeaders($token, $project),
        $payload
    );
    if ($response['code'] >= 200 && $response['code'] < 300) {
        return true;
    }

    $body = strtolower($response['body']);

    return str_contains($body, 'already') || $response['code'] === 409;
}

function googleFormSheetTryGrantServiceAccountWriter(PDO $pdo, string $sheetId): bool
{
    require_once __DIR__ . '/google-drive-oauth.php';
    $saEmail = googleFormEventIntegrationServiceAccountEmail();
    if ($saEmail === '') {
        return false;
    }

    try {
        $gmail = googleDriveGetUserAccessToken($pdo);
        if ($gmail !== '' && googleFormSheetGrantWriterWithToken($gmail, null, $sheetId, $saEmail)) {
            return true;
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] PSA sheet share (gmail): ' . $e->getMessage());
    }

    return false;
}

/**
 * @param list<array{token: string, project: ?string, label: string}> $writers
 */
function googleFormSheetWriteCellWithCandidates(array $writers, string $sheetId, string $cell, string $value): bool
{
    foreach ($writers as $writer) {
        $project = ($writer['project'] ?? '') !== '' ? $writer['project'] : null;
        if (googleSheetsWriteRangeValues($writer['token'], $project, $sheetId, $cell, [[$value]])) {
            return true;
        }
    }

    return false;
}

/**
 * Write the corrected PSA licence back onto Google Form Responses sheets (by email).
 *
 * @return array{updated: int, failed: int, skipped: int, details: list<array<string, mixed>>}
 */
function googleFormPushPsaLicenceToResponseSheets(PDO $pdo, string $email, string $licence): array
{
    require_once __DIR__ . '/google-form-intake.php';
    require_once __DIR__ . '/google-sheets-sync.php';

    $email = strtolower(trim($email));
    $licence = normalizePsaLicence($licence);
    $stats = ['updated' => 0, 'failed' => 0, 'skipped' => 0, 'details' => []];
    if ($email === '' || $licence === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $stats;
    }

    $authRead = googleFormIntakeGetReadAuth($pdo);
    $writers = googleFormIntakeWriteAuthCandidates($pdo);
    if ($authRead === null || $writers === []) {
        $stats['skipped'] = 1;
        $stats['details'][] = ['error' => 'no_write_auth'];

        return $stats;
    }

    googleFormIntakeSeedAvivaIntegration($pdo);
    $rows = googleFormEventIntegrationsEnabled($pdo);
    if ($rows === []) {
        $aviva = googleFormEventIntegrationBySheetId($pdo, GOOGLE_FORM_AVIVA_SHEET_ID);
        if ($aviva !== null) {
            $rows = [$aviva];
        }
    }

    foreach ($rows as $integration) {
        $sheetId = (string) ($integration['google_sheet_id'] ?? '');
        $tab = trim((string) ($integration['google_sheet_tab'] ?? '')) ?: GOOGLE_FORM_AVIVA_SHEET_TAB;
        $eventId = (int) ($integration['event_id'] ?? 0);
        if ($sheetId === '') {
            continue;
        }
        $range = "'" . str_replace("'", "''", $tab) . "'";
        $values = googleSheetsReadRangeValues($authRead['token'], null, $sheetId, $range);
        if (!is_array($values) || $values === []) {
            $stats['skipped']++;
            $stats['details'][] = ['event_id' => $eventId, 'sheet_id' => $sheetId, 'status' => 'unreadable'];
            continue;
        }
        $header = array_map(static fn($h): string => trim((string) $h), $values[0] ?? []);
        $emailIdx = null;
        $psaIdx = null;
        foreach ($header as $i => $title) {
            $t = strtolower($title);
            if ($emailIdx === null && ($t === 'email address' || $t === 'email')) {
                $emailIdx = $i;
            }
            if ($psaIdx === null && (str_contains($t, 'psa licence') || $t === 'licence number' || $t === 'psa license number')) {
                $psaIdx = $i;
            }
        }
        if ($emailIdx === null || $psaIdx === null) {
            $stats['skipped']++;
            $stats['details'][] = ['event_id' => $eventId, 'sheet_id' => $sheetId, 'status' => 'missing_columns'];
            continue;
        }
        $targetRows = [];
        foreach (array_slice($values, 1) as $offset => $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowEmail = strtolower(trim((string) ($row[$emailIdx] ?? '')));
            if ($rowEmail === $email) {
                $targetRows[] = $offset + 2;
            }
        }
        if ($targetRows === []) {
            $stats['skipped']++;
            $stats['details'][] = ['event_id' => $eventId, 'sheet_id' => $sheetId, 'status' => 'email_not_on_sheet'];
            continue;
        }

        $wroteAny = false;
        $failedAny = false;
        foreach ($targetRows as $targetRow) {
            $cell = $range . '!' . googleFormSheetColumnLetter($psaIdx) . $targetRow;
            $wrote = googleFormSheetWriteCellWithCandidates($writers, $sheetId, $cell, $licence);
            if (!$wrote) {
                googleFormSheetTryGrantServiceAccountWriter($pdo, $sheetId);
                $writers = googleFormIntakeWriteAuthCandidates($pdo);
                $wrote = googleFormSheetWriteCellWithCandidates($writers, $sheetId, $cell, $licence);
            }
            if ($wrote) {
                $wroteAny = true;
                $stats['details'][] = [
                    'event_id' => $eventId,
                    'sheet_id' => $sheetId,
                    'row' => $targetRow,
                    'cell' => $cell,
                    'status' => 'updated',
                ];
            } else {
                $failedAny = true;
                $stats['details'][] = [
                    'event_id' => $eventId,
                    'sheet_id' => $sheetId,
                    'row' => $targetRow,
                    'cell' => $cell,
                    'status' => 'write_denied',
                ];
            }
        }
        if ($wroteAny) {
            $stats['updated']++;
        }
        if ($failedAny && !$wroteAny) {
            $stats['failed']++;
            error_log('[EventStaff] PSA sheet write-back failed for event ' . $eventId);
        }
    }

    return $stats;
}

/**
 * @return array{updated: int, failed: int, skipped: int, details?: list<array<string, mixed>>}
 */
function googleFormPushPsaAfterOlasentraUpdate(PDO $pdo, int $staffId, string $licence): array
{
    $stats = ['updated' => 0, 'failed' => 0, 'skipped' => 0, 'details' => []];
    $staff = getStaffById($pdo, $staffId);
    if ($staff === null) {
        return $stats;
    }
    $email = strtolower(trim((string) ($staff['email'] ?? '')));
    if ($email === '') {
        return $stats;
    }

    try {
        $stats = googleFormPushPsaLicenceToResponseSheets($pdo, $email, $licence);
    } catch (Throwable $e) {
        error_log('[EventStaff] PSA form-sheet write-back: ' . $e->getMessage());
        $stats['failed']++;
        $stats['details'][] = ['error' => $e->getMessage()];
    }

    try {
        require_once __DIR__ . '/google-sheets-sync.php';
        require_once __DIR__ . '/google-sheets-queue.php';
        syncStaffProfileToLinkedGoogleSheets($pdo, $staffId);
        $stmt = $pdo->prepare(
            'SELECT DISTINCT event_id FROM staff_registrations
             WHERE staff_id = :staff_id OR LOWER(TRIM(email)) = :email'
        );
        $stmt->execute(['staff_id' => $staffId, 'email' => $email]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $eventId) {
            googleSheetsEnqueueEventRebuild($pdo, (int) $eventId, 'psa_update', null, true, 2);
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] PSA roster sheet sync: ' . $e->getMessage());
    }

    return $stats;
}
