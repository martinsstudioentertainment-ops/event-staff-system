<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$file = __DIR__ . '/dashboard-recovered.php';
$content = file_get_contents($file);

function apply(string &$content, string $old, string $new, string $label): void
{
    if ($old === '') {
        echo "SKIP empty old: $label\n";
        return;
    }
    $pos = strpos($content, $old);
    if ($pos === false) {
        echo "FAIL: $label\n";
        return;
    }
    $content = substr($content, 0, $pos) . $new . substr($content, $pos + strlen($old));
    echo "OK: $label\n";
}

// 1. pwaMetrics variable
apply(
    $content,
    "\$funnel    = adminCan('dashboard') ? getRegistrationFunnelMetrics(\$pdo) : null;\n\n\$messageThreads",
    "\$funnel    = adminCan('dashboard') ? getRegistrationFunnelMetrics(\$pdo) : null;\n\$pwaMetrics  = adminCan('dashboard') ? getPwaInstallDashboardMetrics(\$pdo, 'staff') : null;\n\n\$messageThreads",
    'pwaMetrics variable'
);

// 2. Unified feature-flag POST handler
$oldPost = <<<'PHP'
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) ($_POST['action'] ?? '') === 'toggle_gps_attendance'
    && verifyCsrf($_POST['csrf_token'] ?? null)
) {
    if (!isAdminSuperUser()) {
        setAdminFlash('error', 'Only administrators can change GPS sign-in.');
        header('Location: dashboard.php');
        exit;
    }

    $enable = !empty($_POST['enabled']) && (string) $_POST['enabled'] === '1';
    setSetting($pdo, 'feature_gps_attendance_v2', $enable ? '1' : '0');
    logAdminAudit(
        $pdo,
        'feature_flags_update',
        'settings',
        null,
        'GPS attendance v2 ' . ($enable ? 'enabled' : 'disabled') . ' from dashboard'
    );
    setAdminFlash(
        'success',
        $enable
            ? 'GPS sign-in ON — 1 km geofence and GPS enforcement active.'
            : 'GPS sign-in OFF — legacy 100 m check-in mode.'
    );
    header('Location: dashboard.php#dash-gps-toggle');
    exit;
}
PHP;
$newPost = <<<'PHP'
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? null)) {
    $postAction = (string) ($_POST['action'] ?? '');

    if ($postAction === 'toggle_gps_attendance') {
        $_POST['action']   = 'toggle_feature_flag';
        $_POST['flag_key'] = 'feature_gps_attendance_v2';
        $postAction        = 'toggle_feature_flag';
    }

    if ($postAction === 'toggle_feature_flag') {
        if (!isAdminSuperUser()) {
            setAdminFlash('error', 'Only administrators can change feature flags.');
            header('Location: dashboard.php');
            exit;
        }

        $flagKey = getFeatureFlagKey((string) ($_POST['flag_key'] ?? ''));
        $result  = toggleDashboardFeatureFlag($pdo, $flagKey);
        if ($result['ok']) {
            logAdminAudit(
                $pdo,
                'feature_flags_update',
                'settings',
                null,
                ($result['message'] ?? 'Flag updated') . ' (dashboard corner)'
            );
            setAdminFlash('success', (string) $result['message']);
        } else {
            setAdminFlash('error', (string) ($result['message'] ?? 'Could not update flag.'));
        }
        header('Location: dashboard.php');
        exit;
    }
}
PHP;
apply($content, $oldPost, $newPost, 'unified POST handler');

// 3. Fix misplaced PWA KPI block in dash-cmd (restore health score pill only)
$misplaced = <<<'HTML'
            <?php if ($pwaMetrics !== null): ?>
            <a href="dashboard.php#dash-pwa-analytics" class="exec-kpi exec-kpi--glass exec-kpi--cat-events exec-kpi--<?= !empty($pwaMetrics['available']) && (int) ($pwaMetrics['installed_total'] ?? 0) > 0 ? 'ok' : '' ?>">
                <span class="exec-kpi__val"><?= (int) ($pwaMetrics['installed_total'] ?? 0) ?></span>
                <span class="exec-kpi__lbl">Staff app installs</span>
            </a>
            <a href="dashboard.php#dash-pwa-analytics" class="exec-kpi exec-kpi--glass exec-kpi--cat-events">
                <span class="exec-kpi__val"><?= (int) ($pwaMetrics['active_installed_week'] ?? 0) ?></span>
                <span class="exec-kpi__lbl">Installed · active 7d</span>
            </a>
            <a href="dashboard.php#dash-pwa-analytics" class="exec-kpi exec-kpi--glass exec-kpi--cat-events">
                <span class="exec-kpi__val"><?= (int) ($pwaMetrics['browser_users_week'] ?? 0) ?></span>
                <span class="exec-kpi__lbl">Browser only · 7d</span>
            </a>
                <?php
                $iphoneCount = 0;
                $androidCount = 0;
                foreach (($pwaMetrics['devices'] ?? []) as $devRow) {
                    if (($devRow['label'] ?? '') === 'iPhone') {
                        $iphoneCount = (int) ($devRow['count'] ?? 0);
                    }
                    if (($devRow['label'] ?? '') === 'Android') {
                        $androidCount = (int) ($devRow['count'] ?? 0);
                    }
                }
                ?>
            <a href="dashboard.php#dash-pwa-analytics" class="exec-kpi exec-kpi--glass exec-kpi--cat-events">
                <span class="exec-kpi__val"><?= $iphoneCount ?></span>
                <span class="exec-kpi__lbl">iPhone · 30d</span>
            </a>
            <a href="dashboard.php#dash-pwa-analytics" class="exec-kpi exec-kpi--glass exec-kpi--cat-events">
                <span class="exec-kpi__val"><?= $androidCount ?></span>
                <span class="exec-kpi__lbl">Android · 30d</span>
            </a>
            <?php endif; ?>
            <?php if ($healthScore !== null): ?>
