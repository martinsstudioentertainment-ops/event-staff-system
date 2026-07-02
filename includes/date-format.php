<?php

declare(strict_types=1);

/**
 * Irish / EU display dates (DD/MM/YYYY) — DB stays Y-m-d.
 */

const IRISH_DATE_FORMAT = 'd/m/Y';

/** Google Sheets NUMBER_FORMAT pattern for date cells. */
const GOOGLE_SHEETS_DATE_PATTERN = 'dd/mm/yyyy';

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
    if ($pdo === null && function_exists('getDB')) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            $pdo = null;
        }
    }

    if ($pdo instanceof PDO) {
        require_once __DIR__ . '/settings-repository.php';

        return normalizeDisplayDateFormat(getSetting($pdo, 'system_date_format', IRISH_DATE_FORMAT));
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

/**
 * Normalize any DB / sheet date string to Y-m-d.
 */
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

/**
 * Google Sheets / Excel serial (days since 1899-12-30).
 */
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
 * Date cell for Google Sheets API — serial number with dd/mm/yyyy column format (locale-safe).
 *
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

    $formatted = formatDbDateTimeForDisplay((string) $datetime, $pdo, false);

    return $formatted !== '' ? $formatted : trim((string) $datetime);
}

/**
 * System timezone name (default Europe/Dublin).
 */
function getSystemTimezone(?PDO $pdo = null): string
{
    if ($pdo instanceof PDO && function_exists('getSystemSettings')) {
        return getSystemSettings($pdo)['timezone'];
    }

    return 'Europe/Dublin';
}

/**
 * Format a MySQL datetime for admin/staff screens in Irish local time.
 *
 * @param bool $assumeUtc When true, parse stored value as UTC then convert (legacy checked_in_at).
 */
function formatDbDateTimeForDisplay(string $datetime, ?PDO $pdo = null, bool $assumeUtc = false): string
{
    $datetime = trim($datetime);
    if ($datetime === '' || isEmptyDbDate($datetime)) {
        return '';
    }

    if ($pdo instanceof PDO && function_exists('applySystemRuntimeSettings')) {
        applySystemRuntimeSettings($pdo);
    } elseif (function_exists('applySystemRuntimeSettings')) {
        applySystemRuntimeSettings(null);
    }

    $tzName = getSystemTimezone($pdo);
    $sourceTz = $assumeUtc ? 'UTC' : $tzName;

    try {
        $dt = new DateTimeImmutable($datetime, new DateTimeZone($sourceTz));
        if ($assumeUtc) {
            $dt = $dt->setTimezone(new DateTimeZone($tzName));
        }

        return $dt->format(getDisplayDateFormat($pdo) . ' H:i');
    } catch (Throwable $e) {
        $timestamp = strtotime($datetime);

        return $timestamp !== false ? date(getDisplayDateFormat($pdo) . ' H:i', $timestamp) : $datetime;
    }
}

/**
 * Best check-in timestamp for a roster row (GPS activation before legacy checked_in_at).
 */
function resolveAttendanceCheckinTimestamp(array $row): string
{
    foreach (['activated_at', 'check_in_gps_at', 'checked_in_at'] as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '' && !isEmptyDbDate($value)) {
            return $value;
        }
    }

    return '';
}

/**
 * Irish-local check-in time for admin attendance boards and exports.
 */
function formatAttendanceCheckinDateTime(array $row, ?PDO $pdo = null): string
{
    $activated = trim((string) ($row['activated_at'] ?? ''));
    $gpsAt     = trim((string) ($row['check_in_gps_at'] ?? ''));
    $checkedIn = trim((string) ($row['checked_in_at'] ?? ''));

    if ($activated !== '' && !isEmptyDbDate($activated)) {
        return formatDbDateTimeForDisplay($activated, $pdo, false);
    }
    if ($gpsAt !== '' && !isEmptyDbDate($gpsAt)) {
        return formatDbDateTimeForDisplay($gpsAt, $pdo, false);
    }
    if ($checkedIn !== '' && !isEmptyDbDate($checkedIn)) {
        return formatDbDateTimeForDisplay($checkedIn, $pdo, true);
    }

    return '';
}

function formatAttendanceCheckinTime(array $row, ?PDO $pdo = null): string
{
    $full = formatAttendanceCheckinDateTime($row, $pdo);
    if ($full === '') {
        return '';
    }

    if (preg_match('/(\d{1,2}:\d{2})$/', $full, $m)) {
        return $m[1];
    }

    return $full;
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
 * 6 = Date Of Birth, 12 = Event date, 16 = PSA Expiry (17-column event tabs).
 *
 * @return list<int>
 */
function spreadsheetDateColumnIndexes(): array
{
    return [6, 12, 16];
}

/**
 * Date column indexes that exist within a sheet width (payroll = 10 cols, master/sync = 17).
 *
 * @return list<int>
 */
function spreadsheetDateColumnIndexesForCount(int $columnCount): array
{
    return array_values(array_filter(
        spreadsheetDateColumnIndexes(),
        static fn (int $index): bool => $index < $columnCount
    ));
}

/**
 * Calendar "today" for shifts and check-in — uses system timezone (Europe/Dublin), not raw MySQL UTC date.
 */
function getOperationalTodayYmd(?PDO $pdo = null): string
{
    if ($pdo === null && function_exists('getDB')) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            $pdo = null;
        }
    }

    if ($pdo instanceof PDO && function_exists('applySystemRuntimeSettings')) {
        applySystemRuntimeSettings($pdo);
    }

    return date('Y-m-d');
}
