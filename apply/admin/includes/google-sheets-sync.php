<?php

declare(strict_types=1);

/**
 * @return array{spreadsheet_id: string, tab_payroll: string, tab_master: string, tab_master_alt: string}
 */
function apply_sheet_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $defaults = [
        'spreadsheet_id' => '12WiqfB2KS3FeiKeA_APANAvAWJ_QYcGsVbt7iPR7UWM',
        'tab_payroll'    => 'Payroll Staff',
        'tab_master'     => 'Master Sheet',
        'tab_master_alt' => 'Overall',
    ];

    $local = __DIR__ . '/../config/sheets.local.php';
    if (is_readable($local)) {
        $loaded = require $local;
        if (is_array($loaded)) {
            $config = array_merge($defaults, $loaded);

            return $config;
        }
    }

    $config = $defaults;

    return $config;
}

/** @return list<string> */
function apply_payroll_sheet_headers(): array
{
    return [
        'Surname',
        'First Name',
        'Full Address',
        'Postcode',
        'Email',
        'Mobile Number',
        'Date Of Birth',
        'Gender',
        'National Insurance/PPS',
        'Bank Account/IBAN',
    ];
}

/** @return list<string> */
function apply_master_sheet_headers(): array
{
    return array_merge(
        apply_payroll_sheet_headers(),
        ['Registration ID', 'Status', 'Event date', 'Event name', 'Role']
    );
}

function apply_format_gender_label(string $gender): string
{
    return match (strtolower($gender)) {
        'male'              => 'Male',
        'female'            => 'Female',
        'other'             => 'Other',
        'prefer_not_to_say' => 'Prefer not to say',
        default             => $gender === '' ? '' : ucfirst($gender),
    };
}

function apply_format_status_label(string $status): string
{
    return match (strtolower($status)) {
        'approved' => 'Approved',
        'pending'  => 'Pending',
        'rejected' => 'Rejected',
        default    => ucfirst($status),
    };
}

function apply_format_role_label(string $role): string
{
    return match (strtolower($role)) {
        'dsp'     => 'DSP',
        'static'  => 'Static',
        'steward' => 'Steward',
        default   => strtoupper($role),
    };
}

function apply_format_event_date(?string $date): string
{
    if ($date === null || $date === '' || $date === '0000-00-00') {
        return '';
    }

    $ts = strtotime($date);

    return $ts !== false ? date('d/m/Y', $ts) : $date;
}

/**
 * @return list<list<string>>
 */
/**
 * Staff eligible for payroll export / Google Payroll Staff tab.
 * Includes everyone in the vault with an email (approved imports may still be Incomplete until PSA is done).
 */
function apply_payroll_staff_sql_where(): string
{
    return "email != '' AND TRIM(email) != ''";
}

function apply_count_payroll_staff(PDO $applyPdo): int
{
    return (int) $applyPdo->query('SELECT COUNT(*) FROM staff_master WHERE ' . apply_payroll_staff_sql_where())->fetchColumn();
}

/**
 * @return array{vault_total: int, payroll_eligible: int, excluded_status: int, excluded_no_email: int, incomplete: int, expired_psa: int}
 */
function apply_payroll_sync_stats(PDO $applyPdo): array
{
    $vaultTotal = (int) $applyPdo->query('SELECT COUNT(*) FROM staff_master')->fetchColumn();
    $payrollEligible = apply_count_payroll_staff($applyPdo);
    $excludedNoEmail = (int) $applyPdo->query("SELECT COUNT(*) FROM staff_master WHERE email = '' OR email IS NULL OR TRIM(email) = ''")->fetchColumn();
    $incomplete = (int) $applyPdo->query("SELECT COUNT(*) FROM staff_master WHERE profile_status = 'Incomplete'")->fetchColumn();
    $expiredPsa = (int) $applyPdo->query("SELECT COUNT(*) FROM staff_master WHERE profile_status = 'Expired PSA'")->fetchColumn();

    return [
        'vault_total'      => $vaultTotal,
        'payroll_eligible' => $payrollEligible,
        'excluded_status'  => max(0, $vaultTotal - $payrollEligible - $excludedNoEmail),
        'excluded_no_email'=> $excludedNoEmail,
        'incomplete'       => $incomplete,
        'expired_psa'      => $expiredPsa,
    ];
}

/**
 * @return array{
 *   approved_registrations: int,
 *   unique_emails: int,
 *   pending_registrations: int,
 *   unique_pending_emails: int,
 *   total_registrations: int,
 *   unique_all_emails: int
 * }
 */
