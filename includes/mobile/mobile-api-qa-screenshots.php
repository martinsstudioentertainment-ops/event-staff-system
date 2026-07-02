<?php

declare(strict_types=1);

require_once __DIR__ . '/mobile-api-qa-runner.php';
require_once __DIR__ . '/../helpers.php';

/**
 * @param array<string, mixed> $report
 * @return array{ok: bool, message?: string, json?: string, html?: string, png?: ?string, svg?: string, dir?: string}
 */
function mobileQaSaveArtifacts(array $report): array
{
    $dirCheck = mobileQaEnsureWritableOutputDir();
    if (empty($dirCheck['ok'])) {
        return ['ok' => false, 'message' => (string) ($dirCheck['message'] ?? 'Output directory not writable.')];
    }

    $dir    = (string) $dirCheck['path'];
    $runId  = (string) ($report['run_id'] ?? date('Ymd-His'));
    $safeId = preg_replace('/[^a-zA-Z0-9._-]/', '-', $runId) ?? $runId;

    $jsonPath = $dir . '/' . $safeId . '.json';
    $htmlPath = $dir . '/' . $safeId . '.html';
    $svgPath  = $dir . '/' . $safeId . '.svg';
    $pngPath  = $dir . '/' . $safeId . '.png';

    $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $jsonBody = json_encode($report, $jsonFlags);
    if ($jsonBody === false) {
        return ['ok' => false, 'message' => 'JSON encode failed: ' . json_last_error_msg()];
    }
    if (@file_put_contents($jsonPath, $jsonBody) === false) {
        return ['ok' => false, 'message' => 'Could not write JSON report: ' . $jsonPath];
    }

    $html = mobileQaRenderHtmlReport($report);
    if (@file_put_contents($htmlPath, $html) === false) {
        return ['ok' => false, 'message' => 'Could not write HTML report: ' . $htmlPath];
    }

    $svg = mobileQaRenderSvgReport($report);
    if (@file_put_contents($svgPath, $svg) === false) {
        return ['ok' => false, 'message' => 'Could not write SVG report: ' . $svgPath];
    }

    $pngSaved = mobileQaRenderPngReport($report, $pngPath);

    return [
        'ok'   => true,
        'json' => $jsonPath,
        'html' => $htmlPath,
        'svg'  => $svgPath,
        'png'  => $pngSaved ? $pngPath : null,
        'dir'  => $dir,
    ];
}

/**
 * @param array<string, mixed> $report
 */
