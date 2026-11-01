<?php

declare(strict_types=1);

/** Ensure pwa_install_events table exists. */
function ensurePwaInstallTrackingSchema(PDO $pdo): void
{
    static $ready = [];

    $key = spl_object_id($pdo);
    if (!empty($ready[$key])) {
        return;
    }

    try {
        if ($pdo->query("SHOW TABLES LIKE 'pwa_install_events'")->fetchColumn()) {
            $ready[$key] = true;

            return;
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] ensurePwaInstallTrackingSchema lookup: ' . $e->getMessage());

        return;
    }

    $path = dirname(__DIR__) . '/database/migrate-phase56-pwa-install-log.sql';
    if (is_file($path)) {
        try {
            $pdo->exec((string) file_get_contents($path));
        } catch (Throwable $e) {
            error_log('[EventStaff] ensurePwaInstallTrackingSchema migrate: ' . $e->getMessage());
        }
    }

    $ready[$key] = true;
}

function normalizePwaInstallContext(string $context): string
{
    return $context === 'admin' ? 'admin' : 'staff';
}

function normalizePwaInstallEventType(string $eventType): string
{
    return in_array($eventType, ['installed', 'standalone_open'], true) ? $eventType : '';
}

function detectPwaInstallPlatform(string $userAgent): string
{
    $ua = strtolower($userAgent);
    if (preg_match('/iphone|ipad|ipod/i', $userAgent) === 1
        || (stripos($ua, 'macintosh') !== false && stripos($ua, 'mobile') !== false)) {
        return 'ios';
    }
    if (preg_match('/android/i', $userAgent) === 1) {
        return 'android';
    }
    if ($ua !== '') {
        return 'desktop';
    }

    return 'unknown';
}

function normalizePwaVisitorKey(string $visitorKey): string
{
    $visitorKey = strtolower(trim($visitorKey));
    if (preg_match('/^[a-f0-9]{16,64}$/', $visitorKey) !== 1) {
        return '';
    }

    return substr($visitorKey, 0, 40);
}

/**
 * @return array{ok: bool, duplicate: bool}
 */
function logPwaInstallEvent(
    PDO $pdo,
    string $context,
    string $eventType,
    string $visitorKey,
    string $userAgent = '',
    ?string $platform = null
): array {
    ensurePwaInstallTrackingSchema($pdo);

    $context   = normalizePwaInstallContext($context);
    $eventType = normalizePwaInstallEventType($eventType);
    $visitorKey = normalizePwaVisitorKey($visitorKey);

    if ($eventType === '' || $visitorKey === '') {
        return ['ok' => false, 'duplicate' => false];
    }

    $platform = $platform !== null && $platform !== ''
        ? $platform
        : detectPwaInstallPlatform($userAgent);

    if (!in_array($platform, ['ios', 'android', 'desktop', 'unknown'], true)) {
        $platform = 'unknown';
    }

    try {
        if ($eventType === 'installed') {
            $check = $pdo->prepare(
                'SELECT id FROM pwa_install_events
                 WHERE visitor_key = :visitor_key AND context = :context AND event_type = :event_type
                 LIMIT 1'
            );
            $check->execute([
                'visitor_key' => $visitorKey,
                'context'     => $context,
                'event_type'  => $eventType,
            ]);
            if ($check->fetchColumn()) {
                return ['ok' => true, 'duplicate' => true];
            }
        } else {
            $check = $pdo->prepare(
                'SELECT id FROM pwa_install_events
                 WHERE visitor_key = :visitor_key AND context = :context AND event_type = :event_type
                   AND DATE(created_at) = CURDATE()
                 LIMIT 1'
            );
            $check->execute([
                'visitor_key' => $visitorKey,
                'context'     => $context,
                'event_type'  => $eventType,
            ]);
            if ($check->fetchColumn()) {
                return ['ok' => true, 'duplicate' => true];
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO pwa_install_events (context, event_type, platform, visitor_key, user_agent)
             VALUES (:context, :event_type, :platform, :visitor_key, :user_agent)'
        );
        $stmt->execute([
            'context'     => $context,
            'event_type'  => $eventType,
            'platform'    => $platform,
            'visitor_key' => $visitorKey,
            'user_agent'  => substr(trim($userAgent), 0, 500),
        ]);

        return ['ok' => true, 'duplicate' => false];
    } catch (Throwable $e) {
        error_log('[EventStaff] logPwaInstallEvent: ' . $e->getMessage());

        return ['ok' => false, 'duplicate' => false];
    }
}

/**
 * @return array{
 *   installed_total: int,
 *   standalone_devices: int,
 *   by_platform: array<string, int>,
 *   by_context: array<string, int>,
 *   recent: list<array<string, mixed>>
 * }
 */
function getPwaInstallStats(PDO $pdo): array
{
    ensurePwaInstallTrackingSchema($pdo);

    $stats = [
        'installed_total'      => 0,
        'standalone_devices'   => 0,
        'by_platform'          => ['ios' => 0, 'android' => 0, 'desktop' => 0, 'unknown' => 0],
        'by_context'           => ['staff' => 0, 'admin' => 0],
        'recent'               => [],
    ];

    try {
        if (!$pdo->query("SHOW TABLES LIKE 'pwa_install_events'")->fetchColumn()) {
            return $stats;
        }

        $stats['installed_total'] = (int) $pdo->query(
            "SELECT COUNT(DISTINCT visitor_key) FROM pwa_install_events WHERE event_type = 'installed'"
        )->fetchColumn();

        $stats['standalone_devices'] = (int) $pdo->query(
            "SELECT COUNT(DISTINCT visitor_key) FROM pwa_install_events WHERE event_type = 'standalone_open'"
        )->fetchColumn();

        $platformRows = $pdo->query(
            "SELECT platform, COUNT(DISTINCT visitor_key) AS device_count
             FROM pwa_install_events
             WHERE event_type = 'installed'
             GROUP BY platform"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($platformRows as $row) {
            $platform = (string) ($row['platform'] ?? 'unknown');
            if (isset($stats['by_platform'][$platform])) {
                $stats['by_platform'][$platform] = (int) ($row['device_count'] ?? 0);
            }
        }

        $contextRows = $pdo->query(
            "SELECT context, COUNT(DISTINCT visitor_key) AS device_count
             FROM pwa_install_events
             WHERE event_type = 'installed'
             GROUP BY context"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($contextRows as $row) {
            $ctx = (string) ($row['context'] ?? '');
            if (isset($stats['by_context'][$ctx])) {
                $stats['by_context'][$ctx] = (int) ($row['device_count'] ?? 0);
            }
        }

        $stats['recent'] = $pdo->query(
            "SELECT context, event_type, platform, visitor_key, user_agent, created_at
             FROM pwa_install_events
             ORDER BY created_at DESC, id DESC
             LIMIT 25"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[EventStaff] getPwaInstallStats: ' . $e->getMessage());
    }

    return $stats;
}

function formatPwaInstallPlatformLabel(string $platform): string
{
    return match ($platform) {
        'ios'     => 'iPhone / iPad',
        'android' => 'Android',
        'desktop' => 'Desktop',
        default   => 'Unknown',
    };
}
