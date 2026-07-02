<?php

declare(strict_types=1);

/**
 * Irish / EU display dates (DD/MM/YYYY) — DB stays Y-m-d.
 */

// On the Apply host, the main ERP may already have loaded /includes/date-format.php.
// These functions live in the global namespace, so we must avoid redeclaring them.
if (!function_exists('getDisplayDateFormatOptions')) {
    if (!defined('IRISH_DATE_FORMAT')) {
        define('IRISH_DATE_FORMAT', 'd/m/Y');
    }
    if (!defined('GOOGLE_SHEETS_DATE_PATTERN')) {
        /** Google Sheets NUMBER_FORMAT pattern for date cells. */
        define('GOOGLE_SHEETS_DATE_PATTERN', 'dd/mm/yyyy');
    }

    /** @return array<string, string> */
    function getDisplayDateFormatOptions(): array
    {
        return [
            'd/m/Y' => 'Irish — DD/MM/YYYY (31/12/2026)',
            'd.m.Y' => 'DD.MM.YYYY (31.12.2026)',
            'Y-m-d' => 'ISO — YYYY-MM-DD (2026-12-31)',
            'm/d/Y' => 'US — MM/DD/YYYY (12/31/2026)',
        ];
    }

    function normalizeDisplayDateFormat(string $value): string
    {
        return array_key_exists($value, getDisplayDateFormatOptions()) ? $value : IRISH_DATE_FORMAT;
    }

    function getDisplayDateFormat(?PDO $pdo = null): string
    {
        require_once __DIR__ . '/main-admin-bridge.php';

        if ($pdo === null) {
            $pdo = getMainAdminPdo();
        }

        if ($pdo instanceof PDO) {
            $fromDb = apply_read_main_setting($pdo, 'system_date_format', '');

            return normalizeDisplayDateFormat($fromDb !== '' ? $fromDb : IRISH_DATE_FORMAT);
        }

        return IRISH_DATE_FORMAT;
    }

function isEmptyDbDate(?string $value): bool
{
    $value = trim((string) $value);

    return $value === '' || $value === '0000-00-00' || str_starts_with($value, '0000-00-00');
}

/**
 * Format a DB or parseable date for screens, CSV, and Google Sheets (default Irish DD/MM/YYYY).
 */
function formatDisplayDate(?string $date, ?PDO $pdo = null): string
{
    if (isEmptyDbDate($date)) {
        return '';
    }

    $date = trim((string) $date);
    $ymd  = parseDisplayDateToDb($date);
    if ($ymd !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);

        return $dt ? $dt->format(getDisplayDateFormat($pdo)) : $date;
    }

    $timestamp = strtotime($date);

    return $timestamp !== false ? date(getDisplayDateFormat($pdo), $timestamp) : $date;
}

/**
 * Alias used by spreadsheet export code.
 */
function formatSheetDate(?string $date, ?PDO $pdo = null): string
{
    return formatDisplayDate($date, $pdo);
}

function dbDateToYmd(?string $date): string
{
    if (isEmptyDbDate($date)) {
        return '';
    }

    $date = trim((string) $date);
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
        return substr($date, 0, 10);
    }

    return parseDisplayDateToDb($date);
}

function googleSheetsDateSerial(string $ymd): ?float
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
        return null;
    }

    $base = strtotime('1899-12-30');
    $ts   = strtotime($ymd);
    if ($base === false || $ts === false) {
        return null;
    }

    return (float) round(($ts - $base) / 86400);
}

/**
 * @return string|float
 */
function googleSheetsDateCell(?string $date, ?PDO $pdo = null): string|float
{
    $ymd = dbDateToYmd($date);
    if ($ymd === '') {
        return '';
    }

    $serial = googleSheetsDateSerial($ymd);

    return $serial !== null ? $serial : formatSheetDate($date, $pdo);
}

function formatDisplayDateTime(?string $datetime, ?PDO $pdo = null): string
{
    if (isEmptyDbDate($datetime)) {
        return '';
    }

    $datetime = trim((string) $datetime);
    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return $datetime;
    }

    return date(getDisplayDateFormat($pdo) . ' H:i', $timestamp);
}

/**
 * Parse user input or sheet text to Y-m-d for MySQL DATE columns.
 */
function parseDisplayDateToDb(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    $formats = ['d/m/Y', 'j/n/Y', 'd.m.Y', 'j.n.Y', 'd-m-Y', 'm/d/Y', 'n/j/Y'];
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        if ($date instanceof DateTimeImmutable) {
            $errors = DateTimeImmutable::getLastErrors();
            if (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0) {
                return $date->format('Y-m-d');
            }
        }
    }

    $ts = strtotime($value);

    return $ts !== false ? date('Y-m-d', $ts) : $value;
}

function googleSheetsDatePattern(): string
{
    return GOOGLE_SHEETS_DATE_PATTERN;
}

/**
 * 0-based column indexes for date fields in sync / master / payroll sheets.
 * 6 = DOB, 12 = Event date, 16 = PSA Expiry (master sheet = 17 columns).
 *
 * @return list<int>
 */
function spreadsheetDateColumnIndexes(): array
{
    return [6, 12, 16];
}

/**
 * @return list<int>
 */
function spreadsheetDateColumnIndexesForCount(int $columnCount): array
{
    return array_values(array_filter(
        spreadsheetDateColumnIndexes(),
        static fn (int $index): bool => $index < $columnCount
    ));
}

}
