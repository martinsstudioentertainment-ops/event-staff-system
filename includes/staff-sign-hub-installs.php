<?php

declare(strict_types=1);

/**
 * Staff Sign Hub — download / install / device usage tracking (global, not one-event).
 */

require_once __DIR__ . '/pwa-install-analytics.php';

function ensureStaffSignHubInstallSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS staff_sign_hub_devices (
                id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                visitor_key      VARCHAR(64) NOT NULL,
                registration_id  INT UNSIGNED NULL,
                staff_email      VARCHAR(255) NULL,
                first_name       VARCHAR(100) NULL,
                surname          VARCHAR(100) NULL,
                device_type      VARCHAR(32) NOT NULL DEFAULT \'unknown\',
                os_name          VARCHAR(64) NULL,
                browser_name     VARCHAR(64) NULL,
                display_mode     VARCHAR(32) NULL,
                channel          VARCHAR(32) NOT NULL DEFAULT \'web\',
                downloaded       TINYINT(1) NOT NULL DEFAULT 0,
                installed        TINYINT(1) NOT NULL DEFAULT 0,
                download_at      TIMESTAMP NULL DEFAULT NULL,
                installed_at     TIMESTAMP NULL DEFAULT NULL,
                first_seen_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_event_id    INT UNSIGNED NULL,
                open_count       INT UNSIGNED NOT NULL DEFAULT 0,
                user_agent       VARCHAR(500) NULL,
                UNIQUE KEY uq_ssh_visitor (visitor_key),
                INDEX idx_ssh_installed (installed),
                INDEX idx_ssh_downloaded (downloaded),
                INDEX idx_ssh_reg (registration_id),
                INDEX idx_ssh_last_seen (last_seen_at),
                INDEX idx_ssh_email (staff_email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $e) {
        error_log('[EventStaff] staff_sign_hub_devices schema: ' . $e->getMessage());
    }

    // One phone → one person per event (cannot sign colleagues in).
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS staff_sign_hub_device_locks (
                id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                visitor_key      VARCHAR(64) NOT NULL,
                event_id         INT UNSIGNED NOT NULL,
                registration_id  INT UNSIGNED NOT NULL,
                locked_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_ssh_lock_device_event (visitor_key, event_id),
                UNIQUE KEY uq_ssh_lock_reg_event (registration_id, event_id),
                INDEX idx_ssh_lock_event (event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $e) {
        error_log('[EventStaff] staff_sign_hub_device_locks schema: ' . $e->getMessage());
    }
}

/**
 * @return list<array<string, mixed>>
 */
function listStaffSignHubDeviceLocksForEvent(PDO $pdo, int $eventId): array
{
    ensureStaffSignHubInstallSchema($pdo);
    if ($eventId < 1) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT l.id, l.visitor_key, l.event_id, l.registration_id, l.locked_at,
                    sr.first_name, sr.surname, sr.email, sr.staff_role
             FROM staff_sign_hub_device_locks l
             LEFT JOIN staff_registrations sr ON sr.id = l.registration_id
             WHERE l.event_id = :eid
             ORDER BY l.locked_at DESC'
        );
        $stmt->execute(['eid' => $eventId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[EventStaff] listStaffSignHubDeviceLocksForEvent: ' . $e->getMessage());

        return [];
    }
}

/**
 * Admin-only override: clear a phone↔person lock for an event.
 *
 * @return array{ok: bool, message: string}
 */
function adminUnlockStaffSignHubDeviceLock(PDO $pdo, int $lockId, int $eventId): array
{
    ensureStaffSignHubInstallSchema($pdo);
    if ($lockId < 1 || $eventId < 1) {
        return ['ok' => false, 'message' => 'Invalid unlock request.'];
    }

    try {
        $stmt = $pdo->prepare(
            'DELETE FROM staff_sign_hub_device_locks WHERE id = :id AND event_id = :eid LIMIT 1'
        );
        $stmt->execute(['id' => $lockId, 'eid' => $eventId]);
        if ($stmt->rowCount() < 1) {
            return ['ok' => false, 'message' => 'Lock not found for this event.'];
        }

        return ['ok' => true, 'message' => 'Phone lock cleared. That person can sign in again on Staff Sign.'];
    } catch (Throwable $e) {
        error_log('[EventStaff] adminUnlockStaffSignHubDeviceLock: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Could not clear lock.'];
    }
}

/**
 * Enforce: one device signs in only one person for an event; that person cannot use a second phone.
 * Only admin can clear locks (Staff Sign Hub → Unlock).
 *
 * @return array{ok: bool, message: string}
 */
function staffSignHubEnforceOneUserOneDevice(PDO $pdo, string $visitorKey, int $eventId, int $registrationId): array
{
    ensureStaffSignHubInstallSchema($pdo);

    $visitorKey = normalizeStaffSignHubVisitorKey($visitorKey);
    if ($visitorKey === '' || $eventId < 1 || $registrationId < 1) {
        return ['ok' => false, 'message' => 'This phone must open Staff Sign normally before sign-in.'];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT visitor_key, registration_id FROM staff_sign_hub_device_locks
             WHERE event_id = :eid AND (visitor_key = :vk OR registration_id = :rid)
             LIMIT 2'
        );
        $stmt->execute([
            'eid' => $eventId,
            'vk'  => $visitorKey,
            'rid' => $registrationId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $lockedVk  = (string) ($row['visitor_key'] ?? '');
            $lockedRid = (int) ($row['registration_id'] ?? 0);
            if ($lockedVk === $visitorKey && $lockedRid === $registrationId) {
                return ['ok' => true, 'message' => ''];
            }
            if ($lockedVk === $visitorKey && $lockedRid !== $registrationId) {
                return [
                    'ok'      => false,
                    'message' => 'This phone is locked to one person for this event. Only admin can unlock or sign someone else in.',
                ];
            }
            if ($lockedRid === $registrationId && $lockedVk !== $visitorKey) {
                return [
                    'ok'      => false,
                    'message' => 'This person is locked to another phone. Only admin can unlock or use Manual / Attendant sign-in.',
                ];
            }
        }

        $ins = $pdo->prepare(
            'INSERT INTO staff_sign_hub_device_locks (visitor_key, event_id, registration_id)
             VALUES (:vk, :eid, :rid)'
        );
        $ins->execute([
            'vk'  => $visitorKey,
            'eid' => $eventId,
            'rid' => $registrationId,
        ]);

        return ['ok' => true, 'message' => ''];
    } catch (Throwable $e) {
        // Race on unique keys → re-check.
        try {
            $stmt = $pdo->prepare(
                'SELECT visitor_key, registration_id FROM staff_sign_hub_device_locks
                 WHERE event_id = :eid AND (visitor_key = :vk OR registration_id = :rid)
                 LIMIT 2'
            );
            $stmt->execute([
                'eid' => $eventId,
                'vk'  => $visitorKey,
                'rid' => $registrationId,
            ]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                if ((string) ($row['visitor_key'] ?? '') === $visitorKey
                    && (int) ($row['registration_id'] ?? 0) === $registrationId) {
                    return ['ok' => true, 'message' => ''];
                }
            }
        } catch (Throwable $e2) {
            // fall through
        }
        error_log('[EventStaff] staffSignHubEnforceOneUserOneDevice: ' . $e->getMessage());

        return [
            'ok'      => false,
            'message' => 'This phone is locked to one person for this event. Only admin can unlock.',
        ];
    }
}

function normalizeStaffSignHubVisitorKey(string $key): string
{
    return normalizePwaVisitorKey($key);
}

/**
 * @param array<string, mixed> $payload
 * @return array{ok: bool, message: string, data?: array<string, mixed>}
 */
function recordStaffSignHubDeviceEvent(PDO $pdo, string $eventType, array $payload): array
{
    ensureStaffSignHubInstallSchema($pdo);

    $visitorKey = normalizeStaffSignHubVisitorKey((string) ($payload['visitor_key'] ?? ''));
    if ($visitorKey === '') {
        return ['ok' => false, 'message' => 'Invalid device id.'];
    }

    $eventType = strtolower(trim($eventType));
    $allowed = ['open', 'download', 'install', 'identify', 'usage_ping'];
    if (!in_array($eventType, $allowed, true)) {
        return ['ok' => false, 'message' => 'Unknown event.'];
    }

    $ua = trim((string) ($payload['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')));
    $parsed = parsePwaUserAgent($ua);
    $displayMode = trim((string) ($payload['display_mode'] ?? ''));
    $channel = strtolower(trim((string) ($payload['channel'] ?? 'web')));
    if (!in_array($channel, ['web', 'apk', 'pwa'], true)) {
        $channel = 'web';
    }
    if ((string) ($payload['app'] ?? '') === '1' || str_contains(strtolower($ua), 'olasentra')) {
        // APK WebView often sends app=1
        if ($channel === 'web' && ((string) ($payload['app'] ?? '') === '1')) {
            $channel = 'apk';
        }
    }

    $lastEventId = (int) ($payload['event_id'] ?? 0);
    if ($lastEventId < 1) {
        $lastEventId = null;
    }

    $registrationId = (int) ($payload['registration_id'] ?? 0);
    $staffEmail = strtolower(trim((string) ($payload['staff_email'] ?? '')));
    if ($staffEmail !== '' && !filter_var($staffEmail, FILTER_VALIDATE_EMAIL)) {
        $staffEmail = '';
    }
    $firstName = trim((string) ($payload['first_name'] ?? ''));
    $surname = trim((string) ($payload['surname'] ?? ''));

    if ($eventType === 'identify' && $registrationId > 0) {
        try {
            $st = $pdo->prepare(
                'SELECT id, first_name, surname, email FROM staff_registrations WHERE id = :id LIMIT 1'
            );
            $st->execute(['id' => $registrationId]);
            $reg = $st->fetch(PDO::FETCH_ASSOC);
            if ($reg) {
                $firstName = trim((string) ($reg['first_name'] ?? $firstName));
                $surname = trim((string) ($reg['surname'] ?? $surname));
                $staffEmail = strtolower(trim((string) ($reg['email'] ?? $staffEmail)));
            }
        } catch (Throwable $e) {
            // keep payload names
        }
    }

    $markDownload = $eventType === 'download';
    $markInstall = in_array($eventType, ['install', 'open'], true)
        && (in_array($displayMode, ['standalone', 'fullscreen', 'minimal-ui'], true)
            || $channel === 'apk'
            || $eventType === 'install');

    if ($eventType === 'install') {
        $markInstall = true;
    }

    $stmt = $pdo->prepare('SELECT * FROM staff_sign_hub_devices WHERE visitor_key = :vk LIMIT 1');
    $stmt->execute(['vk' => $visitorKey]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (is_array($existing)) {
        $sets = [
            'last_seen_at = NOW()',
            'device_type = :device_type',
            'os_name = :os_name',
            'browser_name = :browser_name',
            'user_agent = :user_agent',
            'open_count = open_count + :open_inc',
        ];
        $params = [
            'vk'           => $visitorKey,
            'device_type'  => $parsed['device_type'],
            'os_name'      => $parsed['os_name'],
            'browser_name' => $parsed['browser_name'],
            'user_agent'   => $ua !== '' ? substr($ua, 0, 500) : null,
            'open_inc'     => in_array($eventType, ['open', 'usage_ping'], true) ? 1 : 0,
        ];

        if ($displayMode !== '') {
            $sets[] = 'display_mode = :display_mode';
            $params['display_mode'] = substr($displayMode, 0, 32);
        }
        if ($channel !== '') {
            $sets[] = 'channel = :channel';
            $params['channel'] = $channel;
        }
        if ($lastEventId !== null) {
            $sets[] = 'last_event_id = :last_event_id';
            $params['last_event_id'] = $lastEventId;
        }
        if ($registrationId > 0) {
            $sets[] = 'registration_id = :registration_id';
            $params['registration_id'] = $registrationId;
        }
        if ($staffEmail !== '') {
            $sets[] = 'staff_email = :staff_email';
            $params['staff_email'] = substr($staffEmail, 0, 255);
        }
        if ($firstName !== '') {
            $sets[] = 'first_name = :first_name';
            $params['first_name'] = substr($firstName, 0, 100);
        }
        if ($surname !== '') {
            $sets[] = 'surname = :surname';
            $params['surname'] = substr($surname, 0, 100);
        }
        if ($markDownload) {
            $sets[] = 'downloaded = 1';
            $sets[] = 'download_at = COALESCE(download_at, NOW())';
        }
        if ($markInstall) {
            $sets[] = 'installed = 1';
            $sets[] = 'installed_at = COALESCE(installed_at, NOW())';
            $sets[] = 'downloaded = 1';
            $sets[] = 'download_at = COALESCE(download_at, NOW())';
        }

        $sql = 'UPDATE staff_sign_hub_devices SET ' . implode(', ', $sets) . ' WHERE visitor_key = :vk';
        $upd = $pdo->prepare($sql);
        $upd->execute($params);
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO staff_sign_hub_devices (
                visitor_key, registration_id, staff_email, first_name, surname,
                device_type, os_name, browser_name, display_mode, channel,
                downloaded, installed, download_at, installed_at,
                last_event_id, open_count, user_agent
             ) VALUES (
                :visitor_key, :registration_id, :staff_email, :first_name, :surname,
                :device_type, :os_name, :browser_name, :display_mode, :channel,
                :downloaded, :installed, :download_at, :installed_at,
                :last_event_id, :open_count, :user_agent
             )'
        );
        $ins->execute([
            'visitor_key'     => $visitorKey,
            'registration_id' => $registrationId > 0 ? $registrationId : null,
            'staff_email'     => $staffEmail !== '' ? substr($staffEmail, 0, 255) : null,
            'first_name'      => $firstName !== '' ? substr($firstName, 0, 100) : null,
            'surname'         => $surname !== '' ? substr($surname, 0, 100) : null,
            'device_type'     => $parsed['device_type'],
            'os_name'         => $parsed['os_name'],
            'browser_name'    => $parsed['browser_name'],
            'display_mode'    => $displayMode !== '' ? substr($displayMode, 0, 32) : null,
            'channel'         => $channel,
            'downloaded'      => ($markDownload || $markInstall) ? 1 : 0,
            'installed'       => $markInstall ? 1 : 0,
            'download_at'     => ($markDownload || $markInstall) ? date('Y-m-d H:i:s') : null,
            'installed_at'    => $markInstall ? date('Y-m-d H:i:s') : null,
            'last_event_id'   => $lastEventId,
            'open_count'      => in_array($eventType, ['open', 'usage_ping'], true) ? 1 : 0,
            'user_agent'      => $ua !== '' ? substr($ua, 0, 500) : null,
        ]);
    }

    return [
        'ok'      => true,
        'message' => 'Recorded.',
        'data'    => ['visitor_key' => $visitorKey],
    ];
}

/**
 * @return array{total: int, downloaded: int, installed: int, identified: int, devices: list<array<string, mixed>>}
 */
function getStaffSignHubInstallReport(PDO $pdo, int $limit = 500): array
{
    ensureStaffSignHubInstallSchema($pdo);

    $total = 0;
    $downloaded = 0;
    $installed = 0;
    $identified = 0;
    $devices = [];

    try {
        $total = (int) $pdo->query('SELECT COUNT(*) FROM staff_sign_hub_devices')->fetchColumn();
        $downloaded = (int) $pdo->query('SELECT COUNT(*) FROM staff_sign_hub_devices WHERE downloaded = 1')->fetchColumn();
        $installed = (int) $pdo->query('SELECT COUNT(*) FROM staff_sign_hub_devices WHERE installed = 1')->fetchColumn();
        $identified = (int) $pdo->query(
            'SELECT COUNT(*) FROM staff_sign_hub_devices
             WHERE registration_id IS NOT NULL OR (staff_email IS NOT NULL AND staff_email <> \'\')
                OR (first_name IS NOT NULL AND first_name <> \'\')'
        )->fetchColumn();

        $limit = max(1, min(2000, $limit));
        $sql = "SELECT d.*, e.name AS last_event_name, e.event_date AS last_event_date
                FROM staff_sign_hub_devices d
                LEFT JOIN events e ON e.id = d.last_event_id
                ORDER BY
                    d.installed DESC,
                    d.downloaded DESC,
                    d.last_seen_at DESC
                LIMIT {$limit}";
        $devices = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[EventStaff] staff sign hub install report: ' . $e->getMessage());
    }

    return [
        'total'      => $total,
        'downloaded' => $downloaded,
        'installed'  => $installed,
        'identified' => $identified,
        'devices'    => $devices,
    ];
}

/**
 * Events with geocode status for hub overview (multi-event).
 *
 * @return list<array<string, mixed>>
 */
function listStaffSignHubEventsGeoStatus(PDO $pdo, int $limit = 80): array
{
    require_once __DIR__ . '/platform/staff-sign-hub.php';
    require_once __DIR__ . '/maps-core.php';

    $out = [];
    foreach (listEventsForStaffSignHub($pdo, $limit) as $ev) {
        $venue = getEventVenueCoordinates($ev);
        $out[] = [
            'id'               => (int) ($ev['id'] ?? 0),
            'name'             => (string) ($ev['name'] ?? ''),
            'event_date'       => substr((string) ($ev['event_date'] ?? ''), 0, 10),
            'start_time'       => (string) ($ev['start_time'] ?? ''),
            'end_time'         => (string) ($ev['end_time'] ?? ''),
            'venue_eircode'    => (string) ($ev['venue_eircode'] ?? ''),
            'venue_configured' => $venue !== null,
            'venue_lat'        => $venue['lat'] ?? null,
            'venue_lng'        => $venue['lng'] ?? null,
            'signin_radius_m'  => getEventSigninRadiusMeters($ev, $pdo),
        ];
    }

    return $out;
}

function sendStaffSignHubInstallsCsv(PDO $pdo): void
{
    $report = getStaffSignHubInstallReport($pdo, 2000);
    $filename = 'staff-sign-hub-installs-' . date('Y-m-d-His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, [
        'Name', 'Email', 'Registration ID', 'Downloaded', 'Installed', 'Channel',
        'Device', 'OS', 'Browser', 'Display mode', 'Open count',
        'First seen', 'Last seen', 'Download at', 'Installed at',
        'Last event', 'Last event date', 'Visitor key',
    ]);

    foreach ($report['devices'] as $row) {
        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? ''));
        fputcsv($out, [
            $name !== '' ? $name : '(not identified yet)',
            (string) ($row['staff_email'] ?? ''),
            (string) ($row['registration_id'] ?? ''),
            !empty($row['downloaded']) ? 'Yes' : 'No',
            !empty($row['installed']) ? 'Yes' : 'No',
            (string) ($row['channel'] ?? ''),
            (string) ($row['device_type'] ?? ''),
            (string) ($row['os_name'] ?? ''),
            (string) ($row['browser_name'] ?? ''),
            (string) ($row['display_mode'] ?? ''),
            (string) ($row['open_count'] ?? '0'),
            (string) ($row['first_seen_at'] ?? ''),
            (string) ($row['last_seen_at'] ?? ''),
            (string) ($row['download_at'] ?? ''),
            (string) ($row['installed_at'] ?? ''),
            (string) ($row['last_event_name'] ?? ''),
            (string) ($row['last_event_date'] ?? ''),
            (string) ($row['visitor_key'] ?? ''),
        ]);
    }

    fclose($out);
    exit;
}
