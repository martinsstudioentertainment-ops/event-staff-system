<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$generated = gmdate('Y-m-d H:i:s') . ' UTC';

$portals = [
    'Admin' => [
        'base' => 'https://admin.olasentra.com/',
        'paths' => [
            'admin/login.php',
            'admin/dashboard.php',
            'admin/system-health.php',
            'admin/go-live.php',
            'admin/staff.php',
            'assets/css/admin.css',
            'assets/css/admin-v3.css',
            'assets/js/admin.js',
        ],
    ],
    'Apply' => [
        'base' => 'https://apply.olasentra.com/',
        'paths' => [
            '',
            'admin/index.php',
            'admin/admin/login.php',
            'admin/admin/dashboard.php',
            'admin/admin/payroll.php',
            'admin/admin/staff-list.php',
            'admin/admin/psa-compliance.php',
            'admin/admin/settings.php',
            'admin/assets/css/secure-admin.css',
        ],
    ],
    'Registration' => [
        'base' => 'https://register.olasentra.com/',
        'paths' => ['', 'index.php'],
    ],
    'Staff' => [
        'base' => 'https://admin.olasentra.com/',
        'paths' => ['staff-portal.php', 'staff-app.php'],
    ],
];

$fixes = [
    'Apply HTTP 500: auth.php required /home/olastofx/includes/app-environment.php (wrong path on Apply host)',
    'Added apply_require_app_environment() with main ERP + local shim fallback',
    'Fixed session-idle-timeout.js path on Apply secure layout',
    'Fixed phone-numbers.php load on edit-staff.php',
    'Admin system-health: missing attendance-gps-phase1.php include (prior sprint)',
    'Admin V3: readiness checklist contrast + table/detail readability CSS',
];

$risks = [
    'Apply payroll export requires Google Sheets credentials on server — verify after login',
    'cPanel cron schedules not stored in-app — operator must confirm',
    'GPS flag should stay OFF unless piloting',
];

$results = [];
$failCount = 0;
$passCount = 0;

foreach ($portals as $name => $cfg) {
    foreach ($cfg['paths'] as $path) {
        $url = $cfg['base'] . ltrim($path, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $phpErr = preg_match('/fatal error|parse error|uncaught error|failed opening required/i', $body) === 1;
        $ok = $code > 0 && $code < 500 && !$phpErr;
        if ($ok) {
            $passCount++;
        } else {
            $failCount++;
        }

        $results[] = [
            'portal' => $name,
            'path'   => $path ?: '/',
            'code'   => $code,
            'ok'     => $ok,
            'note'   => $phpErr ? 'PHP error in body' : ($code >= 500 ? 'Server error' : ''),
        ];
    }
}

$total = max(1, $passCount + $failCount);
$score = (int) round(($passCount / $total) * 100);

$rowsHtml = '';
foreach ($results as $r) {
    $status = $r['ok'] ? 'pass' : 'fail';
    $rowsHtml .= sprintf(
        '<tr class="%s"><td>%s</td><td><code>%s</code></td><td>%d</td><td>%s</td><td>%s</td></tr>',
        $status,
        htmlspecialchars($r['portal'], ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($r['path'], ENT_QUOTES, 'UTF-8'),
        $r['code'],
        $r['ok'] ? 'OK' : 'FAIL',
        htmlspecialchars($r['note'], ENT_QUOTES, 'UTF-8')
    );
}

$fixesHtml = '';
foreach ($fixes as $f) {
    $fixesHtml .= '<li>' . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') . '</li>';
}

$risksHtml = '';
foreach ($risks as $r) {
    $risksHtml .= '<li>' . htmlspecialchars($r, ENT_QUOTES, 'UTF-8') . '</li>';
}

$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Platform Health Report — Sprint 5</title>
<style>
body{font-family:system-ui,sans-serif;margin:2rem;background:#0f172a;color:#e2e8f0;line-height:1.5}
h1,h2{color:#f8fafc}
.card{background:#1e293b;border:1px solid rgba(148,163,184,.2);border-radius:12px;padding:1.25rem;margin:1rem 0}
.score{font-size:2.5rem;font-weight:700;color:#4ade80}
table{width:100%;border-collapse:collapse;font-size:.9rem}
th,td{padding:.5rem .65rem;border-bottom:1px solid rgba(148,163,184,.15);text-align:left}
th{color:#94a3b8;font-size:.75rem;text-transform:uppercase}
tr.pass td:nth-child(5){color:#4ade80}
tr.fail td:nth-child(5){color:#f87171}
code{background:rgba(0,0,0,.25);padding:.1rem .35rem;border-radius:4px}
ul{margin:.5rem 0;padding-left:1.25rem}
.meta{color:#94a3b8;font-size:.85rem}
</style>
</head>
<body>
<h1>Platform Health Report</h1>
<p class="meta">Sprint 5 — Critical fixes + Apply portal recovery · Generated {$generated}</p>

<div class="card">
<h2>Readiness score</h2>
<p class="score">{$score}%</p>
<p>{$passCount} passing · {$failCount} failing · {$total} checks</p>
</div>

<div class="card">
<h2>Verification results</h2>
<table>
<thead><tr><th>Portal</th><th>Path</th><th>HTTP</th><th>Result</th><th>Note</th></tr></thead>
<tbody>{$rowsHtml}</tbody>
</table>
<p class="meta">302/401 on protected admin pages without session is expected and counted OK when no PHP fatal in body.</p>
</div>

<div class="card">
<h2>Root cause — Apply HTTP 500</h2>
<p><code>apply/admin/includes/auth.php</code> used <code>dirname(__DIR__, 3) . '/includes/app-environment.php'</code>, resolving to <code>/home/olastofx/includes/app-environment.php</code> on production. That file lives under <code>public_html/includes/</code> on the main host, not on the Apply document root — causing fatal errors on every authenticated Apply page.</p>
</div>

<div class="card">
<h2>Fixes applied</h2>
<ul>{$fixesHtml}</ul>
</div>

<div class="card">
<h2>Remaining risks / blockers</h2>
<ul>{$risksHtml}</ul>
</div>

<div class="card">
<h2>Screenshots</h2>
<p class="meta">Automated HTTP verification used (no browser session). Re-check Apply dashboard and payroll after signing in at <a href="https://apply.olasentra.com/admin/admin/login.php" style="color:#818cf8">Apply login</a>.</p>
</div>
</body>
</html>
HTML;

$out = $root . '/docs/PLATFORM-HEALTH-REPORT.html';
file_put_contents($out, $html);
echo "Wrote $out\nScore: $score%\nPass: $passCount Fail: $failCount\n";
exit($failCount > 0 ? 1 : 0);
