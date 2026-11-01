<?php
declare(strict_types=1);
/**
 * AVIVA Match – Event Staff: Google Form Responses Sheet -> registered-only check -> aviva_match_signups.
 * Additive. Never calls processGoogleFormStaffIntake(), never creates staff.
 * Read auth: googleFormIntakeGetReadAuth() (service account event-staff-sheets@…, same as 1 Oct Aviva).
 */
require_once __DIR__ . '/aviva-match.php';
require_once __DIR__ . '/google-sheets-sync.php';
require_once __DIR__ . '/google-form-event-integrations.php';
require_once __DIR__ . '/aviva-match-onboard.php';

const AVIVA_MATCH_SHEET_DEFAULT_TAB = 'Form Responses 1';
const AVIVA_MATCH_STATUS_TEXT = [
    'accepted'       => 'Accepted',
    'not_registered' => 'Not registered - register on olasentra.com first',
    'duplicate'      => 'Duplicate',
    'full'           => 'Event full',
    'invalid'        => 'Missing email/mobile',
];

function avivaMatchSheetEnsureSchema(PDO $pdo): void
{
    avivaMatchEnsureSchema($pdo);
    // One row per sheet row processed (accepted AND rejected). Only aviva_match_signups counts toward 70.
    $pdo->exec('CREATE TABLE IF NOT EXISTS aviva_match_sheet_rows (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sheet_row INT UNSIGNED NOT NULL,
        submitted_at VARCHAR(40) NOT NULL DEFAULT \'\',
        email VARCHAR(190) NOT NULL DEFAULT \'\',
        mobile VARCHAR(40) NOT NULL DEFAULT \'\',
        full_name VARCHAR(190) NOT NULL DEFAULT \'\',
        status VARCHAR(20) NOT NULL,
        reason VARCHAR(255) NOT NULL DEFAULT \'\',
        staff_id INT UNSIGNED NULL,
        written_back TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_aviva_match_sheet_row (sheet_row)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function avivaMatchSheetId(PDO $pdo): string
{
    return trim((string) getSetting($pdo, 'aviva_match_sheet_id', AVIVA_MATCH_SHEET_ID)) ?: AVIVA_MATCH_SHEET_ID;
}

/** @param array<string,string> $named */
function avivaMatchSheetPick(array $named, array $keys): string
{
    foreach ($keys as $k) {
        foreach ($named as $title => $v) {
            if ($v !== '' && strcasecmp(trim((string) $title), $k) === 0) { return $v; }
        }
    }
    return '';
}

/** Tab: setting aviva_match_sheet_tab, else 'Form Responses 1' if present, else first tab. */
function avivaMatchSheetResolveTab(PDO $pdo, string $token, string $sheetId): string
{
    $set = trim((string) getSetting($pdo, 'aviva_match_sheet_tab', ''));
    if ($set !== '') { return $set; }
    $r = googleSheetsHttpRequest('GET', 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($sheetId)
        . '?fields=sheets.properties.title', googleSheetsAuthHeaders($token, null, false));
    $titles = [];
    if ($r['code'] === 200) {
        foreach ((json_decode($r['body'], true)['sheets'] ?? []) as $s) { $titles[] = (string) ($s['properties']['title'] ?? ''); }
    }
    if (in_array(AVIVA_MATCH_SHEET_DEFAULT_TAB, $titles, true)) { return AVIVA_MATCH_SHEET_DEFAULT_TAB; }
    return $titles[0] ?? AVIVA_MATCH_SHEET_DEFAULT_TAB;
}

/** Best-effort write token (spreadsheets scope). '' when unavailable. */
function avivaMatchSheetWriteToken(): string
{
    try {
        $sa = loadGoogleServiceAccount();
        return $sa ? (string) (googleSheetsGetAccessToken($sa, ['https://www.googleapis.com/auth/spreadsheets']) ?? '') : '';
    } catch (Throwable $e) {
        return '';
    }
}

function avivaMatchSheetColumnLetter(int $zeroBased): string
{
    $n = $zeroBased + 1;
    $s = '';
    while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); }
    return $s;
}