function mobileQaRenderHtmlReport(array $report): string
{
    $runId   = h((string) ($report['run_id'] ?? ''));
    $overall = h((string) ($report['overall'] ?? 'FAIL'));
    $staff   = is_array($report['staff'] ?? null) ? $report['staff'] : [];
    $staffLabel = h(trim((string) ($staff['name'] ?? '') . ' · ' . (string) ($staff['email'] ?? '')));
    $baseUrl = h((string) ($report['base_url'] ?? ''));
    $ts      = h((string) ($report['timestamp'] ?? ''));
    $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
    $passed  = (int) ($summary['passed'] ?? 0);
    $failed  = (int) ($summary['failed'] ?? 0);
    $overallColor = ($report['overall'] ?? '') === 'PASS' ? '#15803d' : '#b91c1c';

    $rowsHtml = '';
    foreach ($report['results'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $status = (string) ($row['status'] ?? '');
        $color  = $status === 'PASS' ? '#15803d' : '#b91c1c';
        $rowsHtml .= '<tr>'
            . '<td>' . h((string) ($row['group'] ?? '')) . '</td>'
            . '<td>' . h((string) ($row['name'] ?? '')) . '</td>'
            . '<td style="color:' . $color . ';font-weight:700;">' . h($status) . '</td>'
            . '<td>' . h((string) ($row['detail'] ?? '')) . '</td>'
            . '</tr>';
    }

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<title>Mobile API QA ' . $runId . '</title>'
        . '<style>'
        . 'body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;margin:0;background:#f8fafc;color:#0f172a;}'
        . '.wrap{max-width:960px;margin:0 auto;padding:24px;}'
        . '.hero{background:#0f172a;color:#fff;border-radius:12px;padding:20px 24px;margin-bottom:20px;}'
        . '.hero h1{margin:0 0 8px;font-size:1.35rem;}'
        . '.badge{display:inline-block;padding:4px 12px;border-radius:999px;font-weight:700;background:' . $overallColor . ';color:#fff;}'
        . '.meta{color:#cbd5e1;font-size:0.92rem;line-height:1.5;}'
        . '.table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);}'
        . '.table th,.table td{padding:10px 12px;border-bottom:1px solid #e2e8f0;text-align:left;font-size:0.92rem;vertical-align:top;}'
        . '.table th{background:#f1f5f9;font-size:0.8rem;text-transform:uppercase;letter-spacing:.04em;color:#475569;}'
        . '.foot{margin-top:16px;color:#64748b;font-size:0.85rem;}'
        . '.summary{margin:12px 0 20px;font-size:1rem;}'
        . '</style></head><body><div class="wrap">'
        . '<div class="hero"><h1>Olasentra Mobile API — Phase 1 QA</h1>'
        . '<div class="meta">Run <strong>' . $runId . '</strong> · ' . $ts . '<br>'
        . 'Staff: ' . $staffLabel . '<br>Base: ' . $baseUrl . '</div>'
        . '<p style="margin:16px 0 0;"><span class="badge">' . $overall . '</span></p></div>'
        . '<p class="summary"><strong>' . $passed . '</strong> passed · <strong>' . $failed . '</strong> failed · read-only (no writes)</p>'
        . '<table class="table"><thead><tr><th>Group</th><th>Test</th><th>Status</th><th>Detail</th></tr></thead><tbody>'
        . $rowsHtml
        . '</tbody></table>'
        . '<p class="foot">Temporary admin QA tool — does not create refresh tokens, messages, or availability changes.</p>'
        . '</div></body></html>';
}

/**
 * @param array<string, mixed> $report
 */
function mobileQaRenderSvgReport(array $report): string
{
    $results = is_array($report['results'] ?? null) ? $report['results'] : [];
    $rowH    = 28;
    $headerH = 120;
    $height  = $headerH + 40 + (count($results) * $rowH) + 40;
    $width   = 920;
    $overall = (string) ($report['overall'] ?? 'FAIL');
    $badgeColor = $overall === 'PASS' ? '#15803d' : '#b91c1c';
    $staff = is_array($report['staff'] ?? null) ? $report['staff'] : [];
    $title = 'Mobile API QA — ' . trim((string) ($staff['name'] ?? 'Staff'));
    $sub   = (string) ($staff['email'] ?? '') . ' · ' . (string) ($report['run_id'] ?? '');

    $svg = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">'
        . '<rect width="100%" height="100%" fill="#f8fafc"/>'
        . '<rect x="20" y="20" width="' . ($width - 40) . '" height="88" rx="12" fill="#0f172a"/>'
        . '<text x="36" y="52" fill="#ffffff" font-family="Segoe UI, Arial, sans-serif" font-size="20" font-weight="700">' . mobileQaSvgEscape($title) . '</text>'
        . '<text x="36" y="78" fill="#cbd5e1" font-family="Segoe UI, Arial, sans-serif" font-size="13">' . mobileQaSvgEscape($sub) . '</text>'
        . '<rect x="' . ($width - 130) . '" y="44" width="90" height="28" rx="14" fill="' . $badgeColor . '"/>'
        . '<text x="' . ($width - 85) . '" y="63" fill="#ffffff" font-family="Segoe UI, Arial, sans-serif" font-size="14" font-weight="700" text-anchor="middle">' . mobileQaSvgEscape($overall) . '</text>';

    $y = $headerH;
    $svg .= '<rect x="20" y="' . $y . '" width="' . ($width - 40) . '" height="32" fill="#e2e8f0"/>';
    $svg .= mobileQaSvgText(28, $y + 21, 'Group', 12, '#475569', true);
    $svg .= mobileQaSvgText(160, $y + 21, 'Test', 12, '#475569', true);
    $svg .= mobileQaSvgText(520, $y + 21, 'Status', 12, '#475569', true);
    $svg .= mobileQaSvgText(610, $y + 21, 'Detail', 12, '#475569', true);
    $y += 32;

    foreach ($results as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $fill = ($i % 2) === 0 ? '#ffffff' : '#f1f5f9';
        $svg .= '<rect x="20" y="' . $y . '" width="' . ($width - 40) . '" height="' . $rowH . '" fill="' . $fill . '"/>';
        $status = (string) ($row['status'] ?? '');
        $statusColor = $status === 'PASS' ? '#15803d' : '#b91c1c';
        $svg .= mobileQaSvgText(28, $y + 19, (string) ($row['group'] ?? ''), 11, '#0f172a', false);
        $svg .= mobileQaSvgText(160, $y + 19, mobileQaTruncate((string) ($row['name'] ?? ''), 42), 11, '#0f172a', false);
        $svg .= mobileQaSvgText(520, $y + 19, $status, 11, $statusColor, true);
        $svg .= mobileQaSvgText(610, $y + 19, mobileQaTruncate((string) ($row['detail'] ?? ''), 38), 11, '#475569', false);
        $y += $rowH;
    }

    $svg .= '</svg>';

    return $svg;
}

function mobileQaSvgEscape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function mobileQaSvgText(int $x, int $y, string $text, int $size, string $color, bool $bold): string
{
    $weight = $bold ? ' font-weight="700"' : '';

    return '<text x="' . $x . '" y="' . $y . '" fill="' . $color . '" font-family="Segoe UI, Arial, sans-serif" font-size="' . $size . '"' . $weight . '>'
        . mobileQaSvgEscape($text) . '</text>';
}

function mobileQaTruncate(string $text, int $max): string
{
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, max(0, $max - 1)) . '…';
    }

    if (strlen($text) <= $max) {
        return $text;
    }

    return substr($text, 0, max(0, $max - 1)) . '…';
}

