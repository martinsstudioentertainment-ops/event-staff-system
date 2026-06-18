<?php

declare(strict_types=1);

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/production-readiness.php';

function ensurePwaInstallAnalyticsSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;

    try {
        if ($pdo->query("SHOW TABLES LIKE 'pwa_app_devices'")->fetchColumn()) {
            return;
        }
    } catch (Throwable $e) {
        // Continue and attempt CREATE below.
    }

    $path = dirname(__DIR__) . '/database/migrate-pwa-install-analytics.sql';
    if (is_file($path)) {
        $sql = file_get_contents($path);
        if ($sql !== false && trim($sql) !== '') {
            try {
                $pdo->exec($sql);

                return;
            } catch (Throwable $e) {
                error_log('[PWA] migrate-pwa-install-analytics.sql: ' . $e->getMessage());
            }
        }
    }

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS pwa_app_devices (
                id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                visitor_key     VARCHAR(64) NOT NULL,
                staff_email     VARCHAR(255) NULL,
                app_context     VARCHAR(16) NOT NULL DEFAULT \'staff\',
                device_type     VARCHAR(32) NOT NULL DEFAULT \'unknown\',
                os_name         VARCHAR(64) NULL,
                browser_name    VARCHAR(64) NULL,
                display_mode    VARCHAR(32) NULL,
                installed       TINYINT(1) NOT NULL DEFAULT 0,
                install_method  VARCHAR(32) NULL,
                first_seen_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                installed_at    TIMESTAMP NULL,
                user_agent      VARCHAR(500) NULL,
                UNIQUE KEY uq_pwa_visitor_context (visitor_key, app_context),
                INDEX idx_pwa_installed (installed),
                INDEX idx_pwa_device_type (device_type),
                INDEX idx_pwa_staff_email (staff_email),
                INDEX idx_pwa_last_seen (last_seen_at),
                INDEX idx_pwa_app_context (app_context)
            )'
        );
    } catch (Throwable $e) {
        error_log('[PWA] ensurePwaInstallAnalyticsSchema: ' . $e->getMessage());
    }
}

/**
 * @return array{device_type: string, os_name: string, browser_name: string}
 */
function parsePwaUserAgent(string $ua): array
{
    $ua = trim($ua);
    $lower = strtolower($ua);

    $deviceType = 'desktop';
    $osName     = 'Unknown';

    if (str_contains($lower, 'iphone')) {
        $deviceType = 'iphone';
        $osName     = 'iOS (iPhone)';
    } elseif (str_contains($lower, 'ipad') || (str_contains($lower, 'macintosh') && str_contains($lower, 'mobile'))) {
        $deviceType = 'ipad';
        $osName     = 'iOS (iPad)';
    } elseif (str_contains($lower, 'android')) {
        $deviceType = 'android';
        $osName     = 'Android';
    } elseif (str_contains($lower, 'windows')) {
        $deviceType = 'desktop';
        $osName     = 'Windows';
    } elseif (str_contains($lower, 'macintosh') || str_contains($lower, 'mac os')) {
        $deviceType = 'desktop';
        $osName     = 'macOS';
    } elseif (str_contains($lower, 'linux')) {
        $deviceType = 'desktop';
        $osName     = 'Linux';
    } elseif ($ua !== '') {
        $deviceType = 'other';
    }

    $browser = 'Other';
    if (str_contains($lower, 'samsungbrowser')) {
        $browser = 'Samsung Internet';
    } elseif (str_contains($lower, 'edg/')) {
        $browser = 'Edge';
    } elseif (str_contains($lower, 'firefox')) {
        $browser = 'Firefox';
    } elseif (str_contains($lower, 'crios')) {
        $browser = 'Chrome (iOS)';
    } elseif (str_contains($lower, 'chrome') && !str_contains($lower, 'edg/')) {
        $browser = 'Chrome';
    } elseif (str_contains($lower, 'safari') && !str_contains($lower, 'chrome')) {
        $browser = 'Safari';
    }

    return [
        'device_type'  => $deviceType,
        'os_name'      => $osName,
        'browser_name' => $browser,
    ];
}