function apply_main_erp_import_stats(PDO $eventPdo): array
{
    $approvedRegistrations = (int) $eventPdo->query("SELECT COUNT(*) FROM staff_registrations WHERE status = 'approved'")->fetchColumn();
    $uniqueEmails = (int) $eventPdo->query("
        SELECT COUNT(DISTINCT LOWER(TRIM(email)))
        FROM staff_registrations
        WHERE status = 'approved' AND email IS NOT NULL AND TRIM(email) != ''
    ")->fetchColumn();
    $pendingRegistrations = (int) $eventPdo->query("SELECT COUNT(*) FROM staff_registrations WHERE status = 'pending'")->fetchColumn();
    $uniquePendingEmails = (int) $eventPdo->query("
        SELECT COUNT(DISTINCT LOWER(TRIM(email)))
        FROM staff_registrations
        WHERE status = 'pending' AND email IS NOT NULL AND TRIM(email) != ''
    ")->fetchColumn();
    $totalRegistrations = (int) $eventPdo->query('SELECT COUNT(*) FROM staff_registrations')->fetchColumn();
    $uniqueAllEmails = (int) $eventPdo->query("
        SELECT COUNT(DISTINCT LOWER(TRIM(email)))
        FROM staff_registrations
        WHERE email IS NOT NULL AND TRIM(email) != ''
    ")->fetchColumn();

    return [
        'approved_registrations' => $approvedRegistrations,
        'unique_emails'          => $uniqueEmails,
        'pending_registrations'  => $pendingRegistrations,
        'unique_pending_emails'  => $uniquePendingEmails,
        'total_registrations'    => $totalRegistrations,
        'unique_all_emails'      => $uniqueAllEmails,
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function apply_load_vault_rows_by_email(PDO $applyPdo): array
{
    $map  = [];
    $stmt = $applyPdo->query('SELECT * FROM staff_master WHERE email IS NOT NULL AND TRIM(email) != \'\'');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = strtolower(trim((string) ($row['email'] ?? '')));
        if ($key !== '') {
            $map[$key] = $row;
        }
    }

    return $map;
}

function apply_merge_payroll_field(string $preferred, string $fallback): string
{
    $preferred = trim($preferred);

    return $preferred !== '' ? $preferred : trim($fallback);
}

/**
 * @param array<string, mixed> $registration
 * @param array<string, mixed>|null $vault
 * @return list<string>
 */
function apply_registration_to_payroll_row(array $registration, ?array $vault = null): array
{
    $vault = $vault ?? [];

    return [
        apply_merge_payroll_field((string) ($vault['last_name'] ?? ''), (string) ($registration['surname'] ?? '')),
        apply_merge_payroll_field((string) ($vault['first_name'] ?? ''), (string) ($registration['first_name'] ?? '')),
        apply_merge_payroll_field((string) ($vault['address'] ?? ''), (string) ($registration['full_address'] ?? '')),
        apply_merge_payroll_field((string) ($vault['postcode'] ?? ''), (string) ($registration['eircode'] ?? '')),
        (string) ($registration['email'] ?? ''),
        apply_merge_payroll_field((string) ($vault['phone'] ?? ''), (string) ($registration['mobile'] ?? '')),
        apply_merge_payroll_field(
            (string) ($vault['date_of_birth'] ?? ''),
            (string) ($registration['date_of_birth'] ?? '')
        ),
        apply_format_gender_label(
            apply_merge_payroll_field((string) ($vault['gender'] ?? ''), (string) ($registration['gender'] ?? ''))
        ),
        apply_merge_payroll_field((string) ($vault['national_insurance'] ?? ''), (string) ($registration['pps_number'] ?? '')),
        apply_merge_payroll_field((string) ($vault['bank_iban'] ?? ''), (string) ($registration['bank_iban'] ?? '')),
    ];
}

/**
 * Payroll rows from main ERP — one row per approved person (unique email), enriched from apply vault.
 *
 * @return list<list<string>>
 */
function apply_build_payroll_sheet_values_from_main(PDO $eventPdo, PDO $applyPdo): array
{
    $stmt = $eventPdo->query("
        SELECT sr.*
        FROM staff_registrations sr
        INNER JOIN (
            SELECT MAX(id) AS id
            FROM staff_registrations
            WHERE status = 'approved'
              AND email IS NOT NULL
              AND TRIM(email) != ''
            GROUP BY LOWER(TRIM(email))
        ) pick ON pick.id = sr.id
        ORDER BY sr.surname ASC, sr.first_name ASC
    ");

    $vaultByEmail = apply_load_vault_rows_by_email($applyPdo);
    $values       = [apply_payroll_sheet_headers()];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $registration) {
        $emailKey = strtolower(trim((string) ($registration['email'] ?? '')));
        $vault    = $vaultByEmail[$emailKey] ?? null;
        $values[] = apply_registration_to_payroll_row($registration, $vault);
    }

    return $values;
}

/**
 * @return list<list<string>>
 */
function apply_build_payroll_sheet_values(PDO $applyPdo, ?PDO $eventPdo = null): array
{
    if ($eventPdo instanceof PDO) {
        return apply_build_payroll_sheet_values_from_main($eventPdo, $applyPdo);
    }

    $stmt = $applyPdo->query("
        SELECT last_name, first_name, address, postcode, email, phone,
               date_of_birth, gender, national_insurance, bank_iban
        FROM staff_master
        WHERE " . apply_payroll_staff_sql_where() . "
        ORDER BY last_name ASC, first_name ASC
    ");

    $values = [apply_payroll_sheet_headers()];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $staff) {
        $values[] = [
            (string) ($staff['last_name'] ?? ''),
            (string) ($staff['first_name'] ?? ''),
            (string) ($staff['address'] ?? ''),
            (string) ($staff['postcode'] ?? ''),
            (string) ($staff['email'] ?? ''),
            (string) ($staff['phone'] ?? ''),
            (string) ($staff['date_of_birth'] ?? ''),
            apply_format_gender_label((string) ($staff['gender'] ?? '')),
            (string) ($staff['national_insurance'] ?? ''),
            (string) ($staff['bank_iban'] ?? ''),
        ];
    }

    return $values;
}

function apply_count_payroll_from_main(PDO $eventPdo): int
{
    return (int) $eventPdo->query("
        SELECT COUNT(DISTINCT LOWER(TRIM(email)))
        FROM staff_registrations
        WHERE status = 'approved' AND email IS NOT NULL AND TRIM(email) != ''
    ")->fetchColumn();
}

/**
 * Vault staff not on payroll because they are not approved on main ERP (or have no email).
 *
 * @return list<array{id: int, name: string, email: string, profile_status: string, main_status: string}>
 */
function apply_vault_not_on_payroll(PDO $eventPdo, PDO $applyPdo): array
{
    $approvedEmails = [];
    $statusByEmail  = [];

    $stmt = $eventPdo->query("
        SELECT LOWER(TRIM(email)) AS em, status
        FROM staff_registrations
        WHERE email IS NOT NULL AND TRIM(email) != ''
        ORDER BY id DESC
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $em = (string) ($row['em'] ?? '');
        if ($em === '') {
            continue;
        }
        if (!isset($statusByEmail[$em])) {
            $statusByEmail[$em] = (string) ($row['status'] ?? '');
        }
        if (($row['status'] ?? '') === 'approved') {
            $approvedEmails[$em] = true;
        }
    }

    $missing = [];
    $vault   = $applyPdo->query('SELECT id, first_name, last_name, email, profile_status FROM staff_master ORDER BY last_name, first_name');
    foreach ($vault->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $email = trim((string) ($row['email'] ?? ''));
        if ($email === '') {
            $missing[] = [
                'id'             => (int) $row['id'],
                'name'           => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                'email'          => '(no email)',
                'profile_status' => (string) ($row['profile_status'] ?? ''),
                'main_status'    => 'no email on vault record',
            ];
            continue;
        }

        $key = strtolower($email);
        if (!isset($approvedEmails[$key])) {
            $mainStatus = $statusByEmail[$key] ?? 'not on main ERP';
            $missing[]  = [
                'id'             => (int) $row['id'],
                'name'           => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                'email'          => $email,
                'profile_status' => (string) ($row['profile_status'] ?? ''),
                'main_status'    => $mainStatus,
            ];
        }
    }

    return $missing;
}

/**
 * Full master roster from main ERP approved registrations.
 *
 * @return list<list<string>>
 */
function apply_build_master_sheet_values(PDO $eventPdo): array
{
    $stmt = $eventPdo->query("
        SELECT sr.id, sr.surname, sr.first_name, sr.full_address, sr.eircode, sr.email, sr.mobile,
               sr.date_of_birth, sr.gender, sr.pps_number, sr.bank_iban, sr.status, sr.staff_role,
               e.name AS event_name, e.event_date
        FROM staff_registrations sr
        LEFT JOIN events e ON e.id = sr.event_id
        WHERE sr.status = 'approved'
        ORDER BY sr.surname ASC, sr.first_name ASC, sr.id ASC
    ");

    $values = [apply_master_sheet_headers()];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $values[] = [
            (string) ($row['surname'] ?? ''),
            (string) ($row['first_name'] ?? ''),
            (string) ($row['full_address'] ?? ''),
            (string) ($row['eircode'] ?? ''),
            (string) ($row['email'] ?? ''),
            (string) ($row['mobile'] ?? ''),
            (string) ($row['date_of_birth'] ?? ''),
            apply_format_gender_label((string) ($row['gender'] ?? '')),
            (string) ($row['pps_number'] ?? ''),
            (string) ($row['bank_iban'] ?? ''),
            (string) ($row['id'] ?? ''),
            apply_format_status_label((string) ($row['status'] ?? '')),
            apply_format_event_date(isset($row['event_date']) ? (string) $row['event_date'] : null),
            (string) ($row['event_name'] ?? ''),
            apply_format_role_label((string) ($row['staff_role'] ?? '')),
        ];
    }

    return $values;
}

function apply_sanitize_sheet_cell(mixed $value): string
{
    $text = (string) $value;
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        if (is_string($converted) && $converted !== '') {
            $text = $converted;
        }
    }

    return $text;
}

/**
 * @param list<list<string>> $values
 * @return list<list<string>>
 */
function apply_sanitize_sheet_values(array $values): array
{
    return array_map(
        static fn (array $row): array => array_map('apply_sanitize_sheet_cell', $row),
        $values
    );
}

function apply_json_encode_sheet(array $payload): string
{
    $flags = JSON_THROW_ON_ERROR;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    return json_encode($payload, $flags);
}

function apply_google_column_letter(int $zeroBasedIndex): string
{
    $index  = max(0, $zeroBasedIndex);
    $letter = '';
    while ($index >= 0) {
        $letter = chr(ord('A') + ($index % 26)) . $letter;
        $index  = intdiv($index, 26) - 1;
    }

    return $letter !== '' ? $letter : 'A';
}

/** A1 notation with quoted tab name (required for spaces / special chars). */
function apply_google_tab_range(string $sheetName, string $cells): string
{
    $escaped = str_replace("'", "''", $sheetName);

    return "'" . $escaped . "'!" . $cells;
}

/**
 * @return list<string>
 */
function apply_google_sheet_titles(string $accessToken, string $spreadsheetId): array
{
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '?fields=sheets.properties.title';

    $request = curl_init();
    curl_setopt_array($request, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    ]);
    $response = curl_exec($request);
    curl_close($request);

    if ($response === false) {
        return [];
    }

    $data = json_decode((string) $response, true);
    if (!is_array($data['sheets'] ?? null)) {
        return [];
    }

    $titles = [];
    foreach ($data['sheets'] as $sheet) {
        $title = trim((string) ($sheet['properties']['title'] ?? ''));
        if ($title !== '') {
            $titles[] = $title;
        }
    }

    return $titles;
}

function apply_google_find_sheet_title(array $titles, string $wanted): ?string
{
    foreach ($titles as $title) {
        if (strcasecmp($title, $wanted) === 0) {
            return $title;
        }
    }

    return null;
}

function apply_google_create_sheet_tab(string $accessToken, string $spreadsheetId, string $title): bool
{
    $url  = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . ':batchUpdate';
    $body = apply_json_encode_sheet([
        'requests' => [
            ['addSheet' => ['properties' => ['title' => $title]]],
        ],
    ]);

    $request = curl_init();
    curl_setopt_array($request, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => $body,
    ]);
    $response = curl_exec($request);
    $code     = (int) curl_getinfo($request, CURLINFO_HTTP_CODE);
    curl_close($request);

    return $response !== false && $code >= 200 && $code < 300;
}

/**
 * Pick an existing master-roster tab or create "Master Sheet".
 */
function apply_google_resolve_master_tab(string $accessToken, string $spreadsheetId, array $cfg): string
{
    $titles     = apply_google_sheet_titles($accessToken, $spreadsheetId);
    $candidates = array_values(array_unique(array_filter([
        (string) ($cfg['tab_master'] ?? ''),
        (string) ($cfg['tab_master_alt'] ?? ''),
        'Master Sheet',
        'Overall',
        'Master',
        'Roster',
    ])));

    foreach ($candidates as $candidate) {
        $found = apply_google_find_sheet_title($titles, $candidate);
        if ($found !== null) {
            return $found;
        }
    }

    foreach ($titles as $title) {
        if (preg_match('/master|overall|roster/i', $title) === 1) {
            return $title;
        }
    }

    $newTab = (string) ($cfg['tab_master'] ?? 'Master Sheet');
    if ($newTab === '') {
        $newTab = 'Master Sheet';
    }

    if (!in_array($newTab, $titles, true) && apply_google_find_sheet_title($titles, $newTab) === null) {
        apply_google_create_sheet_tab($accessToken, $spreadsheetId, $newTab);
    }

    return $newTab;
}

/**
 * Remove leftover rows from a previous longer export (PUT alone does not delete old rows).
 */
function apply_google_clear_tab_data(string $accessToken, string $spreadsheetId, string $sheetName, int $columnCount = 15): array
{
    $lastCol = apply_google_column_letter(max(0, $columnCount - 1));
    $range   = apply_google_tab_range($sheetName, 'A2:' . $lastCol . '5000');
    $url     = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '/values/' . rawurlencode($range) . ':clear';

    $request = curl_init();
    curl_setopt_array($request, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => '{}',
    ]);

    $response  = curl_exec($request);
    $curlError = curl_error($request);
    curl_close($request);

    if ($response === false) {
        return ['ok' => false, 'error' => $curlError];
    }

    $responseData = json_decode((string) $response, true);
    if (!is_array($responseData) || !isset($responseData['clearedRange'])) {
        return ['ok' => false, 'error' => (string) $response];
    }

    return ['ok' => true, 'error' => ''];
}

/**
 * @param list<list<string>> $values
 * @return array{ok: bool, error: string}
 */
function apply_google_write_tab(string $accessToken, string $spreadsheetId, string $sheetName, array $values, int $columnCount = 15): array
{
    $clearResult = apply_google_clear_tab_data($accessToken, $spreadsheetId, $sheetName, $columnCount);
    // If clear fails (e.g. brand-new tab), still attempt the write.
    if (!$clearResult['ok']) {
        error_log('[ApplySync] clear tab "' . $sheetName . '": ' . $clearResult['error']);
    }

    $range       = apply_google_tab_range($sheetName, 'A1');
    $url         = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '/values/' . rawurlencode($range) . '?valueInputOption=RAW';
    $requestBody = apply_json_encode_sheet([
        'range'          => $range,
        'majorDimension' => 'ROWS',
        'values'         => apply_sanitize_sheet_values($values),
    ]);

    $request = curl_init();
    curl_setopt_array($request, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => $requestBody,
    ]);

    $response  = curl_exec($request);
    $curlError = curl_error($request);
    curl_close($request);

    if ($response === false) {
        return ['ok' => false, 'error' => $curlError];
    }

    $responseData = json_decode($response, true);
    if (!is_array($responseData) || empty($responseData['spreadsheetId'])) {
        return ['ok' => false, 'error' => $response];
    }

    return ['ok' => true, 'error' => ''];
}

