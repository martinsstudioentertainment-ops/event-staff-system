<?php

declare(strict_types=1);

/**
 * Mobile numbers stored in E.164 (+country + national digits).
 */

function defaultPhoneCountryIso(): string
{
    return 'IE';
}

/**
 * @return array<string, string> ISO 3166-1 alpha-2 => dial code (with +)
 */
function phoneCountryDialCodes(): array
{
    return [
        'IE' => '+353',
        'GB' => '+44',
        'PL' => '+48',
        'RO' => '+40',
        'LT' => '+370',
        'LV' => '+371',
        'EE' => '+372',
        'HU' => '+36',
        'SK' => '+421',
        'CZ' => '+420',
        'DE' => '+49',
        'FR' => '+33',
        'ES' => '+34',
        'IT' => '+39',
        'PT' => '+351',
        'NL' => '+31',
        'BE' => '+32',
        'SE' => '+46',
        'NO' => '+47',
        'DK' => '+45',
        'FI' => '+358',
        'UA' => '+380',
        'BG' => '+359',
        'HR' => '+385',
        'GR' => '+30',
        'AT' => '+43',
        'CH' => '+41',
        'US' => '+1',
        'CA' => '+1',
        'AU' => '+61',
        'NZ' => '+64',
        'IN' => '+91',
        'PH' => '+63',
        'BR' => '+55',
        'ZA' => '+27',
        'NG' => '+234',
        'PK' => '+92',
        'BD' => '+880',
        'CN' => '+86',
        'JP' => '+81',
        'KR' => '+82',
        'TR' => '+90',
        'SA' => '+966',
        'AE' => '+971',
        'MX' => '+52',
        'AR' => '+54',
        'CO' => '+57',
        'CL' => '+56',
        'PE' => '+51',
        'RU' => '+7',
        'IL' => '+972',
        'EG' => '+20',
        'MA' => '+212',
        'SN' => '+221',
        'GH' => '+233',
        'KE' => '+254',
        'ZW' => '+263',
        'MT' => '+356',
        'CY' => '+357',
        'LU' => '+352',
        'IS' => '+354',
        'SI' => '+386',
        'RS' => '+381',
        'BA' => '+387',
        'MK' => '+389',
        'AL' => '+355',
        'MD' => '+373',
        'BY' => '+375',
        'GE' => '+995',
        'AM' => '+374',
        'AZ' => '+994',
        'KZ' => '+7',
        'UZ' => '+998',
        'TH' => '+66',
        'VN' => '+84',
        'MY' => '+60',
        'SG' => '+65',
        'ID' => '+62',
        'HK' => '+852',
        'TW' => '+886',
    ];
}

/**
 * @return array<string, string> ISO => display label
 */
function phoneCountryLabels(): array
{
    return [
        'IE' => 'Ireland',
        'GB' => 'United Kingdom',
        'PL' => 'Poland',
        'RO' => 'Romania',
        'LT' => 'Lithuania',
        'LV' => 'Latvia',
        'EE' => 'Estonia',
        'HU' => 'Hungary',
        'SK' => 'Slovakia',
        'CZ' => 'Czechia',
        'DE' => 'Germany',
        'FR' => 'France',
        'ES' => 'Spain',
        'IT' => 'Italy',
        'PT' => 'Portugal',
        'NL' => 'Netherlands',
        'BE' => 'Belgium',
        'SE' => 'Sweden',
        'NO' => 'Norway',
        'DK' => 'Denmark',
        'FI' => 'Finland',
        'UA' => 'Ukraine',
        'BG' => 'Bulgaria',
        'HR' => 'Croatia',
        'GR' => 'Greece',
        'AT' => 'Austria',
        'CH' => 'Switzerland',
        'US' => 'United States',
        'CA' => 'Canada',
        'AU' => 'Australia',
        'NZ' => 'New Zealand',
        'IN' => 'India',
        'PH' => 'Philippines',
        'BR' => 'Brazil',
        'ZA' => 'South Africa',
        'NG' => 'Nigeria',
        'PK' => 'Pakistan',
        'BD' => 'Bangladesh',
        'CN' => 'China',
        'JP' => 'Japan',
        'KR' => 'South Korea',
        'TR' => 'Turkey',
        'SA' => 'Saudi Arabia',
        'AE' => 'UAE',
        'MX' => 'Mexico',
        'AR' => 'Argentina',
        'CO' => 'Colombia',
        'CL' => 'Chile',
        'PE' => 'Peru',
        'RU' => 'Russia',
        'IL' => 'Israel',
        'EG' => 'Egypt',
        'MA' => 'Morocco',
        'SN' => 'Senegal',
        'GH' => 'Ghana',
        'KE' => 'Kenya',
        'ZW' => 'Zimbabwe',
        'MT' => 'Malta',
        'CY' => 'Cyprus',
        'LU' => 'Luxembourg',
        'IS' => 'Iceland',
        'SI' => 'Slovenia',
        'RS' => 'Serbia',
        'BA' => 'Bosnia',
        'MK' => 'North Macedonia',
        'AL' => 'Albania',
        'MD' => 'Moldova',
        'BY' => 'Belarus',
        'GE' => 'Georgia',
        'AM' => 'Armenia',
        'AZ' => 'Azerbaijan',
        'KZ' => 'Kazakhstan',
        'UZ' => 'Uzbekistan',
        'TH' => 'Thailand',
        'VN' => 'Vietnam',
        'MY' => 'Malaysia',
        'SG' => 'Singapore',
        'ID' => 'Indonesia',
        'HK' => 'Hong Kong',
        'TW' => 'Taiwan',
    ];
}