HTML;
apply($content, $misplaced, '            <?php if ($healthScore !== null): ?>', 'fix dash-cmd PWA misplaced block');

// 4. PWA KPI tiles in exec-kpi row (before open incidents)
$pwaKpiOld = <<<'HTML'
            <a href="dashboard.php#dash-actions-title" class="exec-kpi exec-kpi--glass exec-kpi--cat-incidents exec-kpi--<?= $openIncidents > 0 ? 'warn' : 'ok' ?>">
                <span class="exec-kpi__val"><?= $openIncidents ?></span>
                <span class="exec-kpi__lbl">Open incidents</span>
            </a>
HTML;
$pwaKpiNew = <<<'HTML'
            <?php if ($pwaMetrics !== null): ?>
            <a href="dashboard.php#dash-pwa-analytics" class="exec-kpi exec-kpi--glass exec-kpi--cat-events exec-kpi--<?= !empty($pwaMetrics['available']) && (int) ($pwaMetrics['installed_total'] ?? 0) > 0 ? 'ok' : '' ?>">
                <span class="exec-kpi__val"><?= (int) ($pwaMetrics['installed_total'] ?? 0) ?></span>
                <span class="exec-kpi__lbl">Staff app installs</span>
            </a>
            <a href="dashboard.php#dash-pwa-analytics" class="exec-kpi exec-kpi--glass exec-kpi--cat-events">
                <span class="exec-kpi__val"><?= (int) ($pwaMetrics['active_installed_week'] ?? 0) ?></span>
                <span class="exec-kpi__lbl">Installed · active 7d</span>
            </a>
            <a href="dashboard.php#dash-pwa-analytics" class="exec-kpi exec-kpi--glass exec-kpi--cat-events">
                <span class="exec-kpi__val"><?= (int) ($pwaMetrics['browser_users_week'] ?? 0) ?></span>
                <span class="exec-kpi__lbl">Browser only · 7d</span>
            </a>
                <?php
                $iphoneCount = 0;
                $androidCount = 0;
                foreach (($pwaMetrics['devices'] ?? []) as $devRow) {
                    if (($devRow['label'] ?? '') === 'iPhone') {
                        $iphoneCount = (int) ($devRow['count'] ?? 0);
                    }
                    if (($devRow['label'] ?? '') === 'Android') {
                        $androidCount = (int) ($devRow['count'] ?? 0);
                    }
                }
                ?>
            <a href="dashboard.php#dash-pwa-analytics" class="exec-kpi exec-kpi--glass exec-kpi--cat-events">
                <span class="exec-kpi__val"><?= $iphoneCount ?></span>
                <span class="exec-kpi__lbl">iPhone · 30d</span>
            </a>
            <a href="dashboard.php#dash-pwa-analytics" class="exec-kpi exec-kpi--glass exec-kpi--cat-events">
                <span class="exec-kpi__val"><?= $androidCount ?></span>
                <span class="exec-kpi__lbl">Android · 30d</span>
            </a>
            <?php endif; ?>
            <a href="dashboard.php#dash-actions-title" class="exec-kpi exec-kpi--glass exec-kpi--cat-incidents exec-kpi--<?= $openIncidents > 0 ? 'warn' : 'ok' ?>">
                <span class="exec-kpi__val"><?= $openIncidents ?></span>
                <span class="exec-kpi__lbl">Open incidents</span>
            </a>
HTML;
apply($content, $pwaKpiOld, $pwaKpiNew, 'exec-kpi PWA tiles');

// 5. Remove GPS badge from system health title
apply(
    $content,
    "                            <h2 id=\"dash-health-title\" class=\"dash-panel__title\">System health</h2>\n                            <span class=\"dash-gps dash-gps--<?= \$gpsFlagOn ? 'on' : 'off' ?>\" aria-hidden=\"true\">GPS <?= \$gpsFlagOn ? 'ON' : 'OFF' ?></span>",
    '                            <h2 id="dash-health-title" class="dash-panel__title">System health</h2>',
    'remove GPS from health title'
);

// 6. Feature flag corner in sidebar
$flagCornerOld = <<<'HTML'
            </section>
            <?php endif; ?>
        </aside>
