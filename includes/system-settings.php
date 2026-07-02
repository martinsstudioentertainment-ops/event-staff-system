<?php

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/world-currencies.php';
require_once __DIR__ . '/world-locales.php';
require_once __DIR__ . '/world-timezones.php';
require_once __DIR__ . '/date-format.php';

/** @return array<string, string> */
function getSystemLayoutModeOptions(): array
{
    return [
        'dark'   => 'Dark',
        'light'  => 'Light',
        'system' => 'System',
    ];
}

/** @return array<string, string> */
function getSystemCurrencyOptions(): array
{
    return getWorldCurrencyOptions();
}

/** @return array<string, string> */
function getSystemDateFormatOptions(): array
{
    return getDisplayDateFormatOptions();
}

/** @return array<string, string> */
function getSystemSettings(?PDO $pdo = null): array
{
    if ($pdo === null && function_exists('getDB')) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            $pdo = null;
        }
    }

    $defaults = [
        'layout_mode'              => 'dark',
        'timezone'                 => 'Europe/Dublin',
        'currency'                 => 'EUR',
        'date_format'              => 'd/m/Y',
        'language'                 => 'en',
        'maintenance_mode'         => '0',
        'admin_2fa_required'       => '0',
        'activity_logging_enabled' => '1',
        'auto_backup_enabled'      => '0',
    ];

    if ($pdo === null) {
        return $defaults;
    }

    return [
        'layout_mode'              => normalizeSystemLayoutMode(getSetting($pdo, 'system_layout_mode', $defaults['layout_mode'])),
        'timezone'                 => normalizeSystemTimezone(getSetting($pdo, 'system_timezone', $defaults['timezone'])),
        'currency'                 => normalizeSystemCurrency(getSetting($pdo, 'system_currency', $defaults['currency'])),
        'date_format'              => normalizeSystemDateFormat(getSetting($pdo, 'system_date_format', $defaults['date_format'])),
        'language'                 => normalizeSystemLanguage(getSetting($pdo, 'system_language', $defaults['language'])),
        'maintenance_mode'         => getSetting($pdo, 'maintenance_mode', $defaults['maintenance_mode']) === '1' ? '1' : '0',
        'admin_2fa_required'       => getSetting($pdo, 'admin_2fa_required', $defaults['admin_2fa_required']) === '1' ? '1' : '0',
        'activity_logging_enabled' => getSetting($pdo, 'activity_logging_enabled', $defaults['activity_logging_enabled']) === '1' ? '1' : '0',
        'auto_backup_enabled'      => getSetting($pdo, 'auto_backup_enabled', $defaults['auto_backup_enabled']) === '1' ? '1' : '0',
    ];
}

function normalizeSystemLayoutMode(string $value): string
{
    return array_key_exists($value, getSystemLayoutModeOptions()) ? $value : 'dark';
}

function normalizeSystemTimezone(string $value): string
{
    return normalizeWorldTimezone($value);
}

function normalizeSystemCurrency(string $value): string
{
    return normalizeWorldCurrency($value);
}

function normalizeSystemDateFormat(string $value): string
{
    return normalizeDisplayDateFormat($value);
}

function normalizeSystemLanguage(string $value): string
{
    return normalizeWorldLocale($value);
}

/** @return array<string, string> */
function getSystemLanguageOptions(): array
{
    return getWorldLocaleOptions();
}

/** @return array<string, string> */
function getSystemTimezoneOptions(): array
{
    return getWorldTimezoneOptions();
}

/** @param array<string, string> $input */
function validateSystemSettingsInput(array $input): ?string
{
    if (!array_key_exists(trim((string) ($input['layout_mode'] ?? '')), getSystemLayoutModeOptions())) {
        return 'Invalid layout mode selected.';
    }

    $tz = normalizeWorldTimezone(trim((string) ($input['timezone'] ?? '')));
    if (!isValidWorldTimezone($tz)) {
        return 'Invalid timezone selected.';
    }

    $currencyRaw = trim((string) ($input['currency'] ?? ''));
    if ($currencyRaw !== '') {
        $normalized = normalizeWorldCurrency($currencyRaw);
        if (!isValidWorldCurrencyCode($normalized)) {
            return 'Invalid currency selected.';
        }
    }

    if (!array_key_exists(trim((string) ($input['date_format'] ?? '')), getSystemDateFormatOptions())) {
        return 'Invalid date format selected.';
    }

    $language = normalizeWorldLocale(trim((string) ($input['language'] ?? '')));
    if (!isValidWorldLocale($language)) {
        return 'Invalid language selected.';
    }

    return null;
}

