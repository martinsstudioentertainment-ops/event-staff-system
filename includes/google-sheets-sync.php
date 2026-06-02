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

/**
 * Folder in a human's Drive (shared with the service account) where new sheets are created.
 * Service accounts have no personal Drive quota — sheets must live in this folder.
 */
function getGoogleSheetsDriveParentFolderId(?PDO $pdo = null): string
{
    $pdo = $pdo ?? getDB();

    return parseGoogleDriveFolderId((string) getSetting($pdo, 'google_sheets_drive_folder_id', ''));
}

/**
 * Optional template sheet in the shared folder (copy avoids service-account quota limits).
 */
function getGoogleSheetsTemplateSpreadsheetId(?PDO $pdo = null): string
{
    $pdo = $pdo ?? getDB();
    $raw = trim((string) getSetting($pdo, 'google_sheets_template_id', ''));

    return parseGoogleSpreadsheetId($raw) ?? '';
}

/**
 * @param array<string, mixed> $serviceAccount
 * @return array{ok: bool, name: string, mimeType: string, summary: string}
 */
function googleDriveInspectParentFolder(array $serviceAccount, string $folderId): array
{
    $folderId = trim($folderId);
    if ($folderId === '') {
        return ['ok' => false, 'name' => '', 'mimeType' => '', 'summary' => 'Folder ID is empty'];
    }

    $project = (string) ($serviceAccount['project_id'] ?? '');
    $token   = googleSheetsGetAccessToken($serviceAccount, ['https://www.googleapis.com/auth/drive']);
    if ($token === '') {
        return ['ok' => false, 'name' => '', 'mimeType' => '', 'summary' => 'No Drive API token'];
    }

    $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($folderId)
        . '?fields=id,name,mimeType,capabilities&supportsAllDrives=true';

    $response = googleSheetsHttpRequest(
        'GET',
        $url,
        googleSheetsAuthHeaders($token, $project, false)
    );

    if ($response['code'] === 404) {
        return [
            'ok'       => false,
            'name'     => '',
            'mimeType' => '',
            'summary'  => 'Folder not found — share the folder (not only a sheet file) with the service account as Editor',
        ];
    }

    if ($response['code'] !== 200) {
        return [
            'ok'       => false,
            'name'     => '',
            'mimeType' => '',
            'summary'  => 'HTTP ' . $response['code'] . ': ' . googleSheetsSummarizeApiError($response['body']),
        ];
    }

    $data     = json_decode($response['body'], true);
    $name     = is_array($data) ? (string) ($data['name'] ?? '') : '';
    $mime     = is_array($data) ? (string) ($data['mimeType'] ?? '') : '';
    $canAdd   = is_array($data) && is_array($data['capabilities'] ?? null)
        ? (bool) ($data['capabilities']['canAddChildren'] ?? false)
        : false;

    if ($mime === 'application/vnd.google-apps.spreadsheet') {
        return [
            'ok'       => false,
            'name'     => $name,
            'mimeType' => $mime,
            'summary'  => 'This ID is a spreadsheet file, not a folder — in Drive use New → Folder, share that folder, copy its URL',
        ];
    }

    if ($mime !== 'application/vnd.google-apps.folder') {
        return [
            'ok'       => false,
            'name'     => $name,
            'mimeType' => $mime,
            'summary'  => 'Not a Drive folder (mime: ' . $mime . ')',
        ];
    }

    if (!$canAdd) {
        return [
            'ok'       => false,
            'name'     => $name,
            'mimeType' => $mime,
            'summary'  => 'Folder “' . $name . '” found but service account cannot add files — re-share as Editor',
        ];
    }

    return [
        'ok'       => true,
        'name'     => $name,
        'mimeType' => $mime,
        'summary'  => 'Folder “' . $name . '” — service account can create sheets here',
    ];
}

