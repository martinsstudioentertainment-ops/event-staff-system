<?php

/**
 * Log public website visits with IP-based location (country / region / city).
 */

require_once __DIR__ . '/production-readiness.php';

function ensureWebsiteVisitSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    if (!tableExists($pdo, 'website_visits')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS website_visits (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                visited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                site_area VARCHAR(20) NOT NULL DEFAULT 'marketing',
                request_path VARCHAR(500) NOT NULL DEFAULT '/',
                http_host VARCHAR(120) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(500) NULL,
                referrer VARCHAR(500) NULL,
                country VARCHAR(80) NULL,
                region VARCHAR(120) NULL,
                city VARCHAR(120) NULL,
                visitor_key CHAR(40) NULL,
                INDEX idx_visited_at (visited_at DESC),
                INDEX idx_site_area (site_area),
                INDEX idx_country (country),
                INDEX idx_visitor_key (visitor_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    if (!tableExists($pdo, 'ip_geo_cache')) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS ip_geo_cache (
                ip_address VARCHAR(45) NOT NULL PRIMARY KEY,
                country VARCHAR(80) NULL,
                region VARCHAR(120) NULL,
                city VARCHAR(120) NULL,
                looked_up_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    $ready = true;
}

function getClientIpAddress(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            continue;
        }
        if (str_contains($raw, ',')) {
            $raw = trim(explode(',', $raw)[0]);
        }
        if (filter_var($raw, FILTER_VALIDATE_IP)) {
            return $raw;
        }
    }

    return '';
}

function shouldTrackWebsiteVisit(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        return false;
    }

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (str_starts_with($host, 'admin.')) {
        return false;
    }

    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (preg_match('#^/(admin|api|cron|vendor|includes|storage|database|scripts)(/|$)#i', $uri)) {
        return false;
    }

    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, ['manifest.php', 'offline.php'], true)) {
        return false;
    }

    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|HeadlessChrome/i', $ua)) {
        return false;
    }

    return true;
}

function detectWebsiteVisitArea(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');

    if (str_starts_with($host, 'register.')) {
        if (preg_match('#^/(staff-|status\.php|check-in\.php)#i', $path)) {
            return 'staff';
        }

        return 'registration';
    }

    return 'marketing';
}

/**
 * @return array{country: string, region: string, city: string}
 */
function lookupIpGeo(PDO $pdo, string $ip): array
{
    $empty = ['country' => '', 'region' => '', 'city' => ''];

    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return $empty;
    }

    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return ['country' => '', 'region' => '', 'city' => 'Local network'];
    }

    ensureWebsiteVisitSchema($pdo);

    $stmt = $pdo->prepare('SELECT country, region, city FROM ip_geo_cache WHERE ip_address = :ip LIMIT 1');
    $stmt->execute(['ip' => $ip]);
    $cached = $stmt->fetch();
    if ($cached) {
        return [
            'country' => (string) ($cached['country'] ?? ''),
            'region'  => (string) ($cached['region'] ?? ''),
            'city'    => (string) ($cached['city'] ?? ''),
        ];
    }

    $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,country,regionName,city';
    $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    $json = @file_get_contents($url, false, $ctx);
    if ($json === false || $json === '') {
        return $empty;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
        return $empty;
    }

    $geo = [
        'country' => trim((string) ($data['country'] ?? '')),
        'region'  => trim((string) ($data['regionName'] ?? '')),
        'city'    => trim((string) ($data['city'] ?? '')),
    ];

    $ins = $pdo->prepare(
        'INSERT INTO ip_geo_cache (ip_address, country, region, city) VALUES (:ip, :country, :region, :city)
         ON DUPLICATE KEY UPDATE country = VALUES(country), region = VALUES(region), city = VALUES(city)'
    );
    $ins->execute([
        'ip'      => $ip,
        'country' => $geo['country'],
        'region'  => $geo['region'],
        'city'    => $geo['city'],
    ]);

    return $geo;
}