function phoneDialCodeForIso(string $iso): string
{
    $iso   = strtoupper(trim($iso));
    $codes = phoneCountryDialCodes();

    return $codes[$iso] ?? $codes[defaultPhoneCountryIso()];
}

function isKnownPhoneCountryIso(string $iso): bool
{
    $iso = strtoupper(trim($iso));

    return isset(phoneCountryDialCodes()[$iso]);
}

/**
 * @return array{iso: string, dial: string, national: string, e164: string}
 */
function splitMobileNumber(string $stored, ?string $fallbackIso = null): array
{
    $fallbackIso = strtoupper(trim((string) $fallbackIso));
    if ($fallbackIso === '' || !isKnownPhoneCountryIso($fallbackIso)) {
        $fallbackIso = defaultPhoneCountryIso();
    }

    $stored = trim($stored);
    if ($stored === '') {
        return [
            'iso'      => $fallbackIso,
            'dial'     => phoneDialCodeForIso($fallbackIso),
            'national' => '',
            'e164'     => '',
        ];
    }

    $e164   = normalizeMobileNumber($stored, $fallbackIso);
    $digits = ltrim($e164, '+');

    $matches = [];
    foreach (phoneCountryDialCodes() as $iso => $dial) {
        $dialDigits = ltrim($dial, '+');
        if ($dialDigits !== '' && str_starts_with($digits, $dialDigits)) {
            $matches[] = ['iso' => $iso, 'dial' => $dial, 'len' => strlen($dialDigits)];
        }
    }

    usort($matches, static fn(array $a, array $b): int => $b['len'] <=> $a['len']);

    if ($matches !== []) {
        $match    = $matches[0];
        $national = substr($digits, $match['len']);

        return [
            'iso'      => $match['iso'],
            'dial'     => $match['dial'],
            'national' => $national,
            'e164'     => $e164,
        ];
    }

    return [
        'iso'      => $fallbackIso,
        'dial'     => phoneDialCodeForIso($fallbackIso),
        'national' => preg_replace('/\D+/', '', $stored) ?? '',
        'e164'     => $e164,
    ];
}

function normalizeMobileNumber(string $raw, ?string $countryIso = null): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $countryIso = strtoupper(trim((string) $countryIso));
    if ($countryIso === '' || !isKnownPhoneCountryIso($countryIso)) {
        $countryIso = defaultPhoneCountryIso();
    }

    if (str_starts_with($raw, '+')) {
        $digits = preg_replace('/\D+/', '', substr($raw, 1)) ?? '';

        return $digits !== '' ? '+' . $digits : '';
    }

    $digitsOnly = preg_replace('/\D+/', '', $raw) ?? '';
    if ($digitsOnly === '') {
        return '';
    }

    $dialDigits = ltrim(phoneDialCodeForIso($countryIso), '+');
    if (
        $dialDigits !== ''
        && str_starts_with($digitsOnly, $dialDigits)
        && strlen($digitsOnly) > strlen($dialDigits) + 5
    ) {
        return '+' . $digitsOnly;
    }

    if (str_starts_with($digitsOnly, '00')) {
        $digitsOnly = substr($digitsOnly, 2);
        if ($digitsOnly !== '') {
            return '+' . $digitsOnly;
        }
    }

    if (str_starts_with($digitsOnly, '0')) {
        $digitsOnly = substr($digitsOnly, 1);
    }

    if ($digitsOnly === '') {
        return '';
    }

    return phoneDialCodeForIso($countryIso) . $digitsOnly;
}

