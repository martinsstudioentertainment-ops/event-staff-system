<?php
/**
 * Append staff registrations to a Google Sheet per event (Sheets API v4 + service account).
 */

require_once __DIR__ . '/google-sheets-schema.php';
require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/staff-labels.php';
require_once __DIR__ . '/staff-employee-export.php';
require_once __DIR__ . '/events-repository.php';

function isGoogleSheetsSyncEnabled(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? getDB();

    return getSetting($pdo, 'google_sheets_sync_enabled', '0') === '1';
}

/**
 * @return array<string, mixed>|null
 */
function loadGoogleServiceAccount(): ?array
{
    $path = getGoogleServiceAccountPath();
    if (!is_file($path)) {
        return null;
    }

    $json = file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return null;
    }

    $data = json_decode($json, true);

    return is_array($data) && !empty($data['client_email']) && !empty($data['private_key'])
        ? $data
        : null;
}

function isGoogleServiceAccountConfigured(): bool
{
    return loadGoogleServiceAccount() !== null;
}

function isGoogleSheetsConfigured(?PDO $pdo = null): bool
{
    return isGoogleSheetsSyncEnabled($pdo) && isGoogleServiceAccountConfigured();
}

function parseGoogleSpreadsheetId(string $urlOrId): ?string
{
    $urlOrId = trim($urlOrId);
    if ($urlOrId === '') {
        return null;
    }

    if (preg_match('#^docs\.google\.com/spreadsheets/d/([a-zA-Z0-9-_]+)#', $urlOrId, $m)) {
        return $m[1];
    }

    if (preg_match('#/spreadsheets/d/([a-zA-Z0-9-_]+)#', $urlOrId, $m)) {
        return $m[1];
    }

    if (preg_match('#^[a-zA-Z0-9-_]{20,}$#', $urlOrId)) {
        return $urlOrId;
    }

    return null;
}

function escapeGoogleSheetRangeTab(string $tab): string
{
    $tab = trim($tab);
    if ($tab === '') {
        return 'Sheet1';
    }

    return "'" . str_replace("'", "''", $tab) . "'";
}

/**
 * @return list<string>
 */
function getGoogleSheetsExportHeaders(): array
{
    return getEmployeeSpreadsheetHeaders();
}

/**
 * @param array<string, mixed> $row
 * @return list<string>
 */
function buildGoogleSheetsRegistrationRow(array $row): array
{
    return buildEmployeeSpreadsheetRow($row);
}

function googleSheetsLog(string $message): void
{
    $GLOBALS['_event_staff_google_sheets_last_error'] = $message;

    $dir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents(
        $dir . '/google-sheets.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n",
        FILE_APPEND | LOCK_EX
    );
}

function getLastGoogleSheetsApiError(): string
{
    return (string) ($GLOBALS['_event_staff_google_sheets_last_error'] ?? '');
}

/**
 * @param array<string, mixed> $serviceAccount
 */
/**
 * @param list<string> $scopes
 */
function googleSheetsGetAccessToken(array $serviceAccount, array $scopes = []): ?string
{
    if ($scopes === []) {
        $scopes = ['https://www.googleapis.com/auth/spreadsheets'];
    }

    $now = time();
    $header = base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $claims = base64UrlEncode(json_encode([
        'iss'   => (string) $serviceAccount['client_email'],
        'scope' => implode(' ', $scopes),
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ], JSON_THROW_ON_ERROR));

    $input = $header . '.' . $claims;
    $key   = openssl_pkey_get_private((string) $serviceAccount['private_key']);
    if ($key === false) {
        return null;
    }

    $signature = '';
    if (!openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
        return null;
    }

    $jwt = $input . '.' . base64UrlEncode($signature);

    $response = googleSheetsHttpRequest(
        'POST',
        'https://oauth2.googleapis.com/token',
        [
            'Content-Type: application/x-www-form-urlencoded',
        ],
        http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ])
    );

    if ($response['code'] !== 200) {
        googleSheetsLog('Token error: ' . $response['body']);

        return null;
    }

    $data = json_decode($response['body'], true);

    return is_array($data) ? (string) ($data['access_token'] ?? '') : null;
}

/**
 * @param list<string> $headers
 * @return array{code: int, body: string}
 */
function googleSheetsHttpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($ch);
        $code         = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($code === 0 && $curlError !== '') {
            return ['code' => 0, 'body' => 'curl: ' . $curlError];
        }

        return ['code' => $code, 'body' => is_string($responseBody) ? $responseBody : ''];
    }

    $opts = [
        'http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'content'       => $body ?? '',
            'timeout'       => 20,
            'ignore_errors' => true,
        ],
    ];
    $responseBody = @file_get_contents($url, false, stream_context_create($opts));

    return [
        'code' => 0,
        'body' => is_string($responseBody) ? $responseBody : '',
    ];
}