function trackWebsiteVisit(): void
{
    if (!shouldTrackWebsiteVisit()) {
        return;
    }

    try {
        $pdo = getDB();
    } catch (Throwable $e) {
        return;
    }

    ensureWebsiteVisitSchema($pdo);

    $ip   = getClientIpAddress();
    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
    $path = substr($path, 0, 500);
    $ua   = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $ref  = substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500);
    $host = substr((string) ($_SERVER['HTTP_HOST'] ?? ''), 0, 120);
    $area = detectWebsiteVisitArea();
    $day  = date('Y-m-d');
    $visitorKey = $ip !== '' ? sha1(strtolower($ip) . '|' . $ua . '|' . $day) : null;

    $geo = lookupIpGeo($pdo, $ip);

    $stmt = $pdo->prepare(
        'INSERT INTO website_visits (
            site_area, request_path, http_host, ip_address, user_agent, referrer,
            country, region, city, visitor_key
        ) VALUES (
            :site_area, :request_path, :http_host, :ip_address, :user_agent, :referrer,
            :country, :region, :city, :visitor_key
        )'
    );
    $stmt->execute([
        'site_area'    => $area,
        'request_path' => $path,
        'http_host'    => $host,
        'ip_address'   => $ip !== '' ? $ip : null,
        'user_agent'   => $ua !== '' ? $ua : null,
        'referrer'     => $ref !== '' ? $ref : null,
        'country'      => $geo['country'] !== '' ? $geo['country'] : null,
        'region'       => $geo['region'] !== '' ? $geo['region'] : null,
        'city'         => $geo['city'] !== '' ? $geo['city'] : null,
        'visitor_key'  => $visitorKey,
    ]);
}

/**
 * @return array{where: string, params: array<string, mixed>}
 */
function buildWebsiteVisitFilters(array $filters): array
{
    $where  = ['1=1'];
    $params = [];

    if (!empty($filters['site_area']) && in_array($filters['site_area'], ['marketing', 'registration', 'staff'], true)) {
        $where[] = 'site_area = :site_area';
        $params['site_area'] = $filters['site_area'];
    }

    if (!empty($filters['country'])) {
        $where[] = 'country LIKE :country';
        $params['country'] = '%' . $filters['country'] . '%';
    }

    if (!empty($filters['q'])) {
        $where[] = '(ip_address LIKE :q OR city LIKE :q OR region LIKE :q OR request_path LIKE :q OR referrer LIKE :q)';
        $params['q'] = '%' . $filters['q'] . '%';
    }

    if (!empty($filters['from'])) {
        $where[] = 'visited_at >= :from_date';
        $params['from_date'] = $filters['from'] . ' 00:00:00';
    }

    if (!empty($filters['to'])) {
        $where[] = 'visited_at <= :to_date';
        $params['to_date'] = $filters['to'] . ' 23:59:59';
    }

    return [implode(' AND ', $where), $params];
}

function countWebsiteVisits(PDO $pdo, array $filters = []): int
{
    ensureWebsiteVisitSchema($pdo);
    [$where, $params] = buildWebsiteVisitFilters($filters);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM website_visits WHERE {$where}");
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

/**
 * @return array<int, array<string, mixed>>
 */
function getWebsiteVisits(PDO $pdo, array $filters = [], ?int $limit = null, int $offset = 0): array
{
    ensureWebsiteVisitSchema($pdo);
    [$where, $params] = buildWebsiteVisitFilters($filters);

    $sql = "SELECT * FROM website_visits WHERE {$where} ORDER BY visited_at DESC, id DESC";
    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
}

/**
 * @return array{total: int, unique_today: int, top_countries: array<int, array{country: string, visits: int}>}
 */
function getWebsiteVisitSummary(PDO $pdo, array $filters = []): array
{
    ensureWebsiteVisitSchema($pdo);
    [$where, $params] = buildWebsiteVisitFilters($filters);

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM website_visits WHERE {$where}");
    $totalStmt->execute($params);
    $total = (int) $totalStmt->fetchColumn();

    $uniqueStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT visitor_key) FROM website_visits
         WHERE {$where} AND visitor_key IS NOT NULL AND DATE(visited_at) = CURDATE()"
    );
    $uniqueStmt->execute($params);
    $uniqueToday = (int) $uniqueStmt->fetchColumn();

    $topStmt = $pdo->prepare(
        "SELECT COALESCE(NULLIF(country, ''), 'Unknown') AS country, COUNT(*) AS visits
         FROM website_visits WHERE {$where}
         GROUP BY COALESCE(NULLIF(country, ''), 'Unknown')
         ORDER BY visits DESC LIMIT 8"
    );
    $topStmt->execute($params);
    $topCountries = $topStmt->fetchAll() ?: [];

    return [
        'total'         => $total,
        'unique_today'  => $uniqueToday,
        'top_countries' => $topCountries,
    ];
}

function formatWebsiteVisitAreaLabel(string $area): string
{
    return match ($area) {
        'registration' => 'Registration',
        'staff'        => 'Staff app',
        default        => 'Marketing site',
    };
}

function formatWebsiteVisitLocation(array $row): string
{
    $parts = array_filter([
        trim((string) ($row['city'] ?? '')),
        trim((string) ($row['region'] ?? '')),
        trim((string) ($row['country'] ?? '')),
    ]);

    return $parts !== [] ? implode(', ', $parts) : 'Unknown';
}