function normalizePwaVisitorKey(string $key): string
{
    $key = strtolower(preg_replace('/[^a-f0-9]/', '', $key) ?? '');

    return strlen($key) >= 16 && strlen($key) <= 64 ? $key : '';
}

function normalizePwaAppContext(string $context): string
{
    $context = strtolower(trim($context));

    return in_array($context, ['staff', 'admin', 'register'], true) ? $context : 'staff';
}

/**
 * @param array<string, mixed> $payload
 * @return array{ok: bool, message: string}
 */
function recordPwaInstallAnalyticsEvent(PDO $pdo, string $eventType, array $payload): array
{
    ensurePwaInstallAnalyticsSchema($pdo);

    $visitorKey = normalizePwaVisitorKey((string) ($payload['visitor_key'] ?? ''));
    if ($visitorKey === '') {
        return ['ok' => false, 'message' => 'Invalid device id.'];
    }

    $eventType = strtolower(trim($eventType));
    $allowed   = ['standalone_open', 'app_installed', 'usage_ping', 'install_help_open'];
    if (!in_array($eventType, $allowed, true)) {
        return ['ok' => false, 'message' => 'Unknown event.'];
    }

    $appContext   = normalizePwaAppContext((string) ($payload['app_context'] ?? 'staff'));
    $ua           = trim((string) ($payload['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')));
    $parsed       = parsePwaUserAgent($ua);
    $displayMode  = trim((string) ($payload['display_mode'] ?? ''));
    $staffEmail   = strtolower(trim((string) ($payload['staff_email'] ?? '')));
    if ($staffEmail !== '' && !filter_var($staffEmail, FILTER_VALIDATE_EMAIL)) {
        $staffEmail = '';
    }

    $markInstalled = in_array($eventType, ['standalone_open', 'app_installed'], true);
    $installMethod = null;
    if ($eventType === 'app_installed') {
        $installMethod = 'native_prompt';
    } elseif ($eventType === 'standalone_open') {
        $installMethod = 'home_screen';
    }

    $stmt = $pdo->prepare(
        'SELECT id, installed FROM pwa_app_devices WHERE visitor_key = :visitor_key AND app_context = :app_context LIMIT 1'
    );
    $stmt->execute(['visitor_key' => $visitorKey, 'app_context' => $appContext]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (is_array($existing)) {
        $sql = 'UPDATE pwa_app_devices SET
            last_seen_at = CURRENT_TIMESTAMP,
            device_type = :device_type,
            os_name = :os_name,
            browser_name = :browser_name,
            display_mode = :display_mode,
            user_agent = :user_agent';
        $params = [
            'device_type'  => $parsed['device_type'],
            'os_name'      => $parsed['os_name'],
            'browser_name' => $parsed['browser_name'],
            'display_mode' => $displayMode !== '' ? $displayMode : null,
            'user_agent'   => $ua !== '' ? mb_substr($ua, 0, 500) : null,
            'id'           => (int) $existing['id'],
        ];
        if ($staffEmail !== '') {
            $sql .= ', staff_email = :staff_email';
            $params['staff_email'] = $staffEmail;
        }
        if ($markInstalled) {
            $sql .= ', installed = 1, install_method = COALESCE(install_method, :install_method), installed_at = COALESCE(installed_at, CURRENT_TIMESTAMP)';
            $params['install_method'] = $installMethod;
        }
        $sql .= ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);

        return ['ok' => true, 'message' => 'Updated.'];
    }

    $pdo->prepare(
        'INSERT INTO pwa_app_devices (
            visitor_key, staff_email, app_context, device_type, os_name, browser_name,
            display_mode, installed, install_method, installed_at, user_agent
        ) VALUES (
            :visitor_key, :staff_email, :app_context, :device_type, :os_name, :browser_name,
            :display_mode, :installed, :install_method, :installed_at, :user_agent
        )'
    )->execute([
        'visitor_key'    => $visitorKey,
        'staff_email'    => $staffEmail !== '' ? $staffEmail : null,
        'app_context'    => $appContext,
        'device_type'    => $parsed['device_type'],
        'os_name'        => $parsed['os_name'],
        'browser_name'   => $parsed['browser_name'],
        'display_mode'   => $displayMode !== '' ? $displayMode : null,
        'installed'      => $markInstalled ? 1 : 0,
        'install_method' => $installMethod,
        'installed_at'   => $markInstalled ? date('Y-m-d H:i:s') : null,
        'user_agent'     => $ua !== '' ? mb_substr($ua, 0, 500) : null,
    ]);

    return ['ok' => true, 'message' => 'Recorded.'];
}

