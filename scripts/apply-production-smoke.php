<?php
declare(strict_types=1);

$base = 'https://apply.olasentra.com/';
$root = dirname(__DIR__);
$failures = [];
$warnings = [];
$ok = 0;

$paths = [
    '',
    'admin/',
    'admin/index.php',
    'admin/login.php',
    'admin/admin/login.php',
    'admin/admin/dashboard.php',
    'admin/admin/payroll.php',
    'admin/admin/staff-list.php',
    'admin/admin/applicants.php',
    'admin/admin/view-staff.php',
    'admin/admin/edit-staff.php',
    'admin/admin/psa-compliance.php',
    'admin/admin/sync-sheets.php',
    'admin/admin/settings.php',
    'admin/admin/import-applicants.php',
    'admin/admin/auto-sync.php',
    'admin/api/check-staff.php',
    'admin/assets/css/secure-admin.css',
    'admin/assets/css/global.css',
];

foreach (glob($root . '/apply/admin/**/*.php') ?: [] as $file) {
    $rel = str_replace('\\', '/', substr($file, strlen($root . '/apply/')));
    if (!str_contains($rel, '/admin/admin/') && !str_contains($rel, '/admin/api/')) {
        continue;
    }
    if (str_ends_with($rel, '-action.php')) {
        continue;
    }
    $paths[] = $rel;
}
$paths = array_values(array_unique($paths));

foreach ($paths as $path) {
    $url = $base . ltrim($path, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err !== '') {
        $failures[] = "$path — curl: $err";
        continue;
    }
    if ($code >= 500) {
        $snippet = trim(preg_replace('/\s+/', ' ', strip_tags(substr($body, 0, 500))));
        $failures[] = "$path — HTTP $code" . ($snippet !== '' ? " — $snippet" : '');
        continue;
    }
    if ($code === 0) {
        $failures[] = "$path — no HTTP code";
        continue;
    }
    if (preg_match('/fatal error|parse error|uncaught error|failed opening required/i', $body)) {
        $failures[] = "$path — HTTP $code but PHP error in body";
        continue;
    }
    if (in_array($code, [301, 302, 303, 307, 308], true)) {
        $ok++;
        continue;
    }
    if (str_ends_with($path, '.css') && $code !== 200) {
        $warnings[] = "$path — HTTP $code";
    }
    $ok++;
}

echo "Apply production smoke test\n";
echo "Base: $base\n";
echo str_repeat('=', 40) . "\n";
echo "Checked: " . count($paths) . " URLs\n";
echo "OK: $ok\n";
echo "Failures: " . count($failures) . "\n";
echo "Warnings: " . count($warnings) . "\n\n";

if ($failures !== []) {
    echo "FAILURES:\n";
    foreach ($failures as $f) {
        echo "  ✗ $f\n";
    }
}
if ($warnings !== []) {
    echo "\nWARNINGS:\n";
    foreach ($warnings as $w) {
        echo "  ! $w\n";
    }
}

exit($failures === [] ? 0 : 1);
