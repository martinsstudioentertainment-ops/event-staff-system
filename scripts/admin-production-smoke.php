<?php
/**
 * Production admin smoke test — every sidebar page must not return HTTP 500.
 * Unauthenticated requests should redirect (302/303) or return 200 (login), never 500.
 *
 * Run: php scripts/admin-production-smoke.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-capabilities.php';

$base = 'https://admin.olasentra.com/admin/';
$failures = [];
$ok = 0;

$urls = ['login.php', 'index.php'];
foreach (getAdminSidebarSections() as $section) {
    foreach ($section['items'] as $item) {
        $urls[] = strtok($item['url'], '?');
    }
}
$adminDir = dirname(__DIR__) . '/admin';
foreach (glob($adminDir . '/*.php') ?: [] as $file) {
    $name = basename($file);
    if (preg_match('/^(go-live-action|backup-|bulk-|checkin-action|scan-checkin-action|staff-delete|user-action|update-status|blacklist-action|invoice-action|job-record-action|ops-checklist-action|venue-action|work-hours-action|events-sheets-action|events-roster-action|google-drive-oauth-callback|save-|toggle-|export-|import-|print-|invoice-import-action)$/i', $name)) {
        continue;
    }
    if (str_ends_with($name, '-action.php')) {
        continue;
    }
    $urls[] = $name;
}
$urls = array_values(array_unique(array_filter($urls)));

$caBundle = dirname(__DIR__) . '/cacert.pem';
$verifySsl = is_file($caBundle);

foreach ($urls as $path) {
    $url = $base . ltrim($path, '/');
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        CURLOPT_HEADER         => true,
        CURLOPT_NOBODY         => false,
    ];
    if ($verifySsl) {
        $opts[CURLOPT_CAINFO] = $caBundle;
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err !== '') {
        $failures[] = "$path — curl error: $err";
        continue;
    }

    if ($code >= 500) {
        $failures[] = "$path — HTTP $code";
        continue;
    }

    if ($code === 0) {
        $failures[] = "$path — no HTTP code";
        continue;
    }

    $ok++;
}

echo "Admin production smoke test\n";
echo "Base: $base\n";
echo str_repeat('=', 40) . "\n";
echo "Checked: " . count($urls) . " URLs\n";
echo "OK (non-5xx): $ok\n";
echo "Failures: " . count($failures) . "\n\n";

if ($failures !== []) {
    foreach ($failures as $f) {
        echo "  ✗ $f\n";
    }
    exit(1);
}

echo "All pages reachable (no HTTP 500).\n";
exit(0);
