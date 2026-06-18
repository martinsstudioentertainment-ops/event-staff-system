<?php

declare(strict_types=1);

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/system-settings.php';

/** @return array<string, string> */
function getGoogleSheetsSyncScheduleSettings(PDO $pdo): array
{
    return [
        'sync_enabled'           => getSetting($pdo, 'google_sheets_sync_enabled', '0'),
        'quiet_hours_enabled'    => getSetting($pdo, 'google_sheets_sync_quiet_enabled', '0'),
        'quiet_start'            => getSetting($pdo, 'google_sheets_sync_quiet_start', '22:00'),
        'quiet_end'              => getSetting($pdo, 'google_sheets_sync_quiet_end', '07:00'),
        'min_interval_minutes'   => getSetting($pdo, 'google_sheets_sync_min_interval_minutes', '0'),
        'apply_interval_minutes' => getSetting($pdo, 'google_sheets_apply_sync_interval_minutes', '2'),
        'queue_enabled'          => getSetting($pdo, 'google_sheets_queue_enabled', '1'),
        'cron_jobs_per_run'      => getSetting($pdo, 'google_sheets_cron_jobs_per_run', '1'),
        'timezone'               => getSetting($pdo, 'system_timezone', 'Europe/Dublin'),
    ];
}

/** @param array<string, mixed> $input */
function saveGoogleSheetsSyncScheduleSettings(PDO $pdo, array $input): void
{
    setSetting($pdo, 'google_sheets_sync_enabled', !empty($input['google_sheets_sync_enabled']) ? '1' : '0');
    setSetting($pdo, 'google_sheets_sync_quiet_enabled', !empty($input['google_sheets_sync_quiet_enabled']) ? '1' : '0');
    setSetting($pdo, 'google_sheets_sync_quiet_start', normalizeSyncTimeField((string) ($input['google_sheets_sync_quiet_start'] ?? '22:00')));
    setSetting($pdo, 'google_sheets_sync_quiet_end', normalizeSyncTimeField((string) ($input['google_sheets_sync_quiet_end'] ?? '07:00')));

    $minInterval = max(0, min(1440, (int) ($input['google_sheets_sync_min_interval_minutes'] ?? 0)));
    setSetting($pdo, 'google_sheets_sync_min_interval_minutes', (string) $minInterval);

    $applyInterval = max(1, min(120, (int) ($input['google_sheets_apply_sync_interval_minutes'] ?? 2)));
    setSetting($pdo, 'google_sheets_apply_sync_interval_minutes', (string) $applyInterval);

    setSetting($pdo, 'google_sheets_queue_enabled', !empty($input['google_sheets_queue_enabled']) ? '1' : '0');

    $jobsPerRun = max(1, min(3, (int) ($input['google_sheets_cron_jobs_per_run'] ?? 1)));
    setSetting($pdo, 'google_sheets_cron_jobs_per_run', (string) $jobsPerRun);
}

function normalizeSyncTimeField(string $value): string
{
    $value = trim($value);
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m) === 1) {
        $h = max(0, min(23, (int) $m[1]));
        $i = max(0, min(59, (int) $m[2]));

        return sprintf('%02d:%02d', $h, $i);
    }

    return '22:00';
}

function googleSheetsIsQuietHoursNow(PDO $pdo, ?DateTimeImmutable $now = null): bool
{
    $settings = getGoogleSheetsSyncScheduleSettings($pdo);
    if ($settings['quiet_hours_enabled'] !== '1') {
        return false;
    }

    try {
        $tz  = new DateTimeZone($settings['timezone'] !== '' ? $settings['timezone'] : 'Europe/Dublin');
        $now = $now ?? new DateTimeImmutable('now', $tz);
    } catch (Throwable $e) {
        $now = new DateTimeImmutable('now');
    }

    $startParts = explode(':', $settings['quiet_start']);
    $endParts   = explode(':', $settings['quiet_end']);
    $startM     = ((int) ($startParts[0] ?? 22)) * 60 + (int) ($startParts[1] ?? 0);
    $endM       = ((int) ($endParts[0] ?? 7)) * 60 + (int) ($endParts[1] ?? 0);
    $curM       = (int) $now->format('G') * 60 + (int) $now->format('i');

    if ($startM === $endM) {
        return false;
    }

    if ($startM < $endM) {
        return $curM >= $startM && $curM < $endM;
    }

    return $curM >= $startM || $curM < $endM;
}

/** Whether automatic (live) sheet push is allowed right now. Manual resync bypasses this. */
function googleSheetsAllowLiveSyncNow(PDO $pdo): bool
{
    if (getSetting($pdo, 'google_sheets_sync_enabled', '0') !== '1') {
        return false;
    }

    if (googleSheetsIsQuietHoursNow($pdo)) {
        return false;
    }

    $minInterval = (int) getSetting($pdo, 'google_sheets_sync_min_interval_minutes', '0');
    if ($minInterval > 0) {
        $last = trim(getSetting($pdo, 'google_sheets_last_live_sync_at', ''));
        if ($last !== '') {
            $ts = strtotime($last);
            if ($ts !== false && (time() - $ts) < ($minInterval * 60)) {
                return false;
            }
        }
    }

    return true;
}

function googleSheetsMarkLiveSyncRan(PDO $pdo): void
{
    setSetting($pdo, 'google_sheets_last_live_sync_at', gmdate('Y-m-d H:i:s'));
}

/** @return array<string, mixed> */
function googleSheetsSyncScheduleStatus(PDO $pdo): array
{
    $settings = getGoogleSheetsSyncScheduleSettings($pdo);
    $quiet    = googleSheetsIsQuietHoursNow($pdo);
    $allowed  = googleSheetsAllowLiveSyncNow($pdo);

    $reason = 'Live sync allowed';
    if ($settings['sync_enabled'] !== '1') {
        $reason = 'Live sync is OFF — enable below or use Re-sync all sheets';
    } elseif ($quiet) {
        $reason = 'Quiet hours active (' . $settings['quiet_start'] . '–' . $settings['quiet_end'] . ' ' . $settings['timezone'] . ')';
    } elseif (!$allowed) {
        $reason = 'Minimum interval between auto syncs not reached';
    }

    return [
        'settings'          => $settings,
        'quiet_hours_now'   => $quiet,
        'live_sync_allowed' => $allowed,
        'status_label'      => $reason,
        'last_live_sync_at' => getSetting($pdo, 'google_sheets_last_live_sync_at', ''),
    ];
}

function googleSheetsApplySyncIntervalSeconds(PDO $pdo): int
{
    $minutes = (int) getSetting($pdo, 'google_sheets_apply_sync_interval_minutes', '2');

    return max(60, min(7200, $minutes * 60));
}
