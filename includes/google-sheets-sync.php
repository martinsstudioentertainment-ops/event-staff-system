<?php
/**
 * Append staff registrations to a Google Sheet per event (Sheets API v4 + service account).
 */

require_once __DIR__ . '/google-sheets-schema.php';
require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/google-drive-oauth.php';
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
    return isGoogleSheetsSyncEnabled($pdo) && googleSheetsResolveApiAuth($pdo) !== null;
}

/**
 * Service account JWT or connected Gmail OAuth token for Sheets API writes.
 *
 * @return array{token: string, project: ?string, label: string}|null
 */
function googleSheetsResolveApiAuth(?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? getDB();

    if (!isGoogleSheetsSyncEnabled($pdo)) {
        return null;
    }

    $serviceAccount = loadGoogleServiceAccount();
    if ($serviceAccount !== null) {
        $token = googleSheetsGetAccessToken($serviceAccount);
        if ($token !== '') {
            return [
                'token'   => $token,
                'project' => (string) ($serviceAccount['project_id'] ?? ''),
                'label'   => 'service account',
            ];
        }
    }

    $userToken = googleDriveGetUserAccessToken($pdo);
    if ($userToken !== '') {
        return [
            'token'   => $userToken,
            'project' => null,
            'label'   => 'Gmail OAuth',
        ];
    }

    return null;
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
    return buildGoogleSheetsSyncRow($row);
}

/**
 * Column index (0-based) where Registration ID is stored — after payroll columns.
 */
function googleSheetsRegistrationIdColumnIndex(): int
{
    return count(getEmployeeSpreadsheetHeaders());
}

/**
 * Staff/payroll columns first (A–J), then admin columns — matches Event Staff Template.
 *
 * @return list<string>
 */
function getGoogleSheetsSyncHeaders(): array
{
    return array_merge(
        getEmployeeSpreadsheetHeaders(),
        ['Registration ID', 'Status', 'Event date', 'Event name', 'Role']
    );
}

/** Event column in sheets: date first, then name (matches spreadsheet file titles). */
function formatGoogleSheetEventLabel(array $row): string
{
    require_once __DIR__ . '/events-repository.php';

    $name = trim((string) ($row['event_name'] ?? ''));
    $date = !empty($row['event_date'])
        ? formatEventDateLabel((string) $row['event_date'])
        : '';

    if ($date !== '' && $name !== '') {
        return $date . ' — ' . $name;
    }

    return $name !== '' ? $name : $date;
}

/**
 * @param array<string, mixed> $row
 * @return list<string|int>
 */
function buildGoogleSheetsSyncRow(array $row): array
{
    require_once __DIR__ . '/events-repository.php';

    $eventDate = !empty($row['event_date'])
        ? formatEventDateLabel((string) $row['event_date'])
        : '';
    $eventName = trim((string) ($row['event_name'] ?? ''));

    return array_merge(
        buildEmployeeSpreadsheetRow($row),
        [
            (string) ($row['id'] ?? ''),
            formatStatusLabel((string) ($row['status'] ?? '')),
            $eventDate,
            $eventName,
            formatRoleLabel((string) ($row['staff_role'] ?? '')),
        ]
    );
}

/**
 * @return array<int, list<string>>|null
 */
function googleSheetsReadRangeValues(string $token, ?string $project, string $spreadsheetId, string $range): ?array
{
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/'
        . rawurlencode($spreadsheetId)
        . '/values/'
        . rawurlencode($range);

    $response = googleSheetsHttpRequest(
        'GET',
        $url,
        googleSheetsAuthHeaders($token, $project, false),
        null
    );

    if ($response['code'] !== 200) {
        googleSheetsLog("Read range failed ({$spreadsheetId}): HTTP {$response['code']} — {$response['body']}");

        return null;
    }

    $data = json_decode($response['body'], true);

    return is_array($data['values'] ?? null) ? $data['values'] : [];
}

/**
 * @param list<list<string|int|float|null>> $rows
 */
function googleSheetsWriteRangeValues(
    string $token,
    ?string $project,
    string $spreadsheetId,
    string $range,
    array $rows
): bool {
    if ($rows === []) {
        return true;
    }

    $url = 'https://sheets.googleapis.com/v4/spreadsheets/'
        . rawurlencode($spreadsheetId)
        . '/values/'
        . rawurlencode($range)
        . '?valueInputOption=USER_ENTERED';

    $payload = json_encode(['values' => $rows], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return false;
    }

    $response = googleSheetsHttpRequest(
        'PUT',
        $url,
        googleSheetsAuthHeaders($token, $project),
        $payload
    );

    if ($response['code'] >= 200 && $response['code'] < 300) {
        return true;
    }

    googleSheetsLog("Update range failed ({$spreadsheetId}): HTTP {$response['code']} — {$response['body']}");

    return false;
}

/**
 * @param list<list<string|int|float|null>> $rows
 */
