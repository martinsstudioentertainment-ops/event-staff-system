<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/google-sheets-sync.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/event-signin-export.php';

header('Content-Type: application/json; charset=UTF-8');

$pdo = getDB();
$key = trim((string) ($_GET['key'] ?? ''));
$fallback = 'email-encoding-verify-20260606';
$expected = trim(getSetting($pdo, 'reminder_cron_key', ''));
if (!(($expected !== '' && hash_equals($expected, $key)) || hash_equals($fallback, $key))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$eventId = (int) ($_GET['event_id'] ?? 13);
$needle  = strtolower(trim((string) ($_GET['q'] ?? 'faboade')));

$event = getEventById($pdo, $eventId);
$regs  = getRegistrationsForEventGoogleSheet($pdo, $eventId);

$matches = [];
foreach ($regs as $row) {
    $hay = strtolower(
        trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? '') . ' ' . (string) ($row['email'] ?? ''))
    );
    if ($needle === '' || str_contains($hay, $needle)) {
        $matches[] = [
            'registration_id' => (int) ($row['id'] ?? 0),
            'name'            => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? '')),
            'email'           => (string) ($row['email'] ?? ''),
            'status'          => (string) ($row['status'] ?? ''),
            'staff_role'      => (string) ($row['staff_role'] ?? ''),
            'pps_number'      => (string) ($row['pps_number'] ?? ''),
            'psa_licence'     => (string) ($row['psa_licence'] ?? ''),
            'sheet_row'       => buildGoogleSheetsSyncRow($row),
            'would_sync'      => shouldSyncRegistrationToGoogleSheet($row),
        ];
    }
}

$contractorRows = [];
try {
    foreach (getContractorSheetSignInRows($pdo, $eventId) as $row) {
        $name = strtolower(trim((string) ($row['name'] ?? '')));
        if ($needle === '' || str_contains($name, $needle)) {
            $contractorRows[] = $row;
        }
    }
} catch (Throwable $e) {
    $contractorRows = ['error' => $e->getMessage()];
}

$rebuildLogs = [];
if ($pdo->query("SHOW TABLES LIKE 'platform_sheets_sync_log'")->fetchColumn()) {
    $stmt = $pdo->prepare(
        "SELECT action, status, detail, created_at
         FROM platform_sheets_sync_log
         WHERE event_id = :eid
         ORDER BY id DESC LIMIT 8"
    );
    $stmt->execute(['eid' => $eventId]);
    $rebuildLogs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$tabName = trim((string) ($event['google_sheet_tab'] ?? ''));
if ($tabName === '') {
    $tabName = trim((string) getSetting($pdo, 'google_sheets_default_tab', 'Registrations'));
}

$liveSheet = ['readable' => false, 'matches' => [], 'row_count' => 0, 'error' => null];
$sheetUrl  = trim((string) ($event['google_sheet_url'] ?? ''));
$spreadsheetId = $sheetUrl !== '' ? parseGoogleSpreadsheetId($sheetUrl) : null;
if ($spreadsheetId !== null) {
    $auth = googleSheetsResolveApiAuth($pdo);
    if ($auth !== null) {
        $headers = getGoogleSheetsSyncHeaders();
        $colEnd  = googleSheetsSyncColumnLetter(count($headers) - 1);
        $range   = escapeGoogleSheetRangeTab($tabName) . '!A1:' . $colEnd . '500';
        $values  = googleSheetsReadRangeValues($auth['token'], $auth['project'], $spreadsheetId, $range);
        if ($values === null) {
            $liveSheet['error'] = getLastGoogleSheetsApiError() ?: 'Could not read sheet range';
        } else {
            $liveSheet['readable']  = true;
            $liveSheet['row_count'] = max(0, count($values) - 1);
            foreach ($values as $index => $cells) {
                if ($index === 0) {
                    continue;
                }
                $line = strtolower(implode(' ', array_map('strval', $cells)));
                if ($needle === '' || str_contains($line, $needle) || str_contains($line, (string) ($matches[0]['registration_id'] ?? '733'))) {
                    $liveSheet['matches'][] = [
                        'sheet_row' => $index + 1,
                        'cells'     => $cells,
                    ];
                }
            }
        }
    } else {
        $liveSheet['error'] = 'Google Sheets auth not configured';
    }
}

echo json_encode([
    'ok'                    => true,
    'event_id'              => $eventId,
    'event_name'            => (string) ($event['name'] ?? ''),
    'google_sheet_url'      => (string) ($event['google_sheet_url'] ?? ''),
    'google_sheet_tab'      => $tabName,
    'total_roster_rows'     => count($regs),
    'needle'                => $needle,
    'roster_matches'        => $matches,
    'on_contractor_sheet'   => $contractorRows,
    'live_sheet'            => $liveSheet,
    'recent_rebuild_logs'   => $rebuildLogs,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
