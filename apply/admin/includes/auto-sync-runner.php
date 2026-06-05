<?php

declare(strict_types=1);

require_once __DIR__ . '/main-admin-bridge.php';
require_once __DIR__ . '/staff-import.php';
require_once __DIR__ . '/google-sheets-sync.php';

/** Minimum seconds between automatic sync runs (JS / background pings). */
function apply_auto_sync_interval_seconds(): int
{
    return 120;
}

function apply_auto_sync_storage_dir(): string
{
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

/**
 * @return array{finished_at?: string, ok?: bool}|null
 */
function apply_auto_sync_read_state(): ?array
{
    $file = apply_auto_sync_storage_dir() . '/auto-sync-state.json';
    if (!is_readable($file)) {
        return null;
    }

    $decoded = json_decode((string) file_get_contents($file), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $result
 */
function apply_auto_sync_write_state(array $result): void
{
    $payload = [
        'finished_at' => gmdate('c'),
        'ok'          => !empty($result['ok']),
        'skipped'     => !empty($result['skipped']),
    ];
    @file_put_contents(
        apply_auto_sync_storage_dir() . '/auto-sync-state.json',
        json_encode($payload, JSON_THROW_ON_ERROR),
        LOCK_EX
    );
}

/**
 * Import approved staff from main ERP and push Google Sheets.
 *
 * @return array<string, mixed>
 */
function run_apply_payroll_sync(PDO $applyPdo, bool $force = false): array
{
    $interval = apply_auto_sync_interval_seconds();
    $started  = gmdate('c');
    $state    = apply_auto_sync_read_state();

    if (!$force && $state !== null && !empty($state['finished_at'])) {
        $finishedAt = strtotime((string) $state['finished_at']);
        if ($finishedAt !== false) {
            $elapsed = time() - $finishedAt;
            if ($elapsed < $interval - 5) {
                return [
                    'ok'       => true,
                    'skipped'  => true,
                    'reason'   => 'throttled',
                    'next_in'  => max(0, $interval - $elapsed),
                    'started'  => $started,
                    'finished' => gmdate('c'),
                ];
            }
        }
    }

    $lockFile = apply_auto_sync_storage_dir() . '/auto-sync.lock';
    $fp       = @fopen($lockFile, 'c+');
    if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
        if ($fp !== false) {
            fclose($fp);
        }

        return [
            'ok'       => true,
            'skipped'  => true,
            'reason'   => 'locked',
            'started'  => $started,
            'finished' => gmdate('c'),
        ];
    }

    $result = [
        'ok'      => false,
        'started' => $started,
        'import'  => null,
        'sheet'   => null,
    ];

    try {
        $eventPdo = getMainAdminPdo();
        if (!$eventPdo instanceof PDO) {
            throw new RuntimeException('Main ERP database is not connected.');
        }

        $result['import']   = apply_import_approved_from_main($eventPdo, $applyPdo);
        $result['sheet']    = run_apply_google_sheets_sync($applyPdo, $eventPdo);
        $result['ok']       = !empty($result['sheet']['ok']);
        $result['finished'] = gmdate('c');
        apply_auto_sync_write_state($result);
    } catch (Throwable $e) {
        $result['error']    = $e->getMessage();
        $result['finished'] = gmdate('c');
        apply_auto_sync_write_state(['ok' => false, 'skipped' => false]);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    return $result;
}
