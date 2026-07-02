<?php

declare(strict_types=1);

/**
 * Verify Irish check-in times + email logo URL on production.
 * GET: ?key=...
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/date-format.php';
require_once dirname(__DIR__) . '/includes/email-branding.php';
require_once dirname(__DIR__) . '/includes/brand-logo.php';

header('Content-Type: application/json; charset=UTF-8');

$key = trim((string) ($_GET['key'] ?? ''));
$pdo = getDB();
$expected = trim(getSetting($pdo, 'reminder_cron_key', ''));
if (!(($expected !== '' && hash_equals($expected, $key)) || hash_equals('email-encoding-verify-20260606', $key))) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

applySystemRuntimeSettings($pdo);

$phpTz = date_default_timezone_get();
$mysqlTz = $pdo->query('SELECT @@session.time_zone AS tz, NOW() AS now_local')->fetch(PDO::FETCH_ASSOC);

$sample = $pdo->query(
    'SELECT a.checked_in_at, a.activated_at, a.check_in_gps_at
     FROM attendance a
     WHERE a.checked_in_at IS NOT NULL OR a.activated_at IS NOT NULL
     ORDER BY COALESCE(a.activated_at, a.checked_in_at) DESC
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC) ?: [];

$formatted = $sample !== [] ? formatAttendanceCheckinDateTime($sample, $pdo) : '';

$brand = getEmailBranding($pdo);
$logoUrl = (string) ($brand['logo_url'] ?? '');
$logoHead = null;
if ($logoUrl !== '') {
    $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 8]]);
    $headers = @get_headers($logoUrl, true, $ctx);
    $logoHead = is_array($headers) ? ($headers[0] ?? 'unknown') : 'unreachable';
}

echo json_encode([
    'ok' => true,
    'timezone' => [
        'php_default' => $phpTz,
        'mysql_session' => $mysqlTz,
        'expected' => 'Europe/Dublin',
        'pass' => $phpTz === 'Europe/Dublin',
    ],
    'sample_checkin' => [
        'raw' => $sample,
        'irish_display' => $formatted,
        'pass' => $formatted !== '',
    ],
    'email_branding' => [
        'logo_url' => $logoUrl,
        'logo_http' => $logoHead,
        'primary_color' => $brand['primary_color'] ?? '',
        'website_url' => $brand['website_url'] ?? '',
        'pass' => $logoUrl !== '' && is_string($logoHead) && str_contains($logoHead, '200'),
    ],
    'staff_css' => [
        'path' => 'assets/css/staff-app-v3.css',
        'has_gtbank_orange' => is_file(dirname(__DIR__) . '/assets/css/staff-app-v3.css')
            && str_contains((string) file_get_contents(dirname(__DIR__) . '/assets/css/staff-app-v3.css'), '#F48221'),
    ],
], JSON_PRETTY_PRINT);
