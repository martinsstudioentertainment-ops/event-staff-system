<?php
/**
 * Production staff portal smoke test — key pages must not return HTTP 500.
 * Unauthenticated requests should redirect (302) or return 200 (sign-in), never 500.
 *
 * Run: php scripts/staff-production-smoke.php
 */
declare(strict_types=1);

$base = 'https://register.olasentra.com/';
$failures = [];
$ok = 0;

$paths = [
    'staff-app.php',
    'staff-shifts.php',
    'staff-profile.php',
    'staff-profile-hub.php',
    'staff-documents.php',
    'staff-availability.php',
    'staff-notifications.php',
    'staff-messages.php',
    'staff-checkin.php',
    'staff-certificates.php',
    'staff-settings.php',
    'portal/staff-dashboard.php',
    'portal/staff-dashboard.php?tab=availability',
    'portal/staff-dashboard.php?tab=documents',
    'index.php',
    'submit.php',
    'api/registrant-lookup.php?email=smoke@test.invalid',
    'cron/probe-profile-only-registration.php?key=email-encoding-verify-20260606&email=smoke@test.invalid',
];

$caBundle = dirname(__DIR__) . '/cacert.pem';
$verifySsl = is_file($caBundle);

foreach ($paths as $path) {
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
        $bodySnippet = is_string($response) ? substr(strip_tags($response), 0, 200) : '';
        $failures[] = "$path — HTTP $code" . ($bodySnippet !== '' ? " — $bodySnippet" : '');
        continue;
    }

    if ($code === 0) {
        $failures[] = "$path — no HTTP code";
        continue;
    }

    if (is_string($response) && preg_match('/Fatal error|Parse error|Uncaught Error/i', $response)) {
        $failures[] = "$path — PHP error in body (HTTP $code)";
        continue;
    }

    $ok++;
}

echo "Staff production smoke test\n";
echo "Base: $base\n";
echo str_repeat('=', 40) . "\n";
echo "Checked: " . count($paths) . " URLs\n";
echo "OK (non-5xx, no PHP fatal in body): $ok\n";
echo "Failures: " . count($failures) . "\n\n";

if ($failures !== []) {
    foreach ($failures as $f) {
        echo "  ✗ $f\n";
    }
    exit(1);
}

echo "All staff portal pages reachable (no HTTP 500).\n";
exit(0);