/** @param array<string, string> $input */
function saveSystemSettings(PDO $pdo, array $input): void
{
    saveSettings($pdo, [
        'system_layout_mode'         => normalizeSystemLayoutMode(trim((string) ($input['layout_mode'] ?? 'dark'))),
        'system_timezone'            => normalizeSystemTimezone(trim((string) ($input['timezone'] ?? 'UTC'))),
        'system_currency'            => normalizeSystemCurrency(trim((string) ($input['currency'] ?? 'EUR'))),
        'system_date_format'         => normalizeSystemDateFormat(trim((string) ($input['date_format'] ?? 'd/m/Y'))),
        'system_language'            => normalizeSystemLanguage(trim((string) ($input['language'] ?? 'en'))),
        'maintenance_mode'           => !empty($input['maintenance_mode']) ? '1' : '0',
        'admin_2fa_required'         => !empty($input['admin_2fa_required']) ? '1' : '0',
        'admin_login_otp_email'      => strtolower(trim((string) ($input['admin_login_otp_email'] ?? ''))),
        'activity_logging_enabled'   => !empty($input['activity_logging_enabled']) ? '1' : '0',
        'auto_backup_enabled'        => !empty($input['auto_backup_enabled']) ? '1' : '0',
    ]);
}

function applySystemRuntimeSettings(?PDO $pdo = null): void
{
    $settings = getSystemSettings($pdo);
    @date_default_timezone_set($settings['timezone']);
    applySystemMysqlTimezone($pdo);
}

function applySystemMysqlTimezone(?PDO $pdo = null): void
{
    if (!$pdo instanceof PDO) {
        return;
    }

    $tzName = getSystemSettings($pdo)['timezone'];

    try {
        $pdo->exec('SET time_zone = ' . $pdo->quote($tzName));
    } catch (Throwable $e) {
        try {
            $offset = (new DateTime('now', new DateTimeZone($tzName)))->format('P');
            $pdo->exec('SET time_zone = ' . $pdo->quote($offset));
        } catch (Throwable $e2) {
            // Host may not support named time zones — PHP display conversion still applies.
        }
    }
}

function isMaintenanceModeEnabled(?PDO $pdo = null): bool
{
    return getSystemSettings($pdo)['maintenance_mode'] === '1';
}

function isActivityLoggingEnabled(?PDO $pdo = null): bool
{
    return getSystemSettings($pdo)['activity_logging_enabled'] === '1';
}

function isAutoBackupEnabled(?PDO $pdo = null): bool
{
    return getSystemSettings($pdo)['auto_backup_enabled'] === '1';
}

function isAdmin2faRequired(?PDO $pdo = null): bool
{
    return getSystemSettings($pdo)['admin_2fa_required'] === '1';
}

function getSystemDateFormat(?PDO $pdo = null): string
{
    return getSystemSettings($pdo)['date_format'];
}

function getSystemCurrency(?PDO $pdo = null): string
{
    return getSystemSettings($pdo)['currency'];
}

function formatSystemDate(string $date, ?PDO $pdo = null): string
{
    return formatDisplayDate($date, $pdo);
}

function formatSystemDateTime(string $datetime, ?PDO $pdo = null): string
{
    if (function_exists('formatDbDateTimeForDisplay')) {
        $formatted = formatDbDateTimeForDisplay($datetime, $pdo, true);

        return $formatted !== '' ? $formatted : formatDisplayDateTime($datetime, $pdo);
    }

    return formatDisplayDateTime($datetime, $pdo);
}

function formatSystemCurrencyAmount(float $amount, ?PDO $pdo = null): string
{
    $code = getSystemCurrency($pdo);

    return $code . ' ' . number_format($amount, 2);
}

function getSystemLayoutTheme(?PDO $pdo = null): string
{
    $mode = getSystemSettings($pdo)['layout_mode'];

    if ($mode === 'system') {
        return 'dark';
    }

    return $mode;
}

function enforceMaintenanceMode(?PDO $pdo): void
{
    if (!isMaintenanceModeEnabled($pdo)) {
        return;
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    if (str_contains($script, '/admin/') || str_contains($script, '/api/') || str_contains($script, '/cron/')) {
        return;
    }

    if (preg_match('#/(check-in|event-sign|sign-in|status)\.php$#', $script)) {
        return;
    }

    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');

    $siteName = $pdo ? getSiteName($pdo) : 'Event Staff System';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Maintenance — ' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#070b14;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:1.5rem}'
        . '.box{max-width:420px;text-align:center;border:1px solid rgba(59,130,246,.2);border-radius:16px;padding:2rem;background:#111827}'
        . 'h1{margin:0 0 .5rem;font-size:1.5rem}p{margin:0;color:#94a3b8;line-height:1.5}</style></head><body>'
        . '<div class="box"><h1>Under maintenance</h1><p>' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8')
        . ' is temporarily unavailable. Staff sign-in links still work. Please check back soon.</p></div></body></html>';
    exit;
}
