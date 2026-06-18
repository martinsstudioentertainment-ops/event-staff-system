<?php
$root = dirname(__DIR__, 2);
$patchFile = __DIR__ . '/dashboard-patches.json';
$dashboard = $root . '/admin/dashboard.php';
$backup = __DIR__ . '/dashboard-before-recover.php';
$out = __DIR__ . '/dashboard-recovered.php';

$patches = json_decode(file_get_contents($patchFile), true);
$content = file_get_contents($dashboard);
copy($dashboard, $backup);

$applied = 0;
$failed = [];
foreach ($patches as $idx => $p) {
    $old = $p['old'];
    $new = $p['new'];
    if ($old === '') {
        continue;
    }
    $pos = strpos($content, $old);
    if ($pos === false) {
        $failed[] = ['idx' => $idx, 'line' => $p['line'], 'old_len' => strlen($old), 'preview' => substr($old, 0, 80)];
        continue;
    }
    $content = substr($content, 0, $pos) . $new . substr($content, $pos + strlen($old));
    $applied++;
}

file_put_contents($out, $content);

$checks = [
    'pwaMetrics' => strpos($content, 'pwaMetrics') !== false,
    'getPwaInstallDashboardMetrics' => strpos($content, 'getPwaInstallDashboardMetrics') !== false,
    'dash-flag-corner' => strpos($content, 'dash-flag-corner') !== false,
    'getDashboardFeatureFlagKeys' => strpos($content, 'getDashboardFeatureFlagKeys') !== false,
    'dash-pwa-analytics' => strpos($content, 'dash-pwa-analytics') !== false,
    'toggleDashboardFeatureFlag' => strpos($content, 'toggleDashboardFeatureFlag') !== false,
];

echo "Applied: $applied / " . count($patches) . "\n";
echo "Failed: " . count($failed) . "\n";
foreach ($failed as $f) {
    echo "  FAIL idx={$f['idx']} transcript_line={$f['line']} old_len={$f['old_len']} preview=" . json_encode($f['preview']) . "\n";
}
echo "Size: " . strlen($content) . " bytes\n";
echo "Checks:\n";
foreach ($checks as $k => $v) {
    echo "  $k: " . ($v ? 'YES' : 'NO') . "\n";
}