/**
 * Push Payroll Staff + Master Sheet (main ERP approved roster).
 *
 * @return array{ok: bool, message: string, rows: int, payroll_rows: int, master_rows: int, stats?: array<string, int|mixed>}
 */
function run_apply_google_sheets_sync(PDO $pdo, ?PDO $eventPdo = null): array
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(180);
    }

    $cfg           = apply_sheet_config();
    $spreadsheetId = (string) $cfg['spreadsheet_id'];
    $jsonPath      = __DIR__ . '/../config/google-service-account.json';

    if (!is_readable($jsonPath)) {
        return [
            'ok'           => false,
            'message'      => 'Google service account JSON is missing on the server.',
            'rows'         => 0,
            'payroll_rows' => 0,
            'master_rows'  => 0,
        ];
    }

    $credentials = json_decode((string) file_get_contents($jsonPath), true);
    if (!is_array($credentials)) {
        return ['ok' => false, 'message' => 'Google service account JSON is invalid.', 'rows' => 0, 'payroll_rows' => 0, 'master_rows' => 0];
    }

    $accessToken = apply_google_access_token($credentials);
    if ($accessToken === '') {
        return ['ok' => false, 'message' => 'Could not obtain Google access token.', 'rows' => 0, 'payroll_rows' => 0, 'master_rows' => 0];
    }

    if (!$eventPdo instanceof PDO) {
        require_once __DIR__ . '/main-admin-bridge.php';
        $eventPdo = getMainAdminPdo();
    }

    $sheetTitles = apply_google_sheet_titles($accessToken, $spreadsheetId);
    $payrollTab  = apply_google_find_sheet_title($sheetTitles, (string) $cfg['tab_payroll'])
        ?? (string) $cfg['tab_payroll'];

    $payrollValues = apply_build_payroll_sheet_values($pdo, $eventPdo instanceof PDO ? $eventPdo : null);
    $payrollResult = apply_google_write_tab(
        $accessToken,
        $spreadsheetId,
        $payrollTab,
        $payrollValues,
        count(apply_payroll_sheet_headers())
    );
    if (!$payrollResult['ok']) {
        return [
            'ok'           => false,
            'message'      => 'Payroll Staff update failed: ' . $payrollResult['error'],
            'rows'         => 0,
            'payroll_rows' => 0,
            'master_rows'  => 0,
        ];
    }

    $payrollRows = max(0, count($payrollValues) - 1);
    $masterRows  = 0;
    $masterNote  = '';

    if ($eventPdo instanceof PDO) {
        $masterValues = apply_build_master_sheet_values($eventPdo);
        $masterRows   = max(0, count($masterValues) - 1);
        $masterTab    = apply_google_resolve_master_tab($accessToken, $spreadsheetId, $cfg);
        $masterResult = apply_google_write_tab(
            $accessToken,
            $spreadsheetId,
            $masterTab,
            $masterValues,
            count(apply_master_sheet_headers())
        );

        if ($masterResult['ok']) {
            $masterNote = $masterTab . ': ' . $masterRows . ' rows';
        } else {
            $masterNote = 'Master sheet failed (' . $masterResult['error'] . ')';
        }
    } else {
        $masterNote = 'Master sheet skipped (main ERP DB not connected)';
    }

    $vaultStats = apply_payroll_sync_stats($pdo);
    $message    = 'Payroll Staff: ' . $payrollRows . ' approved people';

    if ($eventPdo instanceof PDO) {
        $mainStats = apply_main_erp_import_stats($eventPdo);
        $message  .= ' · Main ERP: ' . $mainStats['unique_emails'] . ' approved with email';
        if ($mainStats['unique_pending_emails'] > 0) {
            $message .= ', ' . $mainStats['unique_pending_emails'] . ' still pending approval';
        }
        if ($payrollRows < $mainStats['unique_emails']) {
            $message .= ' (sync mismatch — run import again)';
        }
    }

    $message .= ' · Apply vault: ' . $vaultStats['vault_total'] . ' staff';

    if ($masterNote !== '') {
        $message .= ' · ' . $masterNote;
    }

    return [
        'ok'           => true,
        'message'      => $message,
        'rows'         => $payrollRows,
        'payroll_rows' => $payrollRows,
        'master_rows'  => $masterRows,
        'stats'        => $vaultStats,
    ];
}

/**
 * @param array<string, mixed> $credentials
 */
function apply_google_access_token(array $credentials): string
{
    $encode = static function (string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    };

    $header  = $encode(apply_json_encode_sheet(['alg' => 'RS256', 'typ' => 'JWT']));
    $time    = time();
    $payload = $encode(apply_json_encode_sheet([
        'iss'   => $credentials['client_email'] ?? '',
        'scope' => 'https://www.googleapis.com/auth/spreadsheets',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'exp'   => $time + 3600,
        'iat'   => $time,
    ]));

    $signatureInput = $header . '.' . $payload;
    $privateKey     = (string) ($credentials['private_key'] ?? '');
    if ($privateKey === '') {
        return '';
    }

    openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $jwt = $signatureInput . '.' . $encode($signature);

    $tokenRequest = curl_init();
    curl_setopt_array($tokenRequest, [
        CURLOPT_URL            => 'https://oauth2.googleapis.com/token',
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
    ]);

    $tokenResponse = curl_exec($tokenRequest);
    curl_close($tokenRequest);
    $tokenData = json_decode((string) $tokenResponse, true);

    return is_array($tokenData) ? (string) ($tokenData['access_token'] ?? '') : '';
}
