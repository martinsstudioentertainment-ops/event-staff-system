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

function isGoogleSheetsConfigured(?PDO $pdo = null): bool
{
    return isGoogleSheetsSyncEnabled($pdo) && loadGoogleServiceAccount() !== null;
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

/**
 * @param array<string, mixed> $serviceAccount
 */
function googleSheetsGetAccessToken(array $serviceAccount): ?string
{
    $now = time();
    $header = base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $claims = base64UrlEncode(json_encode([
        'iss'   => (string) $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/spreadsheets',
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
        curl_close($ch);

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