/**
 * @param array<string, mixed> $report
 */
function mobileQaRenderPngReport(array $report, string $path): bool
{
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }

    $results = is_array($report['results'] ?? null) ? $report['results'] : [];
    $rowH    = 24;
    $headerH = 100;
    $height  = $headerH + 36 + (count($results) * $rowH) + 24;
    $width   = 920;

    $img = imagecreatetruecolor($width, $height);
    if ($img === false) {
        return false;
    }

    $bg      = imagecolorallocate($img, 248, 250, 252);
    $header  = imagecolorallocate($img, 15, 23, 42);
    $white   = imagecolorallocate($img, 255, 255, 255);
    $pass    = imagecolorallocate($img, 21, 128, 61);
    $fail    = imagecolorallocate($img, 185, 28, 28);
    $muted   = imagecolorallocate($img, 100, 116, 139);
    $stripe  = imagecolorallocate($img, 241, 245, 249);
    $border  = imagecolorallocate($img, 226, 232, 240);
    $black   = imagecolorallocate($img, 15, 23, 42);

    imagefilledrectangle($img, 0, 0, $width, $height, $bg);
    imagefilledrectangle($img, 16, 16, $width - 16, 96, $header);

    $overall = (string) ($report['overall'] ?? 'FAIL');
    $badge   = $overall === 'PASS' ? $pass : $fail;
    imagefilledrectangle($img, $width - 120, 40, $width - 30, 68, $badge);

    $staff = is_array($report['staff'] ?? null) ? $report['staff'] : [];
    $title = 'Mobile API QA — ' . trim((string) ($staff['name'] ?? 'Staff'));
    imagestring($img, 5, 28, 28, mobileQaAscii($title), $white);
    imagestring($img, 3, 28, 54, mobileQaAscii((string) ($staff['email'] ?? '')), $muted);
    imagestring($img, 4, $width - 108, 48, $overall, $white);

    $y = $headerH;
    imagefilledrectangle($img, 16, $y, $width - 16, $y + 28, $border);
    imagestring($img, 3, 24, $y + 8, 'Group', $muted);
    imagestring($img, 3, 150, $y + 8, 'Test', $muted);
    imagestring($img, 3, 500, $y + 8, 'Status', $muted);
    imagestring($img, 3, 590, $y + 8, 'Detail', $muted);
    $y += 28;

    foreach ($results as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $fill = ($i % 2) === 0 ? $white : $stripe;
        imagefilledrectangle($img, 16, $y, $width - 16, $y + $rowH, $fill);
        $status = (string) ($row['status'] ?? '');
        $statusColor = $status === 'PASS' ? $pass : $fail;
        imagestring($img, 2, 24, $y + 6, mobileQaAscii(mobileQaTruncate((string) ($row['group'] ?? ''), 18)), $black);
        imagestring($img, 2, 150, $y + 6, mobileQaAscii(mobileQaTruncate((string) ($row['name'] ?? ''), 44)), $black);
        imagestring($img, 2, 500, $y + 6, $status, $statusColor);
        imagestring($img, 2, 590, $y + 6, mobileQaAscii(mobileQaTruncate((string) ($row['detail'] ?? ''), 48)), $muted);
        $y += $rowH;
    }

    $ok = imagepng($img, $path);
    imagedestroy($img);

    return $ok;
}

function mobileQaAscii(string $text): string
{
    $text = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;

    return $text;
}