function staffAppVisitLogSqlWhere(): string
{
    return "(site_area = 'staff'
        OR request_path LIKE '%staff-app.php%'
        OR request_path LIKE '%staff-checkin.php%'
        OR request_path LIKE '%staff-shifts.php%'
        OR request_path LIKE '%staff-messages.php%'
        OR request_path LIKE '%staff-notifications.php%'
        OR request_path LIKE '%staff-profile%')";
}

function getStaffAppLaunchDate(PDO $pdo): string
{
    $raw = trim(getSetting($pdo, 'staff_app_launch_date', '2026-05-28'));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
        return '2026-05-28';
    }

    return $raw;
}

/**
 * @return array{sql: string, params: array<string, string>}
 */
function staffAppVisitLogSinceLaunch(PDO $pdo): array
{
    $launch = getStaffAppLaunchDate($pdo);

    return [
        'sql'    => staffAppVisitLogSqlWhere() . ' AND visited_at >= :launch_at',
        'params' => ['launch_at' => $launch . ' 00:00:00'],
    ];
}

/**
 * Historical staff-app usage from website visit logs (before PWA install tracking existed).
 *
 * @return array{
 *     available: bool,
 *     first_visit_at: ?string,
 *     last_visit_at: ?string,
 *     total_page_views: int,
 *     unique_devices: int,
 *     unique_devices_7d: int,
 *     page_views_7d: int,
 *     by_device: list<array{label: string, count: int}>,
 *     launch_date: string
 * }
 */