/**
 * @param array<string, mixed> $serviceAccount
 * @param list<list<string|int|float|null>> $rows
 */
function googleSheetsAppendRows(
    array $serviceAccount,
    string $spreadsheetId,
    string $tabName,
    array $rows
): bool {
    if ($rows === []) {
        return true;
    }

    $token = googleSheetsGetAccessToken($serviceAccount);
    if ($token === '') {
        return false;
    }

    $range = escapeGoogleSheetRangeTab($tabName) . '!A:P';
    $url   = 'https://sheets.googleapis.com/v4/spreadsheets/'
        . rawurlencode($spreadsheetId)
        . '/values/'
        . rawurlencode($range)
        . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS';

    $payload = json_encode(['values' => $rows], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return false;
    }

    $response = googleSheetsHttpRequest(
        'POST',
        $url,
        [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        $payload
    );

    if ($response['code'] >= 200 && $response['code'] < 300) {
        return true;
    }

    googleSheetsLog("Append failed ({$spreadsheetId}): HTTP {$response['code']} — {$response['body']}");

    return false;
}

/**
 * @param array<string, mixed> $serviceAccount
 */
function googleSheetsSheetNeedsHeader(array $serviceAccount, string $spreadsheetId, string $tabName): bool
{
    $token = googleSheetsGetAccessToken($serviceAccount);
    if ($token === '') {
        return false;
    }

    $range = escapeGoogleSheetRangeTab($tabName) . '!A1:A1';
    $url   = 'https://sheets.googleapis.com/v4/spreadsheets/'
        . rawurlencode($spreadsheetId)
        . '/values/'
        . rawurlencode($range);

    $response = googleSheetsHttpRequest(
        'GET',
        $url,
        ['Authorization: Bearer ' . $token]
    );

    if ($response['code'] !== 200) {
        return true;
    }

    $data = json_decode($response['body'], true);
    $values = $data['values'] ?? [];

    return $values === [] || trim((string) ($values[0][0] ?? '')) === '';
}

function buildGoogleSheetsTitleForEvent(array $event): string
{
    $date = '';
    if (!empty($event['event_date'])) {
        require_once __DIR__ . '/events-repository.php';
        $date = formatEventDateLabel((string) $event['event_date']);
    }

    $name  = trim((string) ($event['name'] ?? 'Event'));
    $title = $date !== '' ? $date . ' — ' . $name . ' — Staff' : $name . ' — Staff';
    $title = preg_replace('/[\[\]\*\/\\\?\:]/', '', $title) ?? $title;

    return mb_substr(trim($title), 0, 100);
}

function buildGoogleSheetsSpreadsheetUrl(string $spreadsheetId): string
{
    return 'https://docs.google.com/spreadsheets/d/' . $spreadsheetId . '/edit';
}

/**
 * @param array<string, mixed> $serviceAccount
 * @return array{spreadsheetId: string, tabName: string, url: string}|null
 */
function googleSheetsCreateSpreadsheet(array $serviceAccount, string $title, string $tabName = 'Registrations'): ?array
{
    $tabName = trim($tabName) !== '' ? trim($tabName) : 'Registrations';
    $token   = googleSheetsGetAccessToken($serviceAccount, [
        'https://www.googleapis.com/auth/spreadsheets',
        'https://www.googleapis.com/auth/drive',
    ]);
    if ($token === '') {
        return null;
    }

    $payload = json_encode([
        'properties' => ['title' => $title],
        'sheets'     => [['properties' => ['title' => $tabName]]],
    ], JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        return null;
    }

    $response = googleSheetsHttpRequest(
        'POST',
        'https://sheets.googleapis.com/v4/spreadsheets',
        [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        $payload
    );

    if ($response['code'] < 200 || $response['code'] >= 300) {
        googleSheetsLog('Create spreadsheet failed: HTTP ' . $response['code'] . ' — ' . $response['body']);

        return null;
    }

    $data = json_decode($response['body'], true);
    $id   = is_array($data) ? (string) ($data['spreadsheetId'] ?? '') : '';
    if ($id === '') {
        return null;
    }

    $headerRows = [getGoogleSheetsExportHeaders()];
    if (!googleSheetsAppendRows($serviceAccount, $id, $tabName, $headerRows)) {
        googleSheetsLog('Created sheet ' . $id . ' but header row failed');
    }

    return [
        'spreadsheetId' => $id,
        'tabName'       => $tabName,
        'url'           => buildGoogleSheetsSpreadsheetUrl($id),
    ];
}

/**
 * Let a human Google account open sheets created by the service account (optional).
 *
 * @param array<string, mixed> $serviceAccount
 */
function googleSheetsShareSpreadsheetWithEmail(array $serviceAccount, string $spreadsheetId, string $email): bool
{
    $email = trim($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $token = googleSheetsGetAccessToken($serviceAccount, [
        'https://www.googleapis.com/auth/drive.file',
    ]);
    if ($token === '') {
        return false;
    }

    $payload = json_encode([
        'type'                 => 'user',
        'role'                 => 'writer',
        'emailAddress'         => $email,
        'sendNotificationEmail'=> false,
    ], JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        return false;
    }

    $url = 'https://www.googleapis.com/drive/v3/files/'
        . rawurlencode($spreadsheetId)
        . '/permissions?supportsAllDrives=true';

    $response = googleSheetsHttpRequest(
        'POST',
        $url,
        [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        $payload
    );

    if ($response['code'] >= 200 && $response['code'] < 300) {
        return true;
    }

    googleSheetsLog("Share sheet {$spreadsheetId} with {$email}: HTTP {$response['code']} — {$response['body']}");

    return false;
}

/**
 * Create a Google Sheet for one event and store URL on the event row.
 *
 * @return array{ok: bool, message: string, url?: string}
 */
function createGoogleSheetForEvent(PDO $pdo, int $eventId): array
{
    ensureGoogleSheetsSchema($pdo);

    if (!isGoogleServiceAccountConfigured()) {
        return ['ok' => false, 'message' => 'Upload the service account JSON in Settings → Google Sheets.'];
    }

    $serviceAccount = loadGoogleServiceAccount();
    if ($serviceAccount === null) {
        return ['ok' => false, 'message' => 'Service account credentials missing.'];
    }

    $event = getEventById($pdo, $eventId);
    if (!$event) {
        return ['ok' => false, 'message' => 'Event not found.'];
    }

    if (trim((string) ($event['google_sheet_url'] ?? '')) !== '') {
        return ['ok' => false, 'message' => 'This event already has a Google Sheet linked.'];
    }

    $tabName = trim((string) getSetting($pdo, 'google_sheets_default_tab', 'Registrations'));
    if ($tabName === '') {
        $tabName = 'Registrations';
    }

    $created = googleSheetsCreateSpreadsheet(
        $serviceAccount,
        buildGoogleSheetsTitleForEvent($event),
        $tabName
    );

    if ($created === null) {
        $detail = getLastGoogleSheetsApiError();
        $hint   = 'Check storage/logs/google-sheets.log';
        if ($detail !== '') {
            $hint = mb_strlen($detail) > 220 ? mb_substr($detail, 0, 220) . '…' : $detail;
        }

        return ['ok' => false, 'message' => 'Google API could not create the spreadsheet. ' . $hint];
    }

    $shareEmail = trim((string) getSetting($pdo, 'google_sheets_share_with_email', ''));
    if ($shareEmail !== '') {
        googleSheetsShareSpreadsheetWithEmail($serviceAccount, $created['spreadsheetId'], $shareEmail);
    }

    $stmt = $pdo->prepare(
        'UPDATE events SET google_sheet_url = :url, google_sheet_tab = :tab WHERE id = :id'
    );
    $stmt->execute([
        'url' => $created['url'],
        'tab' => $created['tabName'],
        'id'  => $eventId,
    ]);

    googleSheetsLog("Auto-created sheet for event {$eventId}: {$created['url']}");

    return [
        'ok'      => true,
        'message' => 'Google Sheet created and linked.',
        'url'     => $created['url'],
    ];
}

/**
 * @return array{created: int, skipped: int, failed: int, errors: list<string>}
 */
function bulkCreateGoogleSheetsForEvents(PDO $pdo, bool $onlyMissing = true): array
{
    ensureGoogleSheetsSchema($pdo);

    $stats = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

    if (!isGoogleServiceAccountConfigured()) {
        $stats['errors'][] = 'Upload the service account JSON in Settings → Google Sheets.';

        return $stats;
    }

    $sql = 'SELECT id FROM events';
    if ($onlyMissing) {
        $sql .= " WHERE google_sheet_url IS NULL OR TRIM(google_sheet_url) = ''";
    }
    $sql .= ' ORDER BY event_date ASC, name ASC';

    $ids = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    if ($ids === []) {
        return $stats;
    }

    foreach ($ids as $rawId) {
        $eventId = (int) $rawId;
        if ($eventId < 1) {
            continue;
        }

        $event = getEventById($pdo, $eventId);
        if ($onlyMissing && $event && trim((string) ($event['google_sheet_url'] ?? '')) !== '') {
            $stats['skipped']++;
            continue;
        }

        $result = createGoogleSheetForEvent($pdo, $eventId);
        if ($result['ok']) {
            $stats['created']++;
        } else {
            $stats['failed']++;
            $label = $event ? buildGoogleSheetsTitleForEvent($event) : 'Event #' . $eventId;
            $stats['errors'][] = $label . ': ' . $result['message'];
        }

        usleep(250000);
    }

    return $stats;
}

/**
 * @return array{total: int, missing: int}
 */
function countEventsGoogleSheetStatus(PDO $pdo): array
{
    ensureGoogleSheetsSchema($pdo);

    $total = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
    $stmt  = $pdo->query(
        "SELECT COUNT(*) FROM events WHERE google_sheet_url IS NOT NULL AND TRIM(google_sheet_url) <> ''"
    );
    $linked = (int) $stmt->fetchColumn();

    return [
        'total'   => $total,
        'missing' => max(0, $total - $linked),
    ];
}

/**
 * Push one registration row to the event's Google Sheet.
 */
function syncRegistrationToGoogleSheet(PDO $pdo, int $registrationId): bool
{
    ensureGoogleSheetsSchema($pdo);

    if (!isGoogleSheetsConfigured($pdo)) {
        return false;
    }

    $serviceAccount = loadGoogleServiceAccount();
    if ($serviceAccount === null) {
        return false;
    }

    $row = getStaffRegistrationById($pdo, $registrationId);
    if (!$row) {
        return false;
    }

    $event = getEventById($pdo, (int) ($row['event_id'] ?? 0));
    if (!$event) {
        return false;
    }

    $sheetUrl = trim((string) ($event['google_sheet_url'] ?? ''));
    if ($sheetUrl === '') {
        return false;
    }

    $tabName = trim((string) ($event['google_sheet_tab'] ?? ''));
    if ($tabName === '') {
        $tabName = 'Sheet1';
    }

    $spreadsheetId = parseGoogleSpreadsheetId($sheetUrl);
    if ($spreadsheetId === null) {
        googleSheetsLog("Invalid sheet URL for registration {$registrationId}");

        return false;
    }

    if ($tabName === '') {
        $tabName = 'Sheet1';
    }

    $rows = [];
    if (googleSheetsSheetNeedsHeader($serviceAccount, $spreadsheetId, $tabName)) {
        $rows[] = getGoogleSheetsExportHeaders();
    }
    $rows[] = buildGoogleSheetsRegistrationRow($row);

    $ok = googleSheetsAppendRows($serviceAccount, $spreadsheetId, $tabName, $rows);
    if ($ok) {
        googleSheetsLog("Synced registration {$registrationId} → sheet {$spreadsheetId}");
    }

    return $ok;
}

/**
 * @param int[] $registrationIds
 * @return array{synced: int, skipped: int, failed: int}
 */
function syncRegistrationsToGoogleSheets(PDO $pdo, array $registrationIds): array
{
    $stats = ['synced' => 0, 'skipped' => 0, 'failed' => 0];

    if (!isGoogleSheetsConfigured($pdo)) {
        $stats['skipped'] = count($registrationIds);

        return $stats;
    }

    foreach ($registrationIds as $registrationId) {
        $registrationId = (int) $registrationId;
        if ($registrationId < 1) {
            continue;
        }

        $row = getStaffRegistrationById($pdo, $registrationId);
        if (!$row) {
            $stats['skipped']++;
            continue;
        }

        $event = getEventById($pdo, (int) $row['event_id']);
        if (!$event || trim((string) ($event['google_sheet_url'] ?? '')) === '') {
            $stats['skipped']++;
            continue;
        }

        if (syncRegistrationToGoogleSheet($pdo, $registrationId)) {
            $stats['synced']++;
        } else {
            $stats['failed']++;
        }
    }

    return $stats;
}

/**
 * @return array{ok: bool, message: string}
 */
function saveGoogleServiceAccountUpload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'No credentials file uploaded.'];
    }

    $tmp  = (string) ($file['tmp_name'] ?? '');
    $json = is_file($tmp) ? file_get_contents($tmp) : false;
    if ($json === false || trim($json) === '') {
        return ['ok' => false, 'message' => 'Credentials file is empty.'];
    }

    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
        return ['ok' => false, 'message' => 'Invalid service account JSON — need client_email and private_key.'];
    }

    $dir  = ensureGoogleStorageDirectory();
    $path = $dir . '/service-account.json';

    if (file_put_contents($path, $json) === false) {
        return ['ok' => false, 'message' => 'Could not save credentials to storage/google/.'];
    }

    @chmod($path, 0600);

    return [
        'ok'      => true,
        'message' => 'Service account saved (' . (string) $data['client_email'] . '). Share each Google Sheet with this email as Editor.',
    ];
}