function validateMobileNumber(string $mobile): ?string
{
    $mobile = trim($mobile);
    if ($mobile === '') {
        return 'Mobile number is required.';
    }

    if (!preg_match('/^\+[1-9]\d{6,14}$/', $mobile)) {
        return 'Please enter a valid mobile number with country code (e.g. +353 87 123 4567).';
    }

    return null;
}

/**
 * @param array<string, mixed> $post
 */
function normalizeMobileFromPost(array $post): string
{
    $national = trim((string) ($post['mobile_national'] ?? ''));
    if ($national === '') {
        $national = trim((string) ($post['phone_national'] ?? ''));
    }
    $iso = strtoupper(trim((string) ($post['phone_country'] ?? '')));
    $raw = trim((string) ($post['mobile'] ?? ''));
    if ($raw === '') {
        $raw = trim((string) ($post['phone'] ?? ''));
    }

    if ($national !== '') {
        return normalizeMobileNumber($national, $iso !== '' ? $iso : null);
    }

    if ($raw !== '') {
        return normalizeMobileNumber($raw, $iso !== '' ? $iso : null);
    }

    return '';
}

/**
 * @param array<string, mixed> $data
 */
function prepareMobileFromRequest(array &$data): void
{
    $normalized     = normalizeMobileFromPost($data);
    $data['mobile'] = $normalized;
    if (array_key_exists('phone', $data) || ($data['phone_national'] ?? '') !== '') {
        $data['phone'] = $normalized;
    }
}

/**
 * @param array<string, string> $countryNameToIso
 */
function countryNameToIso(string $countryName): string
{
    static $map = [
        'Ireland'            => 'IE',
        'United Kingdom'     => 'GB',
        'Poland'             => 'PL',
        'Romania'            => 'RO',
        'Lithuania'          => 'LT',
        'Latvia'             => 'LV',
        'Estonia'            => 'EE',
        'Hungary'            => 'HU',
        'Slovakia'           => 'SK',
        'Czechia'            => 'CZ',
        'Czech Republic'     => 'CZ',
        'Germany'            => 'DE',
        'France'             => 'FR',
        'Spain'              => 'ES',
        'Italy'              => 'IT',
        'Portugal'           => 'PT',
        'Netherlands'        => 'NL',
        'Belgium'            => 'BE',
        'Sweden'             => 'SE',
        'Norway'             => 'NO',
        'Denmark'            => 'DK',
        'Finland'            => 'FI',
        'Ukraine'            => 'UA',
        'United States'      => 'US',
        'India'              => 'IN',
        'Philippines'        => 'PH',
        'Brazil'             => 'BR',
        'Nigeria'            => 'NG',
        'Pakistan'           => 'PK',
        'South Africa'       => 'ZA',
    ];

    return $map[trim($countryName)] ?? '';
}

function resolvePhoneCountryIsoFromRequest(?PDO $pdo = null): string
{
    $cf = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
    if (preg_match('/^[A-Z]{2}$/', $cf) && $cf !== 'XX' && $cf !== 'T1' && isKnownPhoneCountryIso($cf)) {
        return $cf;
    }

    $ip = '';
    $tracking = __DIR__ . '/website-visitor-tracking.php';
    if (is_readable($tracking)) {
        require_once $tracking;
        $ip = getClientIpAddress();
        if ($pdo instanceof PDO && $ip !== '') {
            $geo = lookupIpGeo($pdo, $ip);
            $iso = countryNameToIso((string) ($geo['country'] ?? ''));
            if ($iso !== '' && isKnownPhoneCountryIso($iso)) {
                return $iso;
            }
        }
    }

    if ($ip === '' && isset($_SERVER['REMOTE_ADDR'])) {
        $ip = trim((string) $_SERVER['REMOTE_ADDR']);
    }
    if ($ip === '') {
        return defaultPhoneCountryIso();
    }

    $url  = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,countryCode';
    $ctx  = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    $json = @file_get_contents($url, false, $ctx);
    if (is_string($json) && $json !== '') {
        $data = json_decode($json, true);
        if (is_array($data) && ($data['status'] ?? '') === 'success') {
            $iso = strtoupper(trim((string) ($data['countryCode'] ?? '')));
            if (isKnownPhoneCountryIso($iso)) {
                return $iso;
            }
        }
    }

    return defaultPhoneCountryIso();
}