function parseGoogleDriveFolderId(string $urlOrId): string
{
    $urlOrId = trim($urlOrId);
    if ($urlOrId === '') {
        return '';
    }

    if (preg_match('#/folders/([a-zA-Z0-9_-]+)#', $urlOrId, $m)) {
        return $m[1];
    }

    if (preg_match('#^[a-zA-Z0-9_-]{10,}$#', $urlOrId)) {
        return $urlOrId;
    }

    return '';
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
 * @return list<string>
 */
function googleSheetsAuthHeaders(string $token, ?string $quotaProject = null, bool $jsonContentType = true): array
{
    $headers = ['Authorization: Bearer ' . $token];
    if ($jsonContentType) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($quotaProject !== null && $quotaProject !== '') {
        $headers[] = 'X-Goog-User-Project: ' . $quotaProject;
    }

    return $headers;
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

    $project  = (string) ($serviceAccount['project_id'] ?? '');
    $response = googleSheetsHttpRequest(
        'POST',
        $url,
        googleSheetsAuthHeaders($token, $project),
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

    $project  = (string) ($serviceAccount['project_id'] ?? '');
    $response = googleSheetsHttpRequest(
        'GET',
        $url,
        googleSheetsAuthHeaders($token, $project, false),
        null
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
 * Actionable hint when Google returns 403 on create (API project / billing / enablement).
 */
function googleSheetsCreatePermissionHint(?string $apiBody, ?string $projectId = null): string
{
    $body = strtolower((string) $apiBody);
    $project = $projectId !== null && $projectId !== '' ? $projectId : 'your JSON project_id';

    if (str_contains($body, 'service_disabled') || str_contains($body, 'has not been used in project')) {
        return 'Enable Google Sheets API and Google Drive API on GCP project “'
            . $project
            . '” (APIs & Services → Library), wait 5 minutes, then retry.';
    }

    if (str_contains($body, 'user_project_denied') || str_contains($body, ',event-staff')) {
        return 'Deploy the latest google-sheets-sync.php (duplicate quota project header bug). Then retry; if still failing, grant the service account “Service Usage Consumer” on project “'
            . $project . '”.';
    }

    if (str_contains($body, 'storage quota') || str_contains($body, 'storagequotaexceeded')) {
        return 'Google Drive storage is full for the Gmail account that owns the shared folder (check https://one.google.com/storage). '
            . 'Delete old files or buy more storage, then retry. Template copy cannot run until there is free space.';
    }

    return 'In Google Cloud project “' . $project
        . '”: confirm Sheets + Drive APIs are enabled, link billing, IAM → grant this service account “Service Usage Consumer” or Editor, wait 10 min, re-upload JSON key, retry.';
}

/**
 * Short error summary from a Google API JSON body.
 */
function googleSheetsSummarizeApiError(string $body): string
{
    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['error']) || !is_array($data['error'])) {
        return mb_substr(trim($body), 0, 200);
    }

    $err    = $data['error'];
    $msg    = (string) ($err['message'] ?? 'Unknown error');
    $status = (string) ($err['status'] ?? '');
    $reason = '';
    foreach ($err['details'] ?? [] as $detail) {
        if (is_array($detail) && ($detail['reason'] ?? '') !== '') {
            $reason = (string) $detail['reason'];
            break;
        }
    }

    $line = $msg;
    if ($status !== '') {
        $line = $status . ': ' . $line;
    }
    if ($reason !== '') {
        $line .= ' (' . $reason . ')';
    }

    return $line;
}

/**
 * @param array<string, mixed> $serviceAccount
 */
function googleDriveDeleteFile(array $serviceAccount, string $fileId): bool
{
    $fileId = trim($fileId);
    if ($fileId === '') {
        return false;
    }

    $project = (string) ($serviceAccount['project_id'] ?? '');
    $token   = googleSheetsGetAccessToken($serviceAccount, ['https://www.googleapis.com/auth/drive']);
    if ($token === '') {
        return false;
    }

    $response = googleSheetsHttpRequest(
        'DELETE',
        'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId),
        googleSheetsAuthHeaders($token, $project, false)
    );

    return $response['code'] >= 200 && $response['code'] < 300;
}

/**
 * @param array<string, mixed> $serviceAccount
 * @return list<array{id: string, name: string, createdTime: string}>
 */
function googleDriveListOwnedSpreadsheets(array $serviceAccount, int $maxResults = 500): array
{
    $project = (string) ($serviceAccount['project_id'] ?? '');
    $token   = googleSheetsGetAccessToken($serviceAccount, ['https://www.googleapis.com/auth/drive.readonly']);
    if ($token === '') {
        $token = googleSheetsGetAccessToken($serviceAccount, ['https://www.googleapis.com/auth/drive']);
    }
    if ($token === '') {
        return [];
    }

    $files      = [];
    $pageToken  = '';
    $remaining  = max(1, min(1000, $maxResults));

    do {
        $pageSize = min(100, $remaining);
        $url      = 'https://www.googleapis.com/drive/v3/files?'
            . 'q=' . rawurlencode("mimeType='application/vnd.google-apps.spreadsheet' and trashed=false")
            . '&fields=nextPageToken,files(id,name,createdTime)'
            . '&pageSize=' . $pageSize
            . '&orderBy=createdTime desc';
        if ($pageToken !== '') {
            $url .= '&pageToken=' . rawurlencode($pageToken);
        }

        $response = googleSheetsHttpRequest(
            'GET',
            $url,
            googleSheetsAuthHeaders($token, $project, false)
        );

        if ($response['code'] !== 200) {
            googleSheetsLog('Drive list spreadsheets failed: HTTP ' . $response['code'] . ' — ' . $response['body']);
            break;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            break;
        }

        foreach ($data['files'] ?? [] as $file) {
            if (!is_array($file)) {
                continue;
            }
            $id = (string) ($file['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $files[] = [
                'id'          => $id,
                'name'        => (string) ($file['name'] ?? ''),
                'createdTime' => (string) ($file['createdTime'] ?? ''),
            ];
            $remaining--;
            if ($remaining <= 0) {
                break 2;
            }
        }

        $pageToken = (string) ($data['nextPageToken'] ?? '');
    } while ($pageToken !== '');

    return $files;
}

/**
 * Delete diagnostic probe/test sheets and empty Drive trash for the service account.
 *
 * @param array<string, mixed> $serviceAccount
 * @return array{deleted: int, listed: int, message: string}
 */
function googleDrivePurgeTestSpreadsheets(array $serviceAccount): array
{
    $patterns = [
        'event staff api probe',
        'event staff api test',
        'event staff api',
        'api probe',
        'api test',
    ];
    $files   = googleDriveListOwnedSpreadsheets($serviceAccount, 1000);
    $deleted = 0;

    foreach ($files as $file) {
        $name = mb_strtolower($file['name']);
        $match = false;
        foreach ($patterns as $pattern) {
            if (str_contains($name, $pattern)) {
                $match = true;
                break;
            }
        }
        if (!$match) {
            continue;
        }
        if (googleDriveDeleteFile($serviceAccount, $file['id'])) {
            $deleted++;
        }
    }

    googleDriveEmptyTrash($serviceAccount);

    $listed = count($files);
    $message = $deleted > 0
        ? "Deleted {$deleted} test spreadsheet(s). Listed {$listed} total owned by the service account."
        : "No test spreadsheets matched (probe/test names). Listed {$listed} total — delete old sheets in Drive or purge manually via API.";

    googleSheetsLog('Purge test spreadsheets: ' . $message);

    return ['deleted' => $deleted, 'listed' => $listed, 'message' => $message];
}

/**
 * Delete every spreadsheet owned by the service account (frees Drive quota).
 * Event rows may still have old sheet URLs — re-run Create Google Sheet(s) after.
 *
 * @param array<string, mixed> $serviceAccount
 * @return array{deleted: int, listed: int, message: string}
 */
function googleDrivePurgeAllOwnedSpreadsheets(array $serviceAccount): array
{
    $files   = googleDriveListOwnedSpreadsheets($serviceAccount, 1000);
    $deleted = 0;

    foreach ($files as $file) {
        if (googleDriveDeleteFile($serviceAccount, $file['id'])) {
            $deleted++;
        }
    }

    googleDriveEmptyTrash($serviceAccount);

    $listed  = count($files);
    $message = "Deleted {$deleted} of {$listed} spreadsheet(s) owned by the service account, then emptied trash.";

    googleSheetsLog('Purge ALL service account spreadsheets: ' . $message);

    return ['deleted' => $deleted, 'listed' => $listed, 'message' => $message];
}

/**
 * @param array<string, mixed> $serviceAccount
 * @return array{limit: string, usage: string, usageInDrive: string}|null
 */
function googleDriveStorageQuota(array $serviceAccount): ?array
{
    $project = (string) ($serviceAccount['project_id'] ?? '');
    $token   = googleSheetsGetAccessToken($serviceAccount, ['https://www.googleapis.com/auth/drive.readonly']);
    if ($token === '') {
        $token = googleSheetsGetAccessToken($serviceAccount, ['https://www.googleapis.com/auth/drive']);
    }
    if ($token === '') {
        return null;
    }

    $response = googleSheetsHttpRequest(
        'GET',
        'https://www.googleapis.com/drive/v3/about?fields=storageQuota',
        googleSheetsAuthHeaders($token, $project, false)
    );

    if ($response['code'] !== 200) {
        return null;
    }

    $data  = json_decode($response['body'], true);
    $quota = is_array($data) ? ($data['storageQuota'] ?? null) : null;

    if (!is_array($quota)) {
        return null;
    }

    return [
        'limit'         => (string) ($quota['limit'] ?? ''),
        'usage'         => (string) ($quota['usage'] ?? ''),
        'usageInDrive'  => (string) ($quota['usageInDrive'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $serviceAccount
 */
function googleDriveEmptyTrash(array $serviceAccount): bool
{
    $project = (string) ($serviceAccount['project_id'] ?? '');
    $token   = googleSheetsGetAccessToken($serviceAccount, ['https://www.googleapis.com/auth/drive']);
    if ($token === '') {
        return false;
    }

    $response = googleSheetsHttpRequest(
        'DELETE',
        'https://www.googleapis.com/drive/v3/files/trash',
        googleSheetsAuthHeaders($token, $project, false)
    );

    return $response['code'] >= 200 && $response['code'] < 300;
}

/**
 * @param array<string, mixed> $serviceAccount
 */
function googleSheetsProbeCleanupCreatedFile(array $serviceAccount, int $httpCode, string $responseBody, bool $driveApi): void
{
    if ($httpCode < 200 || $httpCode >= 300) {
        return;
    }

    $data = json_decode($responseBody, true);
    if (!is_array($data)) {
        return;
    }

    $id = $driveApi
        ? (string) ($data['id'] ?? '')
        : (string) ($data['spreadsheetId'] ?? '');

    if ($id !== '' && googleDriveDeleteFile($serviceAccount, $id)) {
        googleSheetsLog('Probe cleanup: deleted temporary spreadsheet ' . $id);
    }
}

/**
 * Raw create probes for admin diagnostic (Sheets API vs Drive API).
 * Successful probes delete the temporary file so tests do not fill Drive quota.
 *
 * @param array<string, mixed> $serviceAccount
 * @return array{sheets: array{code: int, summary: string}, drive: array{code: int, summary: string}}
 */
function googleSheetsProbeCreate(array $serviceAccount, string $title = 'Event Staff API probe', ?string $parentFolderId = null): array
{
    $parentFolderId = $parentFolderId ?? getGoogleSheetsDriveParentFolderId();
    $project = (string) ($serviceAccount['project_id'] ?? '');
    $result  = [
        'sheets' => ['code' => 0, 'summary' => 'No token'],
        'drive'  => ['code' => 0, 'summary' => 'No token'],
    ];

    $tokenSheets = googleSheetsGetAccessToken($serviceAccount, ['https://www.googleapis.com/auth/spreadsheets']);
    if ($tokenSheets !== '') {
        $payload = json_encode(['properties' => ['title' => $title]], JSON_UNESCAPED_UNICODE);
        $resp    = googleSheetsHttpRequest(
            'POST',
            'https://sheets.googleapis.com/v4/spreadsheets',
            googleSheetsAuthHeaders($tokenSheets, $project),
            $payload ?: '{}'
        );
        $ok = $resp['code'] >= 200 && $resp['code'] < 300;
        if ($ok) {
            googleSheetsProbeCleanupCreatedFile($serviceAccount, $resp['code'], $resp['body'], false);
        }
        $result['sheets'] = [
            'code'    => $resp['code'],
            'summary' => $ok ? 'OK (temp file removed)' : googleSheetsSummarizeApiError($resp['body']),
        ];
    }

    $tokenDrive = googleSheetsGetAccessToken($serviceAccount, ['https://www.googleapis.com/auth/drive']);
    if ($tokenDrive !== '') {
        $fileMeta = [
            'name'     => $title,
            'mimeType' => 'application/vnd.google-apps.spreadsheet',
        ];
        if ($parentFolderId !== '') {
            $fileMeta['parents'] = [$parentFolderId];
        }
        $payload = json_encode($fileMeta, JSON_UNESCAPED_UNICODE);
        $resp    = googleSheetsHttpRequest(
            'POST',
            'https://www.googleapis.com/drive/v3/files?supportsAllDrives=true',
            googleSheetsAuthHeaders($tokenDrive, $project),
            $payload ?: '{}'
        );
        $ok = $resp['code'] >= 200 && $resp['code'] < 300;
        if ($ok) {
            googleSheetsProbeCleanupCreatedFile($serviceAccount, $resp['code'], $resp['body'], true);
        }
        $result['drive'] = [
            'code'    => $resp['code'],
            'summary' => $ok ? 'OK (temp file removed)' : googleSheetsSummarizeApiError($resp['body']),
        ];
    }

    return $result;
}

/**
 * @param array<string, mixed> $serviceAccount
 */
function googleSheetsRenameFirstTab(array $serviceAccount, string $spreadsheetId, string $tabName): bool
{
    $tabName = trim($tabName) !== '' ? trim($tabName) : 'Registrations';
    $project = (string) ($serviceAccount['project_id'] ?? '');
    $token   = googleSheetsGetAccessToken($serviceAccount, ['https://www.googleapis.com/auth/spreadsheets']);
    if ($token === '') {
        return false;
    }

    $payload = json_encode([
        'requests' => [[
            'updateSheetProperties' => [
                'properties' => ['sheetId' => 0, 'title' => $tabName],
                'fields'     => 'title',
            ],
        ]],
    ], JSON_UNESCAPED_UNICODE);

    if ($payload === false) {
        return false;
    }

    $url      = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . ':batchUpdate';
    $response = googleSheetsHttpRequest(
        'POST',
        $url,
        googleSheetsAuthHeaders($token, $project),
        $payload
    );

    return $response['code'] >= 200 && $response['code'] < 300;
}

/**
 * @param array<string, mixed> $serviceAccount
 * @return list<array{id: string, name: string}>
 */
function googleDriveListSpreadsheetsInFolder(array $serviceAccount, string $folderId, int $maxResults = 50): array
{
    $folderId = trim($folderId);
    if ($folderId === '') {
        return [];
    }

    $project = (string) ($serviceAccount['project_id'] ?? '');
    $token   = googleSheetsGetAccessToken($serviceAccount, ['https://www.googleapis.com/auth/drive.readonly']);
    if ($token === '') {
        $token = googleSheetsGetAccessToken($serviceAccount, ['https://www.googleapis.com/auth/drive']);
    }
    if ($token === '') {
        return [];
    }

    $q = sprintf("'%s' in parents and mimeType='application/vnd.google-apps.spreadsheet' and trashed=false", str_replace("'", "\\'", $folderId));
    $url = 'https://www.googleapis.com/drive/v3/files?'
        . 'q=' . rawurlencode($q)
        . '&fields=files(id,name)&pageSize=' . min(50, max(1, $maxResults))
        . '&supportsAllDrives=true&includeItemsFromAllDrives=true';

    $response = googleSheetsHttpRequest(
        'GET',
        $url,
        googleSheetsAuthHeaders($token, $project, false)
    );

    if ($response['code'] !== 200) {
        return [];
    }

    $data  = json_decode($response['body'], true);
    $files = [];
    foreach ($data['files'] ?? [] as $file) {
        if (!is_array($file)) {
            continue;
        }
        $id = (string) ($file['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $files[] = ['id' => $id, 'name' => (string) ($file['name'] ?? '')];
    }

    return $files;
}

/**
 * @param array<string, mixed> $serviceAccount
 */
function googleDriveResolveTemplateSpreadsheetId(array $serviceAccount, string $folderId, ?PDO $pdo = null): ?string
{
    $configured = getGoogleSheetsTemplateSpreadsheetId($pdo);
    if ($configured !== '') {
        return $configured;
    }

    foreach (googleDriveListSpreadsheetsInFolder($serviceAccount, $folderId, 50) as $file) {
        $name = mb_strtolower(trim($file['name']));
        if ($name === 'event staff template' || str_contains($name, 'event staff template')) {
            return $file['id'];
        }
    }

    return null;
}

/**
 * Copy an existing sheet in the shared folder (works with personal Gmail; avoids SA quota).
 *
 * @param array<string, mixed> $serviceAccount
 * @return array{spreadsheetId: string, tabName: string, url: string}|null
 */
function googleDriveCopySpreadsheetFromTemplate(
    array $serviceAccount,
    string $templateId,
    string $title,
    string $parentFolderId,
    string $tabName
): ?array {
    $project = (string) ($serviceAccount['project_id'] ?? '');
    $token   = googleSheetsGetAccessToken($serviceAccount, ['https://www.googleapis.com/auth/drive']);
    if ($token === '') {
        return null;
    }

    $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($templateId)
        . '/copy?supportsAllDrives=true&includeItemsFromAllDrives=true';

    $attempts = [
        json_encode(['name' => $title], JSON_UNESCAPED_UNICODE),
        json_encode(['name' => $title, 'parents' => [$parentFolderId]], JSON_UNESCAPED_UNICODE),
    ];

    $response = ['code' => 0, 'body' => ''];
    foreach ($attempts as $payload) {
        if ($payload === false) {
            continue;
        }
        $response = googleSheetsHttpRequest(
            'POST',
            $url,
            googleSheetsAuthHeaders($token, $project),
            $payload
        );
        if ($response['code'] >= 200 && $response['code'] < 300) {
            break;
        }
    }

    if ($response['code'] < 200 || $response['code'] >= 300) {
        googleSheetsLog(
            'Drive copy template failed: HTTP ' . $response['code'] . ' — ' . $response['body']
            . ' | ' . googleSheetsCreatePermissionHint($response['body'], $project)
        );

        return null;
    }

    $data = json_decode($response['body'], true);
    $id   = is_array($data) ? (string) ($data['id'] ?? '') : '';
    if ($id === '') {
        return null;
    }

    $effectiveTab = $tabName;
    if (!googleSheetsRenameFirstTab($serviceAccount, $id, $tabName)) {
        $effectiveTab = 'Sheet1';
    }

    googleSheetsLog('Created spreadsheet via Drive copy: ' . buildGoogleSheetsSpreadsheetUrl($id));

    return [
        'spreadsheetId' => $id,
        'tabName'       => $effectiveTab,
        'url'           => buildGoogleSheetsSpreadsheetUrl($id),
    ];
}

/**
 * Create via Drive API (copy template first, then create in folder).
 *
 * @param array<string, mixed> $serviceAccount
 * @return array{spreadsheetId: string, tabName: string, url: string}|null
 */
function googleDriveCreateSpreadsheet(
    array $serviceAccount,
    string $title,
    string $tabName,
    ?string $parentFolderId = null
): ?array {
    $tabName = trim($tabName) !== '' ? trim($tabName) : 'Registrations';
    $project = (string) ($serviceAccount['project_id'] ?? '');
    $token   = googleSheetsGetAccessToken($serviceAccount, ['https://www.googleapis.com/auth/drive']);
    if ($token === '') {
        return null;
    }

    $parentFolderId = $parentFolderId ?? getGoogleSheetsDriveParentFolderId();

    $templateId = $parentFolderId !== ''
        ? googleDriveResolveTemplateSpreadsheetId($serviceAccount, $parentFolderId)
        : null;

    if ($templateId !== null && $templateId !== '') {
        return googleDriveCopySpreadsheetFromTemplate(
            $serviceAccount,
            $templateId,
            $title,
            $parentFolderId,
            $tabName
        );
    }

    googleSheetsLog(
        'No template sheet in folder — add a blank Google Sheet named "Event Staff Template" inside your shared folder.'
    );

    return null;
}

/**
 * @param array<string, mixed> $serviceAccount
 * @return array{spreadsheetId: string, tabName: string, url: string}|null
 */
function googleSheetsCreateSpreadsheet(
    array $serviceAccount,
    string $title,
    string $tabName = 'Registrations',
    ?string $parentFolderId = null
): ?array {
    $tabName = trim($tabName) !== '' ? trim($tabName) : 'Registrations';
    $parentFolderId = $parentFolderId ?? getGoogleSheetsDriveParentFolderId();

    if ($parentFolderId === '') {
        googleSheetsLog('Cannot create sheet: google_sheets_drive_folder_id is not set (share a Drive folder with the service account).');

        return null;
    }

    $inspect = googleDriveInspectParentFolder($serviceAccount, $parentFolderId);
    if (!$inspect['ok']) {
        googleSheetsLog('Drive parent folder check failed: ' . $inspect['summary']);

        return null;
    }

    $viaDrive = googleDriveCreateSpreadsheet($serviceAccount, $title, $tabName, $parentFolderId);
    if ($viaDrive !== null) {
        $headerRows = [getGoogleSheetsExportHeaders()];
        if (!googleSheetsAppendRows($serviceAccount, $viaDrive['spreadsheetId'], $viaDrive['tabName'], $headerRows)) {
            googleSheetsLog('Drive-created sheet ' . $viaDrive['spreadsheetId'] . ' but header row failed');
        }

        return $viaDrive;
    }

    return null;
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

    $project = (string) ($serviceAccount['project_id'] ?? '');
    $url     = 'https://www.googleapis.com/drive/v3/files/'
        . rawurlencode($spreadsheetId)
        . '/permissions?supportsAllDrives=true';

    $response = googleSheetsHttpRequest(
        'POST',
        $url,
        googleSheetsAuthHeaders($token, $project),
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
        $projectId = is_array($serviceAccount) ? (string) ($serviceAccount['project_id'] ?? '') : '';
        $hint      = googleSheetsCreatePermissionHint(getLastGoogleSheetsApiError(), $projectId !== '' ? $projectId : null);

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