function getStaffAppHistoricalVisitMetrics(PDO $pdo): array
{
    $launchDate = getStaffAppLaunchDate($pdo);
    $empty = [
        'available'         => false,
        'first_visit_at'    => null,
        'last_visit_at'     => null,
        'total_page_views'  => 0,
        'unique_devices'    => 0,
        'unique_devices_7d' => 0,
        'page_views_7d'     => 0,
        'by_device'         => [],
        'launch_date'       => $launchDate,
    ];

    if (!tableExists($pdo, 'website_visits')) {
        return $empty;
    }

    $since  = staffAppVisitLogSinceLaunch($pdo);
    $where  = $since['sql'];
    $params = $since['params'];

    try {
        $rangeStmt = $pdo->prepare(
            "SELECT MIN(visited_at) AS first_at, MAX(visited_at) AS last_at, COUNT(*) AS total
             FROM website_visits WHERE {$where}"
        );
        $rangeStmt->execute($params);
        $range = $rangeStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $empty;
    }

    if (!$range || (int) ($range['total'] ?? 0) === 0) {
        return $empty;
    }

    $uniqueStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT LOWER(SUBSTRING(SHA1(CONCAT(COALESCE(ip_address, ''), '|', COALESCE(user_agent, ''))), 1, 32)))
         FROM website_visits WHERE {$where}"
    );
    $uniqueStmt->execute($params);
    $unique = (int) $uniqueStmt->fetchColumn();

    $weekStmt = $pdo->prepare(
        "SELECT COUNT(*) AS views,
                COUNT(DISTINCT LOWER(SUBSTRING(SHA1(CONCAT(COALESCE(ip_address, ''), '|', COALESCE(user_agent, ''))), 1, 32))) AS devices
         FROM website_visits
         WHERE {$where} AND visited_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );
    $weekStmt->execute($params);
    $week = $weekStmt->fetch(PDO::FETCH_ASSOC);

    $deviceLabels = [
        'iphone'  => 'iPhone',
        'ipad'    => 'iPad',
        'android' => 'Android',
        'desktop' => 'Desktop',
        'other'   => 'Other',
        'unknown' => 'Unknown',
    ];

    $byDevice = [];
    $deviceStmt = $pdo->prepare(
        "SELECT user_agent, COUNT(*) AS cnt FROM website_visits WHERE {$where} GROUP BY user_agent"
    );
    $deviceStmt->execute($params);
    $rows = $deviceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $deviceCounts = [];
    foreach ($rows as $row) {
        $parsed = parsePwaUserAgent((string) ($row['user_agent'] ?? ''));
        $type   = (string) ($parsed['device_type'] ?? 'unknown');
        $deviceCounts[$type] = ($deviceCounts[$type] ?? 0) + (int) ($row['cnt'] ?? 0);
    }
    arsort($deviceCounts);
    foreach ($deviceCounts as $type => $cnt) {
        $byDevice[] = [
            'label' => $deviceLabels[$type] ?? ucfirst($type),
            'count' => $cnt,
        ];
    }

    return [
        'available'         => true,
        'first_visit_at'    => $range['first_at'] ? (string) $range['first_at'] : null,
        'last_visit_at'     => $range['last_at'] ? (string) $range['last_at'] : null,
        'total_page_views'  => (int) ($range['total'] ?? 0),
        'unique_devices'    => $unique,
        'unique_devices_7d' => (int) ($week['devices'] ?? 0),
        'page_views_7d'     => (int) ($week['views'] ?? 0),
        'by_device'         => $byDevice,
        'launch_date'       => $launchDate,
    ];
}

/**
 * Import historical staff-app visit logs into pwa_app_devices (browser-only rows).
 *
 * @return array{inserted: int, updated: int, skipped: int, since: ?string}
 */