HTML;
$flagCornerNew = <<<'HTML'
            </section>
            <?php endif; ?>

            <?php if ($gpsCanToggle): ?>
            <section class="dash-panel dash-panel--flag-corner" aria-label="Feature flags">
                <div class="dash-panel__head dash-panel__head--tight">
                    <h2 class="dash-panel__title">Feature flags</h2>
                    <a href="feature-flags.php" class="dash-panel__link">All →</a>
                </div>
                <div class="dash-flag-corner">
                    <?php foreach (getDashboardFeatureFlagKeys() as $flagKey):
                        $flagOn    = isDashboardFeatureFlagActive($pdo, $flagKey);
                        $flagLabel = getDashboardFeatureFlagShortLabel($flagKey);
                        $flagState = getDashboardFeatureFlagStatusLabel($pdo, $flagKey);
                        $pillClass = $flagOn ? 'info' : 'muted';
                        if ($flagKey === 'feature_auto_approval' && getAutoApprovalMode($pdo) === 2) {
                            $pillClass = 'ok';
                        }
                        ?>
                    <form method="post" action="dashboard.php" class="dash-flag-corner__form">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="toggle_feature_flag">
                        <input type="hidden" name="flag_key" value="<?= h($flagKey) ?>">
                        <span class="dash-flag-corner__name" title="<?= h($flagKey) ?>"><?= h($flagLabel) ?></span>
                        <button
                            type="submit"
                            class="dash-cmd__pill dash-flag-corner__pill dash-cmd__pill--<?= h($pillClass) ?>"
                            title="<?= h($flagKey) ?> — click to toggle"
                        ><?= h($flagState) ?></button>
                    </form>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>
        </aside>
HTML;
apply($content, $flagCornerOld, $flagCornerNew, 'flag corner sidebar');

// 7. Ops snapshot PHP vars
apply(
    $content,
    "    if (count(\$recentActivity) >= 10) {\n        break;\n    }\n}\n\n\$actionShortages = [];",
    "    if (count(\$recentActivity) >= 10) {\n        break;\n    }\n}\n\n\$pendingApprovalQueue = adminCan('staff') ? getRecentPendingRegistrations(\$pdo, 6) : [];\n\$scheduleSnapshot     = [];\nif (adminCan('events')) {\n    foreach (array_slice(\$upcomingEvents, 0, 6) as \$event) {\n        \$needed   = max(0, (int) (\$event['staff_needed'] ?? 0));\n        \$approved = max(0, (int) (\$event['approved_count'] ?? 0));\n        \$gap      = max(0, (int) (\$event['coverage_gap'] ?? 0));\n        \$eventYmd = substr((string) (\$event['event_date'] ?? ''), 0, 10);\n        \$daysOut  = \$eventYmd !== '' ? max(0, (int) floor((strtotime(\$eventYmd) - strtotime(\$todayYmd)) / 86400)) : 0;\n        \$pct      = \$needed > 0 ? min(100, (int) round((\$approved / \$needed) * 100)) : (\$approved > 0 ? 100 : 0);\n        \$scheduleSnapshot[] = [\n            'id'       => (int) (\$event['id'] ?? 0),\n            'name'     => (string) (\$event['name'] ?? ''),\n            'venue'    => trim((string) (\$event['location'] ?? '')),\n            'date'     => dash_format_date_compact(\$eventYmd),\n            'days_out' => \$daysOut,\n            'needed'   => \$needed,\n            'approved' => \$approved,\n            'gap'      => \$gap,\n            'pct'      => \$pct,\n            'status'   => dash_event_status(\$event, \$todayYmd),\n        ];\n    }\n}\n\n\$actionShortages = [];",
    'ops snapshot PHP vars'
);

// 8. Ops snapshot HTML — load from patches JSON index 23
$patches = json_decode(file_get_contents(__DIR__ . '/dashboard-patches.json'), true);
$opsPatch = $patches[23] ?? null;
if ($opsPatch) {
    apply($content, $opsPatch['old'], $opsPatch['new'], 'ops snapshot HTML');
}

$out = $root . '/admin/dashboard.php';
file_put_contents($out, $content);

echo "\nFinal size: " . strlen($content) . " bytes\n";
echo "Written: $out\n";

$checks = [
    'pwaMetrics' => str_contains($content, '$pwaMetrics'),
    'getPwaInstallDashboardMetrics' => str_contains($content, 'getPwaInstallDashboardMetrics'),
    'dash-flag-corner' => str_contains($content, 'dash-flag-corner'),
    'getDashboardFeatureFlagKeys' => str_contains($content, 'getDashboardFeatureFlagKeys'),
    'dash-pwa-analytics' => str_contains($content, 'dash-pwa-analytics'),
    'toggleDashboardFeatureFlag' => str_contains($content, 'toggleDashboardFeatureFlag'),
    'dash-ops-snapshot' => str_contains($content, 'dash-ops-snapshot'),
];
foreach ($checks as $k => $v) {
    echo "  $k: " . ($v ? 'YES' : 'NO') . "\n";
}