/** @return array<string,mixed> */
function avivaMatchSheetSync(PDO $pdo, int $maxRows = 200): array
{
    avivaMatchSheetEnsureSchema($pdo);
    $out = ['ok' => false, 'action' => 'aviva_match_sheet_sync', 'new' => 0, 'accepted' => 0, 'rejected' => 0,
            'written_back' => 0, 'write_back' => 'skipped'];

    $auth = googleFormIntakeGetReadAuth($pdo);
    if ($auth === null) { return $out + ['error' => 'Google is not connected for sheet sync.']; }
    $token = (string) $auth['token'];
    $sheetId = avivaMatchSheetId($pdo);
    $tab = avivaMatchSheetResolveTab($pdo, $token, $sheetId);
    $tabRef = "'" . str_replace("'", "''", $tab) . "'";
    $out['sheet_id'] = $sheetId;
    $out['tab'] = $tab;

    $resp = googleSheetsHttpRequest('GET', 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($sheetId)
        . '/values/' . rawurlencode($tabRef), googleSheetsAuthHeaders($token, null, false));
    if ($resp['code'] !== 200) {
        $out['http'] = $resp['code'];
        $out['error'] = 'Could not read the AVIVA Match sheet.';
        if (in_array($resp['code'], [403, 404], true)) {
            $out['hint'] = 'Share the sheet with ' . googleFormEventIntegrationServiceAccountEmail() . ' (Editor for Status write-back).';
        }
        return $out;
    }
    $values = json_decode($resp['body'], true)['values'] ?? [];
    if (!is_array($values) || $values === []) { return array_merge($out, ['ok' => true, 'total' => 0]); }

    $header = array_map(static fn($h): string => trim((string) $h), $values[0]);
    $rows = array_slice($values, 1);
    $done = max(0, (int) getSetting($pdo, 'aviva_match_sheet_rows', '0'));
    $out['total'] = count($rows);

    $writeToken = avivaMatchSheetWriteToken();
    $statusCol = array_search('Status', $header, true);
    $writes = [];
    if ($writeToken !== '' && $statusCol === false) {
        $statusCol = count($header);
        $writes[] = ['range' => $tabRef . '!' . avivaMatchSheetColumnLetter($statusCol) . '1', 'values' => [['Status']]];
    }

    $processed = 0;
    foreach ($rows as $i => $row) {
        if ($i < $done) { continue; }
        if ($processed >= $maxRows) { break; }
        $named = [];
        foreach ($header as $c => $title) {
            if ($title !== '' && $title !== 'Status' && !isset($named[$title])) {
                $named[$title] = isset($row[$c]) ? trim((string) $row[$c]) : '';
            }
        }
        $sheetRow = $i + 2; // sheet row number (header is row 1)
        $email = avivaMatchSheetPick($named, ['Email address', 'Email Address', 'Email']);
        $mobile = avivaMatchSheetPick($named, ['Mobile number', 'Mobile', 'Phone']);
        $first = avivaMatchSheetPick($named, ['First name']);
        $sur = avivaMatchSheetPick($named, ['Surname', 'Last name']);

        $st = $pdo->prepare('SELECT status FROM aviva_match_sheet_rows WHERE sheet_row = :r');
        $st->execute(['r' => $sheetRow]);
        $prev = $st->fetchColumn();
        if ($prev !== false) {
            $code = (string) $prev;
        } else {
            $r = avivaMatchSignup($pdo, [
                'email' => $email, 'mobile' => $mobile, 'response_id' => 'sheet-row-' . $sheetRow, 'source' => 'google_form',
                'form_first_name' => $first, 'form_surname' => $sur, 'form_email' => strtolower($email), 'form_mobile' => $mobile,
                'full_address' => avivaMatchSheetPick($named, ['Full address', 'Address']),
                'eircode' => avivaMatchSheetPick($named, ['Eircode']),
                'gender' => avivaMatchSheetPick($named, ['Gender']),
                'bank_iban' => avivaMatchSheetPick($named, ['IBAN']),
                'staff_role' => avivaMatchSheetPick($named, ['Role']),
                'psa_licence' => avivaMatchSheetPick($named, ['PSA licence number']),
                'psa_front_path' => avivaMatchSheetPick($named, ['PSA badge, front', 'PSA badge front']),
                'psa_back_path' => avivaMatchSheetPick($named, ['PSA badge, back', 'PSA badge back']),
            ]);
            $code = !empty($r['ok']) ? 'accepted' : (string) ($r['code'] ?? 'invalid');
            $pdo->prepare('INSERT INTO aviva_match_sheet_rows (sheet_row, submitted_at, email, mobile, full_name, status, reason, staff_id)
                VALUES (:r,:t,:e,:m,:n,:s,:why,:sid)')->execute([
                'r' => $sheetRow, 't' => (string) ($row[0] ?? ''), 'e' => strtolower($email), 'm' => $mobile,
                'n' => trim($first . ' ' . $sur), 's' => $code, 'why' => (string) ($r['message'] ?? ''),
                'sid' => isset($r['staff_id']) ? (int) $r['staff_id'] : null,
            ]);
            $out['new']++;
            if ($code === 'accepted') { $out['accepted']++; } else { $out['rejected']++; }
        }
        if ($writeToken !== '' && $statusCol !== false) {
            $writes[] = ['range' => $tabRef . '!' . avivaMatchSheetColumnLetter((int) $statusCol) . $sheetRow,
                         'values' => [[AVIVA_MATCH_STATUS_TEXT[$code] ?? $code]]];
        }
        $done = $i + 1;
        $processed++;
        setSetting($pdo, 'aviva_match_sheet_rows', (string) $done);
    }

    if ($writeToken !== '' && $writes !== []) {
        $wr = googleSheetsHttpRequest('POST', 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($sheetId)
            . '/values:batchUpdate', googleSheetsAuthHeaders($writeToken, null),
            (string) json_encode(['valueInputOption' => 'RAW', 'data' => $writes], JSON_UNESCAPED_UNICODE));
        if ($wr['code'] >= 200 && $wr['code'] < 300) {
            $out['write_back'] = 'ok';
            $out['written_back'] = count($writes);
            $pdo->exec('UPDATE aviva_match_sheet_rows SET written_back = 1 WHERE written_back = 0');
        } else {
            $out['write_back'] = 'no_access'; // Viewer only: skip silently
        }
    }

    // Accepted rows (new + backfill): PSA check, emails, approved registration, roster (1 Oct steps).
    try {
        $out['onboard'] = avivaMatchOnboardPending($pdo, 10);
    } catch (Throwable $e) {
        $out['onboard'] = ['error' => $e->getMessage()];
    }

    $out['ok'] = true;
    $out['processed_rows'] = $done;
    $out['count'] = avivaMatchCount($pdo);
    $out['capacity'] = AVIVA_MATCH_CAPACITY;
    return $out;
}
