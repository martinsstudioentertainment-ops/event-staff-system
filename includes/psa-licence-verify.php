<?php

declare(strict_types=1);

require_once __DIR__ . '/financial-field-validation.php';
require_once __DIR__ . '/date-format.php';

/**
 * Official PSA Ireland registered-employees table (wpDataTables, no public API).
 */
function psaRegisterEmployeeSearchUrl(string $licence = ''): string
{
    return 'https://www.psa-gov.ie/psa-registered-employees/';
}

function psaRegisterAjaxUrl(): string
{
    return 'https://www.psa-gov.ie/wp-admin/admin-ajax.php?action=get_wdtable&table_id=5';
}

function psaNormalizePersonName(string $name): string
{
    $name = trim(mb_strtolower($name, 'UTF-8'));
    if ($name === '') {
        return '';
    }

    $map = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c', 'ý' => 'y',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
    ];
    $name = strtr($name, $map);
    $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name;

    return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
}

function psaRegisterNamesMatch(string $submitted, string $onRegister): bool
{
    $a = psaNormalizePersonName($submitted);
    $b = psaNormalizePersonName($onRegister);
    if ($a === '' || $b === '') {
        return false;
    }
    if ($a === $b) {
        return true;
    }

    $ta = array_values(array_filter(
        explode(' ', $a),
        static fn(string $t): bool => strlen($t) >= 2
    ));
    $tb = explode(' ', $b);
    if ($ta === [] || $tb === []) {
        return false;
    }

    $allPresent = true;
    foreach ($ta as $token) {
        if (!in_array($token, $tb, true)) {
            $allPresent = false;
            break;
        }
    }
    if ($allPresent) {
        return true;
    }

    $first = $ta[0];
    $last = $ta[count($ta) - 1];
    $regFirst = $tb[0];
    $regLast = $tb[count($tb) - 1];
    $firstOk = $first === $regFirst || in_array($first, $tb, true);
    $lastOk = $last === $regLast || in_array($last, $tb, true);
    if ($firstOk && $lastOk && count($ta) >= 2) {
        return true;
    }

    similar_text($a, $b, $pct);

    return $pct >= 88.0;
}

function psaParseIrishDateToDb(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $parsed = parseDisplayDateToDb($value);
    if ($parsed !== '') {
        return $parsed;
    }

    $dt = DateTimeImmutable::createFromFormat('d/m/Y', $value);
    if ($dt instanceof DateTimeImmutable) {
        return $dt->format('Y-m-d');
    }

    return '';
}

/**
 * @return array{ok: bool, body: string, status: int}
 */
