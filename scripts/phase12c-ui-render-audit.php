<?php

declare(strict_types=1);

/**
 * Phase 12C — Staff PWA v3 UI rendering static audit (no deploy).
 * Run: php scripts/phase12c-ui-render-audit.php
 */

$root = dirname(__DIR__);
$cssPath = $root . '/assets/css/staff-app-v3.css';
$css = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';

$phpFiles = array_merge(
    glob($root . '/includes/staff-app-v3*.php') ?: [],
    glob($root . '/includes/staff-app-easy.php') ?: [],
    glob($root . '/includes/staff-portal-shift.php') ?: [],
    glob($root . '/includes/components/staff-status-dashboard.php') ?: [],
    glob($root . '/includes/components/notification-list.php') ?: [],
    [$root . '/offline.php']
);

$svgCount = 0;
$svgWithoutFillAttr = 0;
$svgWithExplicitFill = 0;
$filesWithSvg = [];

foreach ($phpFiles as $file) {
    $content = (string) file_get_contents($file);
    if (!preg_match_all('/<svg\b[^>]*>.*?<\/svg>/is', $content, $matches)) {
        continue;
    }
    foreach ($matches[0] as $svg) {
        $svgCount++;
        if (preg_match('/\bfill="/i', $svg)) {
            $svgWithExplicitFill++;
        } else {
            $svgWithoutFillAttr++;
        }
    }
    if ($matches[0] !== []) {
        $filesWithSvg[basename($file)] = count($matches[0]);
    }
}

$cssFillNoneRules = preg_match_all('/fill:\s*none/i', $css);
$hasGlobalSvgRule = (bool) preg_match('/\.es-v3\s+svg\s*\{[^}]*fill:\s*none/i', $css);
$hasPhase12a = str_contains($css, 'Phase 12A');
$hasWebkitMask = str_contains($css, '-webkit-mask-image');

$sw = is_file($root . '/sw.js') ? (string) file_get_contents($root . '/sw.js') : '';
$swCacheFirst = str_contains($sw, 'return cached || network');
$swCacheName = '';
if (preg_match("/CACHE_NAME\s*=\s*'([^']+)'/", $sw, $m)) {
    $swCacheName = $m[1];
}
$swHasRegCss = str_contains($sw, 'registration-v3.css');

$offline = is_file($root . '/offline.php') ? (string) file_get_contents($root . '/offline.php') : '';
$offlineCssBust = (bool) preg_match('/staff-app-v3\.css\?v=/', $offline);

$issues = [];
if ($svgWithoutFillAttr > 0 && !$hasGlobalSvgRule) {
    $issues[] = [
        'id' => 'SVG-01',
        'severity' => 'HIGH',
        'title' => 'Inline SVGs rely on scoped CSS fill:none (no global fallback)',
        'detail' => sprintf('%d/%d inline SVGs lack fill attribute; CSS has %d fill:none rules but no `.es-v3 svg` global rule.', $svgWithoutFillAttr, $svgCount, $cssFillNoneRules),
    ];
}
if ($swCacheFirst) {
    $issues[] = [
        'id' => 'SW-01',
        'severity' => 'HIGH',
        'title' => 'Service worker cache-first for static assets',
        'detail' => "CACHE_NAME={$swCacheName}; stale CSS can persist on installed PWA until cache bump.",
    ];
}
if (!$offlineCssBust) {
    $issues[] = [
        'id' => 'CSS-01',
        'severity' => 'MEDIUM',
        'title' => 'offline.php loads staff-app-v3.css without ?v= cache buster',
        'detail' => 'Offline shell may render with stale stylesheet from SW precache.',
    ];
}
if (!$hasWebkitMask && str_contains($css, 'mask-image:')) {
    $issues[] = [
        'id' => 'CSS-02',
        'severity' => 'LOW',
        'title' => 'mask-image without -webkit-mask-image on empty-card pseudo',
        'detail' => 'Safari/iOS may not render masked calendar icon in .es-v3__empty-card::before.',
    ];
}
if (!$swHasRegCss) {
    $issues[] = [
        'id' => 'SW-02',
        'severity' => 'LOW',
        'title' => 'registration-v3.css not in SW CORE_ASSETS',
        'detail' => 'Messages token view / registration pages may miss CSS when offline.',
    ];
}

$report = [
    'generated_at' => gmdate('c'),
    'css_bytes' => strlen($css),
    'css_phase_12a' => $hasPhase12a,
    'css_fill_none_rules' => $cssFillNoneRules,
    'css_global_svg_fill_none' => $hasGlobalSvgRule,
    'inline_svg_total' => $svgCount,
    'inline_svg_without_fill_attr' => $svgWithoutFillAttr,
    'inline_svg_with_explicit_fill' => $svgWithExplicitFill,
    'svg_by_file' => $filesWithSvg,
    'sw_cache_name' => $swCacheName,
    'sw_cache_first' => $swCacheFirst,
    'offline_css_cache_bust' => $offlineCssBust,
    'issues' => $issues,
    'verdict' => $issues !== [] ? 'ISSUES FOUND' : 'NO ISSUES FOUND',
];

$outDir = $root . '/docs';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}
$outFile = $outDir . '/phase12c-ui-render-audit-' . date('Ymd-His') . '.json';
file_put_contents($outFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Phase 12C UI Rendering Static Audit\n";
echo str_repeat('-', 40) . "\n";
echo "Verdict: {$report['verdict']}\n";
echo "Inline SVGs: {$svgCount} ({$svgWithoutFillAttr} without fill attr)\n";
echo "CSS fill:none rules: {$cssFillNoneRules}\n";
echo "Global .es-v3 svg rule: " . ($hasGlobalSvgRule ? 'yes' : 'no') . "\n";
echo "SW cache-first: " . ($swCacheFirst ? 'yes' : 'no') . " ({$swCacheName})\n";
echo "offline.php CSS bust: " . ($offlineCssBust ? 'yes' : 'no') . "\n";
echo "Issues: " . count($issues) . "\n";
foreach ($issues as $issue) {
    echo "  [{$issue['severity']}] {$issue['id']}: {$issue['title']}\n";
}
echo "Report: {$outFile}\n";

exit($issues !== [] ? 1 : 0);