function backfillPwaDevicesFromStaffAppVisits(PDO $pdo, string $appContext = 'staff'): array
{
    ensurePwaInstallAnalyticsSchema($pdo);

    $stats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'since' => null];
    if (!tableExists($pdo, 'website_visits')) {
        return $stats;
    }

    $since  = staffAppVisitLogSinceLaunch($pdo);
    $where  = $since['sql'];
    $params = $since['params'];
    $ctx    = normalizePwaAppContext($appContext);

    $importStmt = $pdo->prepare(
        "SELECT LOWER(SUBSTRING(SHA1(CONCAT(COALESCE(ip_address, ''), '|', COALESCE(user_agent, ''))), 1, 32)) AS visitor_key,
                MIN(visited_at) AS first_seen_at,
                MAX(visited_at) AS last_seen_at,
                MAX(user_agent) AS user_agent
         FROM website_visits
         WHERE {$where}
         GROUP BY visitor_key
         HAVING visitor_key IS NOT NULL AND LENGTH(visitor_key) >= 16"
    );
    $importStmt->execute($params);
    $rows = $importStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($rows === []) {
        return $stats;
    }

    foreach ($rows as $row) {
        $first = (string) ($row['first_seen_at'] ?? '');
        if ($first === '') {
            continue;
        }
        if ($stats['since'] === null || $first < $stats['since']) {
            $stats['since'] = $first;
        }
    }

    $selectStmt = $pdo->prepare(
        'SELECT id, first_seen_at, last_seen_at FROM pwa_app_devices WHERE visitor_key = :visitor_key AND app_context = :ctx LIMIT 1'
    );
    $insertStmt = $pdo->prepare(
        'INSERT INTO pwa_app_devices (
            visitor_key, app_context, device_type, os_name, browser_name,
            display_mode, installed, install_method, first_seen_at, last_seen_at, installed_at, user_agent
        ) VALUES (
            :visitor_key, :ctx, :device_type, :os_name, :browser_name,
            :display_mode, 0, :install_method, :first_seen_at, :last_seen_at, NULL, :user_agent
        )'
    );
    $updateStmt = $pdo->prepare(
        'UPDATE pwa_app_devices SET
            first_seen_at = LEAST(first_seen_at, :first_seen_at),
            last_seen_at = GREATEST(last_seen_at, :last_seen_at),
            device_type = :device_type,
            os_name = :os_name,
            browser_name = :browser_name,
            user_agent = COALESCE(:user_agent, user_agent)
         WHERE id = :id'
    );

    foreach ($rows as $row) {
        $visitorKey = normalizePwaVisitorKey((string) ($row['visitor_key'] ?? ''));
        if ($visitorKey === '') {
            $stats['skipped']++;
            continue;
        }

        $ua     = (string) ($row['user_agent'] ?? '');
        $parsed = parsePwaUserAgent($ua);
        $first  = (string) ($row['first_seen_at'] ?? date('Y-m-d H:i:s'));
        $last   = (string) ($row['last_seen_at'] ?? $first);

        $selectStmt->execute(['visitor_key' => $visitorKey, 'ctx' => $ctx]);
        $existing = $selectStmt->fetch(PDO::FETCH_ASSOC);

        if (is_array($existing)) {
            $updateStmt->execute([
                'first_seen_at' => $first,
                'last_seen_at'  => $last,
                'device_type'   => $parsed['device_type'],
                'os_name'       => $parsed['os_name'],
                'browser_name'  => $parsed['browser_name'],
                'user_agent'    => $ua !== '' ? mb_substr($ua, 0, 500) : null,
                'id'            => (int) $existing['id'],
            ]);
            $stats['updated']++;
            continue;
        }

        try {
            $insertStmt->execute([
                'visitor_key'    => $visitorKey,
                'ctx'            => $ctx,
                'device_type'    => $parsed['device_type'],
                'os_name'        => $parsed['os_name'],
                'browser_name'   => $parsed['browser_name'],
                'display_mode'   => 'browser',
                'install_method' => 'visit_log_backfill',
                'first_seen_at'  => $first,
                'last_seen_at'   => $last,
                'user_agent'     => $ua !== '' ? mb_substr($ua, 0, 500) : null,
            ]);
            $stats['inserted']++;
        } catch (Throwable $e) {
            $stats['skipped']++;
        }
    }

    return $stats;
}

function maybeBackfillPwaDevicesFromStaffAppVisits(PDO $pdo): void
{
    $launchDate = getStaffAppLaunchDate($pdo);
    $doneKey    = 'pwa_visit_backfill_v2_' . $launchDate;
    if (getSetting($pdo, $doneKey, '0') === '1') {
        return;
    }

    $historical = getStaffAppHistoricalVisitMetrics($pdo);
    if (empty($historical['available'])) {
        setSetting($pdo, $doneKey, '1');

        return;
    }

    backfillPwaDevicesFromStaffAppVisits($pdo, 'staff');
    setSetting($pdo, $doneKey, '1');
}

/**
 * @return array{
 *     available: bool,
 *     installed_total: int,
 *     installed_week: int,
 *     active_installed_week: int,
 *     browser_users_week: int,
 *     install_rate: float,
 *     devices: list<array{label: string, count: int}>,
 *     browsers: list<array{label: string, count: int}>,
 *     recent: list<array<string, mixed>>,
 *     historical: array<string, mixed>
 * }
 */