function googleSheetsAppendRangeValues(
    string $token,
    ?string $project,
    string $spreadsheetId,
    string $range,
    array $rows
): bool {
    if ($rows === []) {
        return true;
    }

    $url = 'https://sheets.googleapis.com/v4/spreadsheets/'
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
        googleSheetsAuthHeaders($token, $project),
        $payload
    );

    if ($response['code'] >= 200 && $response['code'] < 300) {
        return true;
    }

    googleSheetsLog("Append failed ({$spreadsheetId}): HTTP {$response['code']} — {$response['body']}");

    return false;
}

function googleSheetsSyncColumnLetter(int $zeroBasedIndex): string
{
    $index = max(0, $zeroBasedIndex);
    $letter = '';
    while ($index >= 0) {
        $letter = chr(65 + ($index % 26)) . $letter;
        $index  = (int) floor($index / 26) - 1;
    }

    return $letter !== '' ? $letter : 'A';
}

function googleSheetsSheetHasSyncHeaders(string $token, ?string $project, string $spreadsheetId, string $tabName): bool
{
    $range  = escapeGoogleSheetRangeTab($tabName) . '!A1:A1';
    $values = googleSheetsReadRangeValues($token, $project, $spreadsheetId, $range);
    $a1     = trim((string) (($values[0] ?? [])[0] ?? ''));

    if (strcasecmp($a1, 'Surname') !== 0) {
        return false;
    }

    $idCol   = googleSheetsSyncColumnLetter(googleSheetsRegistrationIdColumnIndex());
    $idRange = escapeGoogleSheetRangeTab($tabName) . '!' . $idCol . '1:' . $idCol . '1';
    $idRow   = googleSheetsReadRangeValues($token, $project, $spreadsheetId, $idRange);
    $idHeader = trim((string) (($idRow[0] ?? [])[0] ?? ''));

    return strcasecmp($idHeader, 'Registration ID') === 0;
}

function googleSheetsSheetUsesLegacyPayrollHeaders(string $token, ?string $project, string $spreadsheetId, string $tabName): bool
{
    $range  = escapeGoogleSheetRangeTab($tabName) . '!A1:A1';
    $values = googleSheetsReadRangeValues($token, $project, $spreadsheetId, $range);
    $first  = trim((string) (($values[0] ?? [])[0] ?? ''));

    return strcasecmp($first, 'Surname') === 0;
}

/**
 * Row 1: payroll columns then Registration ID, Status, Event date, Event name, Role.
 */
function googleSheetsEnsureSyncHeaders(string $token, ?string $project, string $spreadsheetId, string $tabName): bool
{
    if (googleSheetsSheetHasSyncHeaders($token, $project, $spreadsheetId, $tabName)) {
        return true;
    }

    $headers     = getGoogleSheetsSyncHeaders();
    $colEnd      = googleSheetsSyncColumnLetter(count($headers) - 1);
    $needsRepair = false;

    if (googleSheetsSheetUsesLegacyPayrollHeaders($token, $project, $spreadsheetId, $tabName)) {
        $idCol        = googleSheetsSyncColumnLetter(googleSheetsRegistrationIdColumnIndex());
        $adminHeaders = array_slice($headers, googleSheetsRegistrationIdColumnIndex());
        $range        = escapeGoogleSheetRangeTab($tabName) . '!' . $idCol . '1:' . $colEnd . '1';
        $ok           = googleSheetsWriteRangeValues($token, $project, $spreadsheetId, $range, [$adminHeaders]);
        if ($ok) {
            googleSheetsLog("Extended template headers (K–O) on {$spreadsheetId} tab {$tabName}");
            $needsRepair = true;
        }

        if ($ok && $needsRepair) {
            googleSheetsRepairMisalignedRegistrationRows($token, $project, $spreadsheetId, $tabName);
        }

        return $ok;
    }

    $range = escapeGoogleSheetRangeTab($tabName) . '!A1:' . $colEnd . '1';
    $ok    = googleSheetsWriteRangeValues($token, $project, $spreadsheetId, $range, [$headers]);

    if ($ok) {
        googleSheetsLog("Set sync header row on {$spreadsheetId} tab {$tabName}");
    }

    return $ok;
}

/**
 * Fix rows saved under old headers (ID in column A instead of column K).
 */
function googleSheetsRepairMisalignedRegistrationRows(
    string $token,
    ?string $project,
    string $spreadsheetId,
    string $tabName
): int {
    $pdo = getDB();
    $range  = escapeGoogleSheetRangeTab($tabName) . '!A2:O500';
    $values = googleSheetsReadRangeValues($token, $project, $spreadsheetId, $range);
    if ($values === null || $values === []) {
        return 0;
    }

    $headers   = getGoogleSheetsSyncHeaders();
    $colEnd    = googleSheetsSyncColumnLetter(count($headers) - 1);
    $repaired  = 0;

    foreach ($values as $index => $cells) {
        $regId = (int) trim((string) ($cells[0] ?? ''));
        if ($regId < 1) {
            continue;
        }

        $statusGuess = strtolower(trim((string) ($cells[1] ?? '')));
        if (!in_array($statusGuess, ['pending', 'approved', 'rejected'], true)) {
            continue;
        }

        $row = getStaffRegistrationById($pdo, $regId);
        if ($row === null) {
            continue;
        }

        $sheetRow = $index + 2;
        $rowRange = escapeGoogleSheetRangeTab($tabName) . '!A' . $sheetRow . ':' . $colEnd . $sheetRow;
        if (googleSheetsWriteRangeValues($token, $project, $spreadsheetId, $rowRange, [buildGoogleSheetsSyncRow($row)])) {
            $repaired++;
        }
    }

    if ($repaired > 0) {
        googleSheetsLog("Repaired {$repaired} misaligned row(s) on {$spreadsheetId}");
    }

    return $repaired;
}

