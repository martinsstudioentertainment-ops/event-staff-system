<?php

/**
 * PSA licence (EM000000/00) and IBAN validation — used on all staff-facing forms.
 */

/** @return array<string, int> ISO country => IBAN length */
function getIbanCountryLengths(): array
{
    return [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28,
        'BA' => 20, 'BE' => 16, 'BG' => 22, 'BH' => 22, 'BR' => 29,
        'BY' => 28, 'CH' => 21, 'CR' => 22, 'CY' => 28, 'CZ' => 24,
        'DE' => 22, 'DK' => 18, 'DO' => 28, 'EE' => 20, 'EG' => 29,
        'ES' => 24, 'FI' => 18, 'FO' => 18, 'FR' => 27, 'GB' => 22,
        'GE' => 22, 'GI' => 23, 'GL' => 18, 'GR' => 27, 'GT' => 28,
        'HR' => 21, 'HU' => 28, 'IE' => 22, 'IL' => 23, 'IS' => 26,
        'IT' => 27, 'JO' => 30, 'KW' => 30, 'KZ' => 20, 'LB' => 28,
        'LC' => 32, 'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21,
        'MC' => 27, 'MD' => 24, 'ME' => 22, 'MK' => 19, 'MR' => 27,
        'MT' => 31, 'MU' => 30, 'NL' => 18, 'NO' => 15, 'PK' => 24,
        'PL' => 28, 'PS' => 29, 'PT' => 25, 'QA' => 29, 'RO' => 24,
        'RS' => 22, 'SA' => 24, 'SE' => 24, 'SI' => 19, 'SK' => 24,
        'SM' => 27, 'TN' => 24, 'TR' => 26, 'UA' => 29, 'VG' => 24,
        'XK' => 20,
    ];
}

function normalizePsaLicence(string $value): string
{
    return strtoupper(preg_replace('/\s+/', '', trim($value)));
}

function normalizeBankIban(string $value): string
{
    return strtoupper(preg_replace('/\s+/', '', trim($value)));
}

function isValidPsaLicenceFormat(string $value): bool
{
    $psa = normalizePsaLicence($value);

    return (bool) preg_match('/^EM\d{6}\/\d{2}$/', $psa);
}

function ibanMod97(string $iban): int
{
    $iban       = normalizeBankIban($iban);
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $numeric    = '';

    foreach (str_split($rearranged) as $char) {
        $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
    }

    $remainder = 0;
    foreach (str_split($numeric, 7) as $chunk) {
        $remainder = (int) (($remainder . $chunk) % 97);
    }

    return $remainder;
}

function isValidBankIban(string $value): bool
{
    $iban = normalizeBankIban($value);
    if ($iban === '') {
        return false;
    }

    if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $iban)) {
        return false;
    }

    $len = strlen($iban);
    if ($len < 15 || $len > 34) {
        return false;
    }

    $country = substr($iban, 0, 2);
    $lengths = getIbanCountryLengths();
    if (isset($lengths[$country]) && $len !== $lengths[$country]) {
        return false;
    }

    // Reject bank names / free text (too many consecutive letters in account part).
    $bban = substr($iban, 4);
    if (preg_match('/[A-Z]{5,}/', $bban)) {
        return false;
    }

    return ibanMod97($iban) === 1;
}

function validatePsaLicenceField(string $value, bool $required = true): ?string
{
    $value = trim($value);
    if ($value === '') {
        return $required ? 'PSA licence number is required.' : null;
    }

    if (!isValidPsaLicenceFormat($value)) {
        return 'PSA licence must be format EM123456/00 (EM, 6 digits, /, 2 digits).';
    }

    return null;
}

function validateBankIbanField(string $value, bool $required = true): ?string
{
    $value = trim($value);
    if ($value === '') {
        return $required ? 'Bank IBAN is required.' : null;
    }

    if (!isValidBankIban($value)) {
        return 'Enter a valid IBAN with country code (e.g. IE29AIBK93115212345678). Do not enter a bank name.';
    }

    return null;
}

/**
 * @return array<string, string>
 */
function validateFinancialStaffFields(array $data, bool $required = true): array
{
    $errors = [];

    $ibanErr = validateBankIbanField((string) ($data['bank_iban'] ?? ''), $required);
    if ($ibanErr !== null) {
        $errors['bank_iban'] = $ibanErr;
    }

    $psaErr = validatePsaLicenceField((string) ($data['psa_licence'] ?? ''), $required);
    if ($psaErr !== null) {
        $errors['psa_licence'] = $psaErr;
    }

    return $errors;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function normalizeFinancialStaffFields(array $data): array
{
    if (array_key_exists('bank_iban', $data) && trim((string) $data['bank_iban']) !== '') {
        $data['bank_iban'] = normalizeBankIban((string) $data['bank_iban']);
    }
    if (array_key_exists('psa_licence', $data) && trim((string) $data['psa_licence']) !== '') {
        $data['psa_licence'] = normalizePsaLicence((string) $data['psa_licence']);
    }

    return $data;
}