function getPwaInstallDashboardMetrics(PDO $pdo, string $appContext = 'staff'): array
{
    ensurePwaInstallAnalyticsSchema($pdo);

    maybeBackfillPwaDevicesFromStaffAppVisits($pdo);

    $defaults = [
        'available'             => false,
        'installed_total'       => 0,
        'installed_week'        => 0,
        'active_installed_week' => 0,
        'browser_users_week'    => 0,
        'install_rate'          => 0.0,
        'devices'               => [],
        'browsers'              => [],
        'recent'                => [],
        'historical'            => getStaffAppHistoricalVisitMetrics($pdo),
    ];

    try {
        $pdo->query('SELECT 1 FROM pwa_app_devices LIMIT 1');
        $defaults['available'] = true;
    } catch (Throwable $e) {
        return $defaults;
    }

    $ctx = normalizePwaAppContext($appContext);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM pwa_app_devices WHERE app_context = :ctx AND installed = 1');
    $stmt->execute(['ctx' => $ctx]);
    $installedTotal = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM pwa_app_devices WHERE app_context = :ctx AND installed = 1 AND installed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
    );
    $stmt->execute(['ctx' => $ctx]);
    $installedWeek = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM pwa_app_devices WHERE app_context = :ctx AND installed = 1 AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
    );
    $stmt->execute(['ctx' => $ctx]);
    $activeInstalledWeek = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM pwa_app_devices WHERE app_context = :ctx AND installed = 0 AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
    );
    $stmt->execute(['ctx' => $ctx]);
    $browserUsersWeek = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM pwa_app_devices WHERE app_context = :ctx AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
    );
    $stmt->execute(['ctx' => $ctx]);
    $visitorsWeek = (int) $stmt->fetchColumn();

    $installRate = $visitorsWeek > 0
        ? round(($activeInstalledWeek / $visitorsWeek) * 100, 1)
        : 0.0;

    $deviceLabels = [
        'iphone'  => 'iPhone',
        'ipad'    => 'iPad',
        'android' => 'Android',
        'desktop' => 'Desktop',
        'other'   => 'Other',
        'unknown' => 'Unknown',
    ];

    $stmt = $pdo->prepare(
        'SELECT device_type, COUNT(*) AS cnt FROM pwa_app_devices
         WHERE app_context = :ctx AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY device_type ORDER BY cnt DESC'
    );
    $stmt->execute(['ctx' => $ctx]);
    $devices = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $type = (string) ($row['device_type'] ?? 'unknown');
        $devices[] = [
            'label' => $deviceLabels[$type] ?? ucfirst($type),
            'count' => (int) ($row['cnt'] ?? 0),
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT browser_name, COUNT(*) AS cnt FROM pwa_app_devices
         WHERE app_context = :ctx AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY browser_name ORDER BY cnt DESC LIMIT 8'
    );
    $stmt->execute(['ctx' => $ctx]);
    $browsers = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $browsers[] = [
            'label' => (string) ($row['browser_name'] ?? 'Other'),
            'count' => (int) ($row['cnt'] ?? 0),
        ];
    }

    $stmt = $pdo->prepare(
        'SELECT staff_email, device_type, os_name, browser_name, installed_at, last_seen_at
         FROM pwa_app_devices
         WHERE app_context = :ctx AND installed = 1
         ORDER BY installed_at DESC LIMIT 8'
    );
    $stmt->execute(['ctx' => $ctx]);
    $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'available'             => true,
        'installed_total'       => $installedTotal,
        'installed_week'        => $installedWeek,
        'active_installed_week' => $activeInstalledWeek,
        'browser_users_week'    => $browserUsersWeek,
        'install_rate'          => $installRate,
        'devices'               => $devices,
        'browsers'              => $browsers,
        'recent'                => is_array($recent) ? $recent : [],
        'historical'            => getStaffAppHistoricalVisitMetrics($pdo),
    ];
}