function googleSheetsFindRegistrationRowNumber(
    string $token,
    ?string $project,
    string $spreadsheetId,
    string $tabName,
    int $registrationId
): ?int {
    if ($registrationId < 1) {
        return null;
    }

    $idCol  = googleSheetsSyncColumnLetter(googleSheetsRegistrationIdColumnIndex());
    $range  = escapeGoogleSheetRangeTab($tabName) . '!' . $idCol . ':' . $idCol;
    $values = googleSheetsReadRangeValues($token, $project, $spreadsheetId, $range);
    if ($values === null) {
        return null;
    }

    $needle = (string) $registrationId;
    foreach ($values as $index => $row) {
        if ($index === 0) {
            continue;
        }
        if (trim((string) ($row[0] ?? '')) === $needle) {
            return $index + 1;
        }
    }

    // Legacy rows: registration ID was written in column A before headers were fixed.
    $legacy = escapeGoogleSheetRangeTab($tabName) . '!A:A';
    $legacyValues = googleSheetsReadRangeValues($token, $project, $spreadsheetId, $legacy);
    if ($legacyValues !== null) {
        foreach ($legacyValues as $index => $row) {
            if ($index === 0) {
                continue;
            }
            if (trim((string) ($row[0] ?? '')) === $needle) {
                return $index + 1;
            }
        }
    }

    return null;
}

/**
 * @param array{token: string, project: ?string, label: string} $auth
 */
function googleSheetsUpsertRegistrationRow(
    array $auth,
    string $spreadsheetId,
    string $tabName,
    int $registrationId,
    array $rowValues
): bool {
    $token   = $auth['token'];
    $project = $auth['project'];
    $tabName = trim($tabName) !== '' ? trim($tabName) : 'Registrations';
    $colEnd  = googleSheetsSyncColumnLetter(count($rowValues) - 1);

    if (!googleSheetsEnsureSyncHeaders($token, $project, $spreadsheetId, $tabName)) {
        return false;
    }

    $rowsToWrite = [];
    $existingRow = googleSheetsFindRegistrationRowNumber($token, $project, $spreadsheetId, $tabName, $registrationId);
    if ($existingRow !== null) {
        $range = escapeGoogleSheetRangeTab($tabName) . '!A' . $existingRow . ':' . $colEnd . $existingRow;

        return googleSheetsWriteRangeValues($token, $project, $spreadsheetId, $range, [$rowValues]);
    }

    $rowsToWrite[] = $rowValues;
    $range         = escapeGoogleSheetRangeTab($tabName) . '!A:' . $colEnd;

    return googleSheetsAppendRangeValues($token, $project, $spreadsheetId, $range, $rowsToWrite);
}

function googleSheetsLog(string $message): void
{
    $GLOBALS['_event_staff_google_sheets_last_error'] = $message;

    $dir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $logFile = $dir . '/google-sheets.log';
    file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n",
        FILE_APPEND | LOCK_EX
    );

    if (is_file($logFile) && filesize($logFile) > 2097152) {
        $lines = @file($logFile, FILE_IGNORE_NEW_LINES);
        if (is_array($lines) && count($lines) > 400) {
            $tail = array_slice($lines, -400);
            file_put_contents($logFile, implode("\n", $tail) . "\n");
        }
    }
}

function getLastGoogleSheetsApiError(): string
{
    return (string) ($GLOBALS['_event_staff_google_sheets_last_error'] ?? '');
}

function getLastGoogleSheetsApiBody(): string
{
    return (string) ($GLOBALS['_event_staff_google_sheets_last_api_body'] ?? '');
}

function googleSheetsRememberApiFailure(string $logMessage, string $apiBody = ''): void
{
    if ($apiBody !== '') {
        $GLOBALS['_event_staff_google_sheets_last_api_body'] = $apiBody;
    }

    googleSheetsLog($logMessage);
}

/**
 * Pre-flight before auto-creating sheets (Gmail OAuth + folder + template).
 *
 * @return array{ok: bool, message: string}
 */