function psaRegisterHttpRequest(string $method, string $url, string $body = '', string $cookieFile = '', string $referer = ''): array
{
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';
    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,application/json;q=0.8,*/*;q=0.7',
        'Accept-Language: en-IE,en;q=0.9',
    ];
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8';
        $headers[] = 'X-Requested-With: XMLHttpRequest';
    }
    if ($referer !== '') {
        $headers[] = 'Referer: ' . $referer;
        $headers[] = 'Origin: https://www.psa-gov.ie';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'body' => '', 'status' => 0];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => '',
        ]);
        if ($cookieFile !== '') {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $text = is_string($raw) ? $raw : '';

        return ['ok' => $status >= 200 && $status < 300 && $text !== '', 'body' => $text, 'status' => $status];
    }

    $headerStr = implode("\r\n", $headers);
    $context = stream_context_create([
        'http' => [
            'method'        => $method,
            'header'        => $headerStr,
            'content'       => $method === 'POST' ? $body : '',
            'timeout'       => 12,
            'ignore_errors' => true,
            'follow_location' => 1,
            'user_agent'    => $ua,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    $text = is_string($raw) ? $raw : '';

    return ['ok' => $text !== '', 'body' => $text, 'status' => $text !== '' ? 200 : 0];
}

function psaRegisterExtractNonce(string $html): string
{
    if (preg_match('/id=["\']wdtNonceFrontendServerSide_5["\'][^>]*value=["\']([a-f0-9]{8,})["\']/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/name=["\']wdtNonceFrontendServerSide_5["\'][^>]*value=["\']([a-f0-9]{8,})["\']/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/value=["\']([a-f0-9]{8,})["\'][^>]*(?:id|name)=["\']wdtNonceFrontendServerSide_5["\']/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/wdtNonceFrontendServerSide_5["\']?\s*[:=]\s*["\']([a-f0-9]{8,})["\']/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/"wdtNonce"\s*:\s*"([a-f0-9]{8,})"/i', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/wdtNonce=([a-f0-9]{8,})/i', $html, $m)) {
        return $m[1];
    }

    return '';
}

/**
 * Human label for admin / staff after a licence-number check on psa-gov.ie.
 */
function psaRegisterStatusLabel(string $status): string
{
    switch ($status) {
        case 'verified':
            return 'Valid — found on the official PSA register';
        case 'expired':
            return 'Expired — licence number is on the PSA register but the expiry date has passed';
        case 'not_found':
            return 'Not found / fake — this licence number is not on the official PSA register';
        case 'name_mismatch':
            return 'Licence number is on the PSA register, but the name does not match';
        case 'invalid_format':
            return 'Invalid licence number format (expected EM123456/00)';
        case 'unreachable':
            return 'Could not reach the PSA register — will be checked again';
        default:
            return $status !== '' ? $status : 'Not checked yet';
    }
}

function psaRegisterStatusIsAlert(string $status): bool
{
    return in_array($status, ['expired', 'not_found', 'name_mismatch', 'invalid_format'], true);
}

function psaRegisterStatusShortLabel(string $status): string
{
    switch ($status) {
        case 'verified':
            return 'PSA verified';
        case 'expired':
            return 'PSA expired';
        case 'not_found':
            return 'PSA not found / fake';
        case 'name_mismatch':
            return 'PSA name mismatch';
        case 'invalid_format':
            return 'PSA invalid format';
        case 'unreachable':
            return 'PSA check pending';
        default:
            return 'PSA not checked';
    }
}

/** Lower = higher in admin lists (verified first). */
function psaRegisterStatusSortRank(string $status): int
{
    return match (trim($status)) {
        'verified'       => 0,
        'name_mismatch'  => 1,
        'expired'        => 2,
        'not_found'      => 3,
        'invalid_format' => 4,
        'unreachable'    => 5,
        default          => 6,
    };
}

/** SQL ORDER BY expression — verified PSA holders first. */
function psaRegisterStatusOrderSql(string $columnExpr): string
{
    return 'CASE
        WHEN ' . $columnExpr . ' = \'verified\' THEN 0
        WHEN ' . $columnExpr . ' = \'name_mismatch\' THEN 1
        WHEN ' . $columnExpr . ' = \'expired\' THEN 2
        WHEN ' . $columnExpr . ' = \'not_found\' THEN 3
        WHEN ' . $columnExpr . ' = \'invalid_format\' THEN 4
        WHEN ' . $columnExpr . ' = \'unreachable\' THEN 5
        ELSE 6
    END';
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function sortStaffRowsByPsaVerifiedFirst(array $rows, string $secondaryKey = 'created_at'): array
{
    usort($rows, static function (array $a, array $b) use ($secondaryKey): int {
        $rank = psaRegisterStatusSortRank((string) ($a['psa_register_status'] ?? ''))
            <=> psaRegisterStatusSortRank((string) ($b['psa_register_status'] ?? ''));
        if ($rank !== 0) {
            return $rank;
        }

        return strcmp((string) ($b[$secondaryKey] ?? ''), (string) ($a[$secondaryKey] ?? ''));
    });

    return array_values($rows);
}

function psaRegisterStatusCompactLabel(string $status): string
{
    switch ($status) {
        case 'verified':
            return 'PSA OK';
        case 'expired':
            return 'Expired';
        case 'not_found':
            return 'Fake';
        case 'name_mismatch':
            return 'Mismatch';
        case 'invalid_format':
            return 'Invalid';
        case 'unreachable':
            return 'Pending';
        default:
            return 'No PSA';
    }
}

function psaRegisterStatusBadgeClass(string $status): string
{
    switch ($status) {
        case 'verified':
            return 'badge--approved';
        case 'expired':
        case 'not_found':
        case 'name_mismatch':
        case 'invalid_format':
            return 'badge--rejected';
        default:
            return 'badge--pending';
    }
}

function psaRegisterStatusBadgeHtml(string $status, bool $compact = false): string
{
    $status = trim($status);
    $class = psaRegisterStatusBadgeClass($status);
    $label = $compact ? psaRegisterStatusCompactLabel($status) : psaRegisterStatusShortLabel($status);

    return '<span class="badge ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '</span>';
}

/**
 * Look up a stored licence, save the register result, and email admin on fake/expired/invalid.
 *
 * @return array<string, mixed>
 */
function applyPsaRegisterCheckToStaff(PDO $pdo, int $staffId, string $licence, ?string $holderName = null, bool $notifyAdmin = true): array
{
    require_once __DIR__ . '/staff-repository.php';

    $previousStatus = '';
    $staff = $staffId > 0 ? getStaffById($pdo, $staffId) : null;
    if ($staff !== null) {
        $previousStatus = trim((string) ($staff['psa_register_status'] ?? ''));
        if ($holderName === null || trim($holderName) === '') {
            $holderName = trim((string) ($staff['first_name'] ?? '') . ' ' . (string) ($staff['surname'] ?? ''));
        }
        if ($licence === '') {
            $licence = (string) ($staff['psa_licence'] ?? '');
        }
    }

    $verify = lookupPsaLicenceOnRegister($licence, $holderName !== '' ? $holderName : null);
    $status = trim((string) ($verify['status'] ?? ''));
    $fields = [
        'psa_licence' => normalizePsaLicence($licence),
        'psa_register_status' => $status,
        'psa_register_name' => (string) ($verify['register_name'] ?? ''),
        'psa_register_checked_at' => date('Y-m-d H:i:s'),
    ];
    $expiry = trim((string) ($verify['expiry_date'] ?? ''));
    if ($expiry !== '') {
        $fields['psa_expiry_date'] = $expiry;
    }
    // Persist full official row so Staff Sign can show County/Sectors/Issue without a second live call.
    $employee = is_array($verify['employee'] ?? null) ? $verify['employee'] : [];
    $snapshot = [
        'licence_no'    => (string) ($employee['licence_no'] ?? $fields['psa_licence']),
        'employee_name' => (string) ($employee['employee_name'] ?? ($verify['register_name'] ?? '')),
        'county'        => (string) ($employee['county'] ?? ($verify['county'] ?? '')),
        'country'       => (string) ($employee['country'] ?? ($verify['country'] ?? '')),
        'sectors'       => (string) ($employee['sectors'] ?? ($verify['sectors'] ?? '')),
        'issue_date'    => (string) ($employee['issue_date'] ?? ($verify['issue_display'] ?? '')),
        'expiry_date'   => (string) ($employee['expiry_date'] ?? ($verify['expiry_display'] ?? '')),
        'title'         => (string) ($employee['title'] ?? ''),
        'status'        => $status,
        'checked_at'    => date('c'),
    ];
    $fields['psa_register_snapshot'] = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($staffId > 0) {
        try {
            updateStaffProfile($pdo, $staffId, $fields);
        } catch (Throwable $e) {
            // Older DBs without snapshot column — save core fields only.
            unset($fields['psa_register_snapshot']);
            updateStaffProfile($pdo, $staffId, $fields);
        }
        $staff = getStaffById($pdo, $staffId) ?? $staff;
    }

    if ($notifyAdmin && $staff !== null) {
        require_once __DIR__ . '/notification-center.php';
        notifyAdminPsaLicenceProblemIfNeeded($pdo, $staff, $verify, $previousStatus);
    }

    return $verify;
}

function psaRegisterBuildSearchBody(string $nonce, string $licenceSearch, string $nameSearch = ''): string
{
    $params = [
        'draw' => '1',
        'start' => '0',
        'length' => '10',
        'search[value]' => '',
        'search[regex]' => 'false',
        'order[0][column]' => '0',
        'order[0][dir]' => 'asc',
        'wdtNonce' => $nonce,
        'sRangeSeparator' => '|',
    ];

    $columns = [
        0 => 'Title',
        1 => 'wpcf-license-number',
        2 => 'wpcf-employee-name',
        3 => 'wpcf-county-of-residence',
        4 => 'wpcf-country-of-residence',
        5 => 'wpcf-sectors-covered',
        6 => 'wpcf-employee-issue-date',
        7 => 'wpcf-employee-expiry-date',
        8 => 'last_updated',
        9 => 'masterdetail',
    ];

    foreach ($columns as $i => $name) {
        $search = '';
        if ($i === 1) {
            $search = $licenceSearch;
        } elseif ($i === 2) {
            $search = $nameSearch;
        }
        $params["columns[{$i}][data]"] = (string) $i;
        $params["columns[{$i}][name]"] = $name;
        $params["columns[{$i}][searchable]"] = $i === 9 ? 'false' : 'true';
        $params["columns[{$i}][orderable]"] = $i === 9 ? 'false' : 'true';
        $params["columns[{$i}][search][value]"] = $search;
        $params["columns[{$i}][search][regex]"] = 'false';
    }

    return http_build_query($params);
}

/**
 * @return array<string, mixed>|null
 */
function psaRegisterParseEmployeeRow(array $row, string $licence): ?array
{
    if (count($row) < 8) {
        return null;
    }

    $foundLicence = normalizePsaLicence(strip_tags((string) ($row[1] ?? $row[0] ?? '')));
    if ($foundLicence === '' || strcasecmp($foundLicence, $licence) !== 0) {
        return null;
    }

    $title = trim(html_entity_decode(strip_tags((string) ($row[0] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    return [
        'licence'     => $foundLicence,
        'title'       => $title !== '' ? $title : $foundLicence,
        'name'        => trim(html_entity_decode(strip_tags((string) ($row[2] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        'county'      => trim(strip_tags((string) ($row[3] ?? ''))),
        'country'     => trim(strip_tags((string) ($row[4] ?? ''))),
        'sectors'     => trim(strip_tags((string) ($row[5] ?? ''))),
        'issue_date'  => psaParseIrishDateToDb((string) ($row[6] ?? '')),
        'expiry_date' => psaParseIrishDateToDb((string) ($row[7] ?? '')),
        'issue_raw'   => trim(strip_tags((string) ($row[6] ?? ''))),
        'expiry_raw'  => trim(strip_tags((string) ($row[7] ?? ''))),
    ];
}

function psaRegisterIrishDisplayDate(string $raw, string $dbDate): string
{
    $raw = trim($raw);
    if ($raw !== '') {
        return $raw;
    }

    $dbDate = trim($dbDate);
    if ($dbDate === '') {
        return '';
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $dbDate);

    return $dt instanceof DateTimeImmutable ? $dt->format('d/m/Y') : $dbDate;
}

/**
 * Labels match the official PSA “Employee Details” popup.
 *
 * @param array<string, mixed> $match
 * @return array{
 *   title: string,
 *   licence_no: string,
 *   employee_name: string,
 *   county: string,
 *   country: string,
 *   sectors: string,
 *   issue_date: string,
 *   expiry_date: string
 * }
 */
function psaRegisterEmployeeDetails(array $match): array
{
    $licence = trim((string) ($match['licence'] ?? ''));
    $title = trim((string) ($match['title'] ?? ''));

    return [
        'title'         => $title !== '' ? $title : $licence,
        'licence_no'    => $licence,
        'employee_name' => trim((string) ($match['name'] ?? '')),
        'county'        => trim((string) ($match['county'] ?? '')),
        'country'       => trim((string) ($match['country'] ?? '')),
        'sectors'       => trim((string) ($match['sectors'] ?? '')),
        'issue_date'    => psaRegisterIrishDisplayDate(
            (string) ($match['issue_raw'] ?? ''),
            (string) ($match['issue_date'] ?? '')
        ),
        'expiry_date'   => psaRegisterIrishDisplayDate(
            (string) ($match['expiry_raw'] ?? ''),
            (string) ($match['expiry_date'] ?? '')
        ),
    ];
}

/**
 * Look up a PSA employee licence on psa-gov.ie.
 *
 * @return array{
 *   ok: bool,
 *   format_ok: bool,
 *   register_checked: bool,
 *   register_ok: bool,
 *   name_match: bool,
 *   status: string,
 *   message: string,
 *   register_url: string,
 *   licence: string,
 *   register_name: string,
 *   expiry_date: string,
 *   issue_date: string,
 *   sectors: string,
 *   title: string,
 *   county: string,
 *   country: string,
 *   issue_display: string,
 *   expiry_display: string,
 *   employee: array<string, string>|null
 * }
 */
function lookupPsaLicenceOnRegister(string $licence, ?string $holderName = null): array
{
    $licence = normalizePsaLicence($licence);
    $holderName = trim((string) $holderName);
    $registerUrl = psaRegisterEmployeeSearchUrl($licence);
    $empty = [
        'ok'               => false,
        'format_ok'        => false,
        'register_checked' => false,
        'register_ok'      => false,
        'name_match'       => false,
        'status'           => 'invalid_format',
        'message'          => '',
        'register_url'     => $registerUrl,
        'licence'          => $licence,
        'register_name'    => '',
        'expiry_date'      => '',
        'issue_date'       => '',
        'sectors'          => '',
        'title'            => '',
        'county'           => '',
        'country'          => '',
        'issue_display'    => '',
        'expiry_display'   => '',
        'employee'         => null,
    ];

    if ($licence === '') {
        $empty['message'] = 'Enter your PSA licence number.';

        return $empty;
    }

    if (!isValidPsaLicenceFormat($licence)) {
        $empty['message'] = 'Licence must match PSA format EM123456/00.';

        return $empty;
    }

    $base = [
        'ok'               => true,
        'format_ok'        => true,
        'register_checked' => false,
        'register_ok'      => false,
        'name_match'       => false,
        'status'           => 'unreachable',
        'message'          => 'Licence format is valid. Could not reach the PSA register just now — it will be checked again.',
        'register_url'     => $registerUrl,
        'licence'          => $licence,
        'register_name'    => '',
        'expiry_date'      => '',
        'issue_date'       => '',
        'sectors'          => '',
        'title'            => '',
        'county'           => '',
        'country'          => '',
        'issue_display'    => '',
        'expiry_display'   => '',
        'employee'         => null,
    ];

    $cookieFile = tempnam(sys_get_temp_dir(), 'psa_ck_');
    if (!is_string($cookieFile) || $cookieFile === '') {
        return $base;
    }

    try {
        $page = psaRegisterHttpRequest('GET', $registerUrl, '', $cookieFile);
        if (!$page['ok'] || $page['body'] === '' || preg_match('/just a moment|cf-error|attention required/i', $page['body'])) {
            return $base;
        }

        $nonce = psaRegisterExtractNonce($page['body']);
        if ($nonce === '') {
            return $base;
        }

        $ajax = psaRegisterHttpRequest(
            'POST',
            psaRegisterAjaxUrl(),
            psaRegisterBuildSearchBody($nonce, $licence),
            $cookieFile,
            $registerUrl
        );
        if (!$ajax['ok']) {
            return $base;
        }

        $json = json_decode($ajax['body'], true);
        if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
            return $base;
        }

        $match = null;
        foreach ($json['data'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parsed = psaRegisterParseEmployeeRow($row, $licence);
            if ($parsed !== null) {
                $match = $parsed;
                break;
            }
        }

        $base['register_checked'] = true;

        if ($match === null) {
            $base['ok'] = false;
            $base['status'] = 'not_found';
            $base['message'] = 'This licence number is not on the official PSA registered employees list.';

            return $base;
        }

        $details = psaRegisterEmployeeDetails($match);
        $base['register_ok'] = true;
        $base['register_name'] = (string) $match['name'];
        $base['expiry_date'] = (string) $match['expiry_date'];
        $base['issue_date'] = (string) $match['issue_date'];
        $base['sectors'] = (string) $match['sectors'];
        $base['title'] = $details['title'];
        $base['county'] = $details['county'];
        $base['country'] = $details['country'];
        $base['issue_display'] = $details['issue_date'];
        $base['expiry_display'] = $details['expiry_date'];
        $base['employee'] = $details;

        $expired = $base['expiry_date'] !== '' && $base['expiry_date'] < date('Y-m-d');
        $nameMatch = $holderName === '' || psaRegisterNamesMatch($holderName, $base['register_name']);
        $base['name_match'] = $nameMatch;

        if (!$nameMatch) {
            $base['ok'] = false;
            $base['status'] = 'name_mismatch';
            $base['message'] = 'Licence ' . $licence . ' is on the PSA register as "' . $base['register_name']
                . '", which does not match the submitted name.';

            return $base;
        }

        if ($expired) {
            $base['ok'] = false;
            $base['status'] = 'expired';
            $base['message'] = 'Licence ' . $licence . ' matches ' . $base['register_name']
                . ' on the PSA register, but it expired on ' . ($match['expiry_raw'] ?: $base['expiry_date']) . '.';

            return $base;
        }

        $base['status'] = 'verified';
        $expiryNote = $match['expiry_raw'] !== '' ? ' Expires ' . $match['expiry_raw'] . '.' : '';
        $base['message'] = 'Verified on the official PSA register: ' . $base['register_name'] . '.' . $expiryNote;

        return $base;
    } finally {
        if (is_file($cookieFile)) {
            @unlink($cookieFile);
        }
    }
}

/**
 * @return array{ok: bool, format_ok: bool, message: string, register_url: string, licence: string}
 */
function verifyPsaLicenceBasic(string $licence, ?string $holderName = null): array
{
    $result = lookupPsaLicenceOnRegister($licence, $holderName);

    return $result;
}