function googleSheetsValidateSheetCreateSetup(?PDO $pdo = null): array
{
    $pdo = $pdo ?? getDB();

    if (!isGoogleServiceAccountConfigured()) {
        return ['ok' => false, 'message' => 'Upload the service account JSON in Settings → Google Sheets.'];
    }

    $serviceAccount = loadGoogleServiceAccount();
    if ($serviceAccount === null) {
        return ['ok' => false, 'message' => 'Service account credentials missing.'];
    }

    if (getGoogleSheetsDriveParentFolderId($pdo) === '') {
        return ['ok' => false, 'message' => 'Set the Drive folder ID in Settings → Google Sheets.'];
    }

    if (!function_exists('googleDriveOAuthConfigured') || !googleDriveOAuthConfigured($pdo)) {
        return [
            'ok'      => false,
            'message' => 'Connect your Google account in Settings → Google Sheets (required). Service accounts cannot create new spreadsheets.',
        ];
    }

    $userToken = googleDriveGetUserAccessToken($pdo);
    if ($userToken === '') {
        return [
            'ok'      => false,
            'message' => 'Gmail access token expired — open Settings → Google Sheets and click Connect Google account again.',
        ];
    }

    $folderId = getGoogleSheetsDriveParentFolderId($pdo);
    $folderCheck = googleDriveInspectParentFolderWithToken($userToken, null, $folderId);
    if (!$folderCheck['ok']) {
        return [
            'ok'      => false,
            'message' => 'Your connected Gmail cannot open the Drive folder: ' . $folderCheck['summary']
                . ' In Drive, share the folder with the same Gmail you used for Connect Google account (Editor), not only the service account.',
        ];
    }

    if (($folderCheck['mimeType'] ?? '') === 'application/vnd.google-apps.spreadsheet') {
        return [
            'ok'      => false,
            'message' => 'Drive folder ID in Settings is a spreadsheet file, not a folder — open your Event Staff Sheets folder in Drive and paste the folders/… URL.',
        ];
    }

    $templateId = googleDriveResolveTemplateSpreadsheetId($serviceAccount, $folderId, $pdo);
    if ($templateId === null || $templateId === '') {
        return [
            'ok'      => false,
            'message' => 'Add a Google Sheet named Event Staff Template inside your shared Drive folder (or paste its URL in Settings).',
        ];
    }

    $templateCheck = googleDriveInspectParentFolderWithToken($userToken, null, $templateId);
    if (!$templateCheck['ok']) {
        return [
            'ok'      => false,
            'message' => 'Your Gmail cannot open Event Staff Template: ' . $templateCheck['summary']
                . ' Open the folder in Drive while signed in as the connected Gmail and confirm the template file is visible.',
        ];
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * @return array{ok: bool, name: string, mimeType: string, summary: string}
 */
function googleDriveInspectParentFolderWithToken(string $token, ?string $quotaProject, string $fileId): array
{
    $fileId = trim($fileId);
    if ($fileId === '') {
        return ['ok' => false, 'name' => '', 'mimeType' => '', 'summary' => 'ID is empty'];
    }

    if ($token === '') {
        return ['ok' => false, 'name' => '', 'mimeType' => '', 'summary' => 'No access token'];
    }

    $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId)
        . '?fields=id,name,mimeType,capabilities&supportsAllDrives=true';

    $response = googleSheetsHttpRequest(
        'GET',
        $url,
        googleSheetsAuthHeaders($token, $quotaProject, false)
    );

    if ($response['code'] === 404) {
        return [
            'ok'       => false,
            'name'     => '',
            'mimeType' => '',
            'summary'  => 'Not found or no access (404)',
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

    $data = json_decode($response['body'], true);
    $name = is_array($data) ? (string) ($data['name'] ?? '') : '';
    $mime = is_array($data) ? (string) ($data['mimeType'] ?? '') : '';

    return ['ok' => true, 'name' => $name, 'mimeType' => $mime, 'summary' => $name !== '' ? $name : 'OK'];
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
    $name = trim((string) ($event['name'] ?? 'Event'));
    $date = '';
    if (!empty($event['event_date'])) {
        require_once __DIR__ . '/events-repository.php';
        $date = formatEventDateLabel((string) $event['event_date']);
    }

    $title = $date !== '' ? $date . ' — ' . $name . ' — Staff' : $name . ' — Staff';
    $title = preg_replace('/[\[\]\*\/\\\?\:]/', '', $title) ?? $title;

    return mb_substr(trim($title), 0, 100);
}

/**
 * File names to match when linking existing Drive spreadsheets (new + legacy formats).
 *
 * @return list<string>
 */
function buildGoogleSheetsTitleVariantsForEvent(array $event): array
{
    require_once __DIR__ . '/events-repository.php';

    $name      = trim((string) ($event['name'] ?? 'Event'));
    $dateLabel = !empty($event['event_date'])
        ? formatEventDateLabel((string) $event['event_date'])
        : '';
    $ymd = !empty($event['event_date'])
        ? normalizeEventDateYmd((string) $event['event_date'])
        : '';

    $variants = [buildGoogleSheetsTitleForEvent($event)];

    if ($dateLabel !== '') {
        $variants[] = $dateLabel . ' — ' . $name;
        $variants[] = $dateLabel . ' - ' . $name;
        $variants[] = $dateLabel . ' — ' . $name . ' — Staff';
        $compactDate = str_replace('/', '', $dateLabel);
        if ($compactDate !== $dateLabel) {
            $variants[] = $compactDate . ' — ' . $name . ' — Staff';
            $variants[] = $compactDate . ' — ' . $name;
        }
        $variants[] = $name . ' — ' . $dateLabel;
        $variants[] = $name . ' - ' . $dateLabel;
    }
    if ($ymd !== '') {
        $variants[] = $name . ' — ' . $ymd;
        $variants[] = $name . ' - ' . $ymd;
    }

    $unique = [];
    foreach ($variants as $variant) {
        $variant = trim($variant);
        if ($variant !== '' && !in_array($variant, $unique, true)) {
            $unique[] = $variant;
        }
    }

    return $unique;
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
        if (function_exists('googleDriveOAuthConfigured') && googleDriveOAuthConfigured()) {
            return 'Google Drive storage is full for the connected Gmail (or the copy used the service account). '
                . 'Free space at https://one.google.com/storage, purge old test sheets, then Settings → disconnect/reconnect Google account '
                . '(required after a scope update).';
        }

        return 'Service accounts cannot own new files (0 GB). In Settings → Google Sheets, click **Connect Google account** '
            . 'and sign in with your Gmail so copies use your Drive space. Also check https://one.google.com/storage.';
    }

    if (str_contains($body, 'insufficient') && str_contains($body, 'scope')) {
        return 'Reconnect Google account in Settings → Google Sheets (Connect Google account) so the app can copy your template.';
    }

    if (str_contains($body, 'not found') || str_contains($body, '404')) {
        return 'Folder or template not visible to your connected Gmail — share the Drive folder with that Gmail (Editor) and add Event Staff Template inside it.';
    }

    if (str_contains($body, 'caller does not have permission') || str_contains($body, 'forbidden')) {
        return 'Permission denied — share the Drive folder and Event Staff Template with the Gmail you used in Connect Google account (Editor). Reconnect Google account after sharing.';
    }

    $summary = googleSheetsSummarizeApiError((string) $apiBody);
    if ($summary !== '' && $summary !== trim((string) $apiBody)) {
        return $summary
            . ' — If this persists: Settings → Connect Google account; confirm folder + template are shared with that Gmail; run Google Sheets diagnostic.';
    }

    return 'In Google Cloud project “' . $project
        . '”: enable Sheets + Drive APIs and billing. For auto-create, Connect Google account in Settings (Gmail copies the template — the service account alone cannot create files).';
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
/**
 * @return array{limit: int, usage: int, usageInDrive: int, email: string}|null
 */
function googleDriveUserStorageQuota(?PDO $pdo = null): ?array
{
    $token = googleDriveGetUserAccessToken($pdo);
    if ($token === '') {
        return null;
    }

    $response = googleSheetsHttpRequest(
        'GET',
        'https://www.googleapis.com/drive/v3/about?fields=storageQuota,user',
        googleSheetsAuthHeaders($token, null, false)
    );

    if ($response['code'] !== 200) {
        return null;
    }

    $data  = json_decode($response['body'], true);
    $quota = is_array($data) ? ($data['storageQuota'] ?? null) : null;
    if (!is_array($quota)) {
        return null;
    }

    $user = is_array($data['user'] ?? null) ? $data['user'] : [];

    return [
        'limit'        => (int) ($quota['limit'] ?? 0),
        'usage'        => (int) ($quota['usage'] ?? 0),
        'usageInDrive' => (int) ($quota['usageInDrive'] ?? 0),
        'email'        => (string) ($user['emailAddress'] ?? ''),
    ];
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
        . '&fields=files(id,name)&pageSize=' . min(100, max(1, $maxResults))
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
    $project   = (string) ($serviceAccount['project_id'] ?? '');
    $userToken = googleDriveGetUserAccessToken();
    $oauthOn   = function_exists('googleDriveOAuthConfigured') && googleDriveOAuthConfigured();

    $tokenPlan = [];
    if ($userToken !== '') {
        $tokenPlan[] = ['token' => $userToken, 'label' => 'Gmail OAuth', 'quotaProject' => null];
    } elseif ($oauthOn) {
        googleSheetsRememberApiFailure(
            'Drive copy: Gmail is connected but no user access token — open Settings → Google Sheets → Connect Google account again.'
        );

        return null;
    } else {
        googleSheetsRememberApiFailure(
            'Drive copy: Connect your Google account in Settings → Google Sheets. Service accounts cannot create new spreadsheets (no Drive storage).'
        );

        return null;
    }

    $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($templateId)
        . '/copy?supportsAllDrives=true&includeItemsFromAllDrives=true';

    $attempts = [
        json_encode(['name' => $title], JSON_UNESCAPED_UNICODE),
        json_encode(['name' => $title, 'parents' => [$parentFolderId]], JSON_UNESCAPED_UNICODE),
    ];

    $response = ['code' => 0, 'body' => ''];
    foreach ($tokenPlan as $plan) {
        foreach ($attempts as $payload) {
            if ($payload === false) {
                continue;
            }
            $response = googleSheetsHttpRequest(
                'POST',
                $url,
                googleSheetsAuthHeaders($plan['token'], $plan['quotaProject']),
                $payload
            );
            if ($response['code'] >= 200 && $response['code'] < 300) {
                break 2;
            }
        }
        googleSheetsRememberApiFailure(
            'Drive copy (' . $plan['label'] . ') failed: HTTP ' . $response['code'] . ' — '
            . googleSheetsSummarizeApiError($response['body']),
            $response['body']
        );
    }

    if ($response['code'] < 200 || $response['code'] >= 300) {
        $lastLabel = $tokenPlan[count($tokenPlan) - 1]['label'] ?? 'unknown';
        googleSheetsRememberApiFailure(
            'Drive copy template failed (' . $lastLabel . '): HTTP ' . $response['code'] . ' — '
            . googleSheetsSummarizeApiError($response['body'])
            . ' | ' . googleSheetsCreatePermissionHint($response['body'], $project),
            $response['body']
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

    googleSheetsRememberApiFailure(
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
        googleSheetsRememberApiFailure(
            'Cannot create sheet: google_sheets_drive_folder_id is not set (share a Drive folder with your Gmail and the service account).'
        );

        return null;
    }

    $pdo = getDB();
    $preflight = googleSheetsValidateSheetCreateSetup($pdo);
    if (!$preflight['ok']) {
        googleSheetsRememberApiFailure('Create preflight failed: ' . $preflight['message']);

        return null;
    }

    $viaDrive = googleDriveCreateSpreadsheet($serviceAccount, $title, $tabName, $parentFolderId);
    if ($viaDrive !== null) {
        $token = googleSheetsGetAccessToken($serviceAccount);
        if ($token === '') {
            googleSheetsLog('Drive-created sheet ' . $viaDrive['spreadsheetId'] . ' but no token for headers');
        } else {
            $project = (string) ($serviceAccount['project_id'] ?? '');
            if (!googleSheetsEnsureSyncHeaders($token, $project, $viaDrive['spreadsheetId'], $viaDrive['tabName'])) {
                googleSheetsLog('Drive-created sheet ' . $viaDrive['spreadsheetId'] . ' but header row failed');
            }
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

function googleDriveNormalizeSheetTitle(string $title): string
{
    $title = mb_strtolower(trim($title));
    $title = str_replace(['—', '–', '-'], ' ', $title);

    return trim(preg_replace('/\s+/', ' ', $title) ?? '');
}

/**
 * @param list<array{id: string, name: string}> $files
 * @return array<string, array{id: string, name: string}>
 */
function googleDriveIndexSpreadsheetsByTitle(array $files): array
{
    $indexed = [];
    foreach ($files as $file) {
        $name = trim((string) ($file['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $indexed[googleDriveNormalizeSheetTitle($name)] = [
            'id'   => (string) ($file['id'] ?? ''),
            'name' => $name,
        ];
    }

    return $indexed;
}

/**
 * @param array<string, array{id: string, name: string}> $filesByKey
 * @param array<string, mixed> $event
 * @return array{id: string, name: string}|null
 */
function googleDriveMatchSpreadsheetForEvent(array $filesByKey, array $event): ?array
{
    foreach (buildGoogleSheetsTitleVariantsForEvent($event) as $variant) {
        $norm = googleDriveNormalizeSheetTitle($variant);
        if ($norm !== '' && isset($filesByKey[$norm])) {
            return $filesByKey[$norm];
        }
    }

    $nameNorm = googleDriveNormalizeSheetTitle((string) ($event['name'] ?? ''));
    $dateNorm = '';
    if (!empty($event['event_date'])) {
        require_once __DIR__ . '/events-repository.php';
        $dateNorm = googleDriveNormalizeSheetTitle(formatEventDateLabel((string) $event['event_date']));
        $ymdNorm  = googleDriveNormalizeSheetTitle(normalizeEventDateYmd((string) $event['event_date']));
    } else {
        $ymdNorm = '';
    }

    $best      = null;
    $bestScore = 0;
    foreach ($filesByKey as $fileNorm => $file) {
        $score = 0;
        if ($nameNorm !== '' && str_contains($fileNorm, $nameNorm)) {
            $score += 2;
        }
        if ($dateNorm !== '' && str_contains($fileNorm, $dateNorm)) {
            $score += 2;
        } elseif ($ymdNorm !== '' && str_contains($fileNorm, $ymdNorm)) {
            $score += 2;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best      = $file;
        }
    }

    return $bestScore >= 4 ? $best : null;
}

/**
 * Spreadsheets in the shared Drive folder for admin pickers (excludes template).
 *
 * @return list<array{id: string, name: string}>
 */
function listGoogleDriveSpreadsheetsForAdmin(PDO $pdo, int $maxResults = 100): array
{
    $folderId = getGoogleSheetsDriveParentFolderId($pdo);
    if ($folderId === '') {
        return [];
    }

    $serviceAccount = loadGoogleServiceAccount();
    if ($serviceAccount === null) {
        return [];
    }

    $files = googleDriveListSpreadsheetsInFolder($serviceAccount, $folderId, $maxResults);
    $out   = [];
    foreach ($files as $file) {
        $name = trim((string) ($file['name'] ?? ''));
        $id   = trim((string) ($file['id'] ?? ''));
        if ($id === '' || $name === '') {
            continue;
        }
        $lower = mb_strtolower($name);
        if ($lower === 'event staff template' || str_contains($lower, 'event staff template')) {
            continue;
        }
        $out[] = ['id' => $id, 'name' => $name];
    }

    usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

    return $out;
}

/**
 * @param list<int|string> $rawIds
 * @return list<int>
 */
function normalizeBulkEventIds(array $rawIds): array
{
    $ids = [];
    foreach ($rawIds as $rawId) {
        $id = (int) $rawId;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }

    return array_keys($ids);
}

/**
 * Link spreadsheets already in the shared Drive folder to events (by file name).
 *
 * @param list<int>|null $eventIdsOnly When set, only these event IDs are considered.
 * @return array{linked: int, skipped: int, unmatched: int, errors: list<string>}
 */
function linkExistingGoogleSheetsFromDriveFolder(PDO $pdo, ?array $eventIdsOnly = null): array
{
    ensureGoogleSheetsSchema($pdo);

    $stats = ['linked' => 0, 'skipped' => 0, 'unmatched' => 0, 'errors' => []];

    $folderId = getGoogleSheetsDriveParentFolderId($pdo);
    if ($folderId === '') {
        $stats['errors'][] = 'Set the Drive folder ID in Settings → Google Sheets.';

        return $stats;
    }

    $serviceAccount = loadGoogleServiceAccount();
    if ($serviceAccount === null) {
        $stats['errors'][] = 'Upload the service account JSON in Settings.';

        return $stats;
    }

    $fileList      = googleDriveListSpreadsheetsInFolder($serviceAccount, $folderId, 100);
    $filesInFolder = googleDriveIndexSpreadsheetsByTitle($fileList);
    if ($filesInFolder === []) {
        $stats['errors'][] = 'No spreadsheets found in your Drive folder (or API cannot read the folder). Check folder ID and sharing.';

        return $stats;
    }

    $tabName = trim((string) getSetting($pdo, 'google_sheets_default_tab', 'Registrations'));
    if ($tabName === '') {
        $tabName = 'Registrations';
    }

    $update = $pdo->prepare(
        'UPDATE events SET google_sheet_url = :url, google_sheet_tab = :tab WHERE id = :id'
    );

    $onlySet = $eventIdsOnly !== null ? array_fill_keys(normalizeBulkEventIds($eventIdsOnly), true) : null;

    if ($onlySet !== null && $onlySet === []) {
        $stats['errors'][] = 'Select at least one event.';

        return $stats;
    }

    $ids = $pdo->query('SELECT id FROM events ORDER BY event_date ASC, name ASC')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $rawId) {
        $eventId = (int) $rawId;
        if ($eventId < 1) {
            continue;
        }

        if ($onlySet !== null && !isset($onlySet[$eventId])) {
            continue;
        }

        $event = getEventById($pdo, $eventId);
        if (!$event) {
            continue;
        }

        if (trim((string) ($event['google_sheet_url'] ?? '')) !== '') {
            $stats['skipped']++;
            continue;
        }

        $match = googleDriveMatchSpreadsheetForEvent($filesInFolder, $event);
        if ($match === null) {
            $stats['unmatched']++;
            continue;
        }

        $url = buildGoogleSheetsSpreadsheetUrl($match['id']);
        $update->execute(['url' => $url, 'tab' => $tabName, 'id' => $eventId]);
        $stats['linked']++;
        googleSheetsLog("Linked event {$eventId} → {$match['name']} ({$url})");
    }

    return $stats;
}

/**
 * Link one event to a specific spreadsheet from the Drive folder picker.
 */
function linkEventToGoogleSpreadsheetById(PDO $pdo, int $eventId, string $spreadsheetId): bool
{
    ensureGoogleSheetsSchema($pdo);

    $eventId = (int) $eventId;
    if ($eventId < 1) {
        return false;
    }

    $parsed = parseGoogleSpreadsheetId(trim($spreadsheetId));
    if ($parsed === null) {
        return false;
    }

    $event = getEventById($pdo, $eventId);
    if ($event === null) {
        return false;
    }

    $allowed = [];
    foreach (listGoogleDriveSpreadsheetsForAdmin($pdo) as $file) {
        $allowed[$file['id']] = true;
    }
    if ($allowed !== [] && !isset($allowed[$parsed])) {
        return false;
    }

    $tabName = trim((string) getSetting($pdo, 'google_sheets_default_tab', 'Registrations'));
    if ($tabName === '') {
        $tabName = 'Registrations';
    }

    $url = buildGoogleSheetsSpreadsheetUrl($parsed);
    $stmt = $pdo->prepare(
        'UPDATE events SET google_sheet_url = :url, google_sheet_tab = :tab WHERE id = :id'
    );
    $stmt->execute(['url' => $url, 'tab' => $tabName, 'id' => $eventId]);

    googleSheetsLog("Manually linked event {$eventId} → sheet {$parsed} ({$url})");

    return $stmt->rowCount() > 0;
}

/**
 * Remove the linked Google Sheet URL from an event (does not delete the file in Drive).
 */
function unlinkEventGoogleSheet(PDO $pdo, int $eventId): bool
{
    ensureGoogleSheetsSchema($pdo);

    $event = getEventById($pdo, $eventId);
    if ($event === null) {
        return false;
    }

    if (trim((string) ($event['google_sheet_url'] ?? '')) === '') {
        return true;
    }

    $stmt = $pdo->prepare(
        'UPDATE events SET google_sheet_url = NULL, google_sheet_tab = NULL WHERE id = :id'
    );
    $stmt->execute(['id' => $eventId]);

    googleSheetsLog('Unlinked event ' . $eventId . ' from Google Sheet (file unchanged in Drive)');

    return $stmt->rowCount() > 0;
}

/**
 * @param list<int|string> $eventIds
 */
function unlinkEventGoogleSheetsByIds(PDO $pdo, array $eventIds): int
{
    $count = 0;
    foreach (normalizeBulkEventIds($eventIds) as $eventId) {
        if (unlinkEventGoogleSheet($pdo, $eventId)) {
            $count++;
        }
    }

    return $count;
}

function unlinkAllEventGoogleSheets(PDO $pdo): int
{
    ensureGoogleSheetsSchema($pdo);

    $ids = $pdo->query(
        "SELECT id FROM events WHERE google_sheet_url IS NOT NULL AND TRIM(google_sheet_url) <> ''"
    )->fetchAll(PDO::FETCH_COLUMN);

    return unlinkEventGoogleSheetsByIds($pdo, is_array($ids) ? $ids : []);
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
        $apiBody   = getLastGoogleSheetsApiBody();
        $hint      = googleSheetsCreatePermissionHint($apiBody !== '' ? $apiBody : getLastGoogleSheetsApiError(), $projectId !== '' ? $projectId : null);
        $detail    = getLastGoogleSheetsApiError();
        if (str_starts_with($detail, 'Create preflight failed:')) {
            return ['ok' => false, 'message' => substr($detail, strlen('Create preflight failed: '))];
        }

        return ['ok' => false, 'message' => $hint !== '' ? $hint : ('Google API could not create the spreadsheet. ' . $detail)];
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
 * @param list<int>|null $eventIdsOnly When set, only these event IDs are processed.
 * @return array{created: int, skipped: int, failed: int, errors: list<string>}
 */
function bulkCreateGoogleSheetsForEvents(PDO $pdo, bool $onlyMissing = true, ?array $eventIdsOnly = null): array
{
    ensureGoogleSheetsSchema($pdo);

    $stats = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

    $preflight = googleSheetsValidateSheetCreateSetup($pdo);
    if (!$preflight['ok']) {
        $stats['errors'][] = $preflight['message'];

        return $stats;
    }

    if (!isGoogleServiceAccountConfigured()) {
        $stats['errors'][] = 'Upload the service account JSON in Settings → Google Sheets.';

        return $stats;
    }

    $onlySet = $eventIdsOnly !== null ? array_fill_keys(normalizeBulkEventIds($eventIdsOnly), true) : null;
    if ($onlySet !== null && $onlySet === []) {
        $stats['errors'][] = 'Select at least one event.';

        return $stats;
    }

    $sql = 'SELECT id FROM events';
    if ($onlyMissing && $onlySet === null) {
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

        if ($onlySet !== null && !isset($onlySet[$eventId])) {
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

    $auth = googleSheetsResolveApiAuth($pdo);
    if ($auth === null) {
        googleSheetsLog("Sync skipped for registration {$registrationId}: live sync off or no Google auth");

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
        googleSheetsLog("Sync skipped for registration {$registrationId}: event has no Google Sheet linked");

        return false;
    }

    $tabName = trim((string) ($event['google_sheet_tab'] ?? ''));
    if ($tabName === '') {
        $tabName = 'Registrations';
    }

    $spreadsheetId = parseGoogleSpreadsheetId($sheetUrl);
    if ($spreadsheetId === null) {
        googleSheetsLog("Invalid sheet URL for registration {$registrationId}");

        return false;
    }

    $rowValues = buildGoogleSheetsSyncRow($row);
    $ok        = googleSheetsUpsertRegistrationRow($auth, $spreadsheetId, $tabName, $registrationId, $rowValues);
    if ($ok) {
        googleSheetsLog("Synced registration {$registrationId} → sheet {$spreadsheetId} ({$auth['label']})");
    } else {
        googleSheetsLog("Sync failed for registration {$registrationId} ({$auth['label']}): " . getLastGoogleSheetsApiError());
    }

    return $ok;
}

/**
 * Backfill every registration that belongs to an event with a linked sheet.
 *
 * @return array{synced: int, skipped: int, failed: int}
 */
function syncAllRegistrationsToLinkedGoogleSheets(PDO $pdo): array
{
    ensureGoogleSheetsSchema($pdo);

    $stmt = $pdo->query(
        "SELECT sr.id
         FROM staff_registrations sr
         INNER JOIN events e ON e.id = sr.event_id
         WHERE e.google_sheet_url IS NOT NULL AND TRIM(e.google_sheet_url) <> ''
         ORDER BY sr.id ASC"
    );
    $ids = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

    return syncRegistrationsToGoogleSheets($pdo, array_map('intval', $ids ?: []));
}

/**
 * @param int[] $registrationIds
 * @return array{synced: int, skipped: int, failed: int}
 */
function syncRegistrationsToGoogleSheets(PDO $pdo, array $registrationIds): array
{
    $stats = ['synced' => 0, 'skipped' => 0, 'failed' => 0];

    if (googleSheetsResolveApiAuth($pdo) === null) {
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
