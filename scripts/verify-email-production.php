<?php
/**
 * Production email verification orchestrator — calls live cron, builds client previews, screenshots.
 *
 * Usage:
 *   php scripts/verify-email-production.php
 *   php scripts/verify-email-production.php --to=admin@olasentra.com --key=email-encoding-verify-20260606
 */
declare(strict_types=1);

$opts    = getopt('', ['base::', 'to::', 'key::', 'json', 'no-screenshots']);
$baseUrl = rtrim((string) ($opts['base'] ?? 'https://register.olasentra.com'), '/');
$to      = trim((string) ($opts['to'] ?? ''));
$key     = trim((string) ($opts['key'] ?? 'email-encoding-verify-20260606'));
$jsonOut = array_key_exists('json', $opts);
$root    = dirname(__DIR__);
$shotDir = $root . '/docs/screenshots/email-production';
$htmlDir = $shotDir . '/html';
$reportJson = $root . '/storage/reports/email-production-verify-latest.json';

function out(bool $jsonOut, string $msg): void
{
    if (!$jsonOut) {
        echo $msg . PHP_EOL;
    }
}

function fetch_production_report(string $baseUrl, string $key, string $to): ?array
{
    $qs = http_build_query(array_filter(['key' => $key, 'to' => $to]));
    $url = $baseUrl . '/cron/email-production-verify.php?' . $qs;
    $ctx = stream_context_create(['http' => ['timeout' => 120, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if (!is_string($body) || trim($body) === '') {
        return null;
    }
    $data = json_decode($body, true);

    return is_array($data) ? $data : null;
}

function client_chrome(string $client, string $subject, string $innerHtml, string $plain = ''): string
{
    $subjectEsc = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
    $styles = match ($client) {
        'gmail' => 'body{margin:0;background:#f6f8fc;font-family:Roboto,Arial,sans-serif}.wrap{max-width:560px;margin:24px auto;background:#fff;border:1px solid #dadce0;border-radius:8px;padding:20px}.subj{font-size:20px;font-weight:500;color:#202124;margin:0 0 12px}.meta{font-size:12px;color:#5f6368;margin-bottom:16px}',
        'outlook' => 'body{margin:0;background:#f3f2f1;font-family:Segoe UI,Arial,sans-serif}.wrap{max-width:640px;margin:20px auto;background:#fff;border:1px solid #c8c6c4;padding:18px}.subj{font-size:18px;font-weight:600;color:#323130;margin:0 0 8px}.meta{font-size:11px;color:#605e5c;margin-bottom:14px}',
        'apple' => 'body{margin:0;background:#f2f2f7;font-family:-apple-system,BlinkMacSystemFont,sans-serif}.wrap{max-width:390px;margin:16px auto;background:#fff;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.08)}.subj{font-size:17px;font-weight:600;color:#000;margin:0 0 6px}.meta{font-size:12px;color:#8e8e93;margin-bottom:12px}',
        default => 'body{font-family:sans-serif;padding:16px}',
    };
    $label = ucfirst($client);
    $content = $innerHtml !== '' ? $innerHtml : '<pre style="white-space:pre-wrap;font-family:inherit">' . htmlspecialchars($plain, ENT_QUOTES, 'UTF-8') . '</pre>';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . $subjectEsc . ' — ' . $label . '</title><style>' . $styles . '</style></head><body>'
        . '<div class="wrap"><p class="subj">' . $subjectEsc . '</p><p class="meta">' . $label . ' preview — production-sent MIME</p>'
        . $content . '</div></body></html>';
}

out($jsonOut, 'Fetching production email verify: ' . $baseUrl . '/cron/email-production-verify.php');
$report = fetch_production_report($baseUrl, $key, $to);
if ($report === null || empty($report['ok'])) {
    $err = is_array($report) ? (string) ($report['error'] ?? 'unknown') : 'HTTP failed';
    fwrite(STDERR, 'Production verify failed: ' . $err . PHP_EOL);
    exit(1);
}

if (!is_dir($htmlDir)) {
    mkdir($htmlDir, 0755, true);
}

$clients = ['gmail', 'outlook', 'apple'];
$previews = [];

foreach ($report['templates'] ?? [] as $id => $tpl) {
    $subject = (string) ($tpl['subject'] ?? $id);
    $html    = (string) ($tpl['html'] ?? '');
    $text    = (string) ($tpl['text'] ?? '');
    foreach ($clients as $client) {
        $file = $htmlDir . '/' . $id . '-' . $client . '.html';
        file_put_contents($file, client_chrome($client, $subject, $html, $text));
        $previews[] = ['template' => $id, 'client' => $client, 'html' => $file];
    }
}

file_put_contents($reportJson, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

if (!array_key_exists('no-screenshots', $opts)) {
    $ps1 = $root . '/scripts/capture-email-production-screenshots.ps1';
    if (is_file($ps1)) {
        out($jsonOut, 'Capturing screenshots...');
        $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($ps1);
        passthru($cmd, $code);
        if ($code !== 0) {
            out($jsonOut, 'Screenshot capture warning: exit ' . $code);
        }
    }
}

$summary = [
    'ok' => (bool) ($report['all_pass'] ?? false),
    'to' => $report['to'] ?? '',
    'transport' => $report['transport'] ?? '',
    'templates' => array_map(static fn ($t) => [
        'sent' => $t['sent'] ?? false,
        'subject' => $t['subject'] ?? '',
        'scan_pass' => $t['scan']['pass'] ?? false,
        'bad_tokens' => $t['scan']['bad_tokens'] ?? [],
    ], $report['templates'] ?? []),
    'previews' => count($previews),
    'report_json' => $reportJson,
    'screenshots_dir' => $shotDir,
];

if ($jsonOut) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    out($jsonOut, ($summary['ok'] ? 'PASS' : 'FAIL') . ' — production email verification');
    out($jsonOut, 'Sent to: ' . $summary['to'] . ' via ' . $summary['transport']);
    foreach ($summary['templates'] as $id => $t) {
        out($jsonOut, sprintf('  %s: sent=%s scan=%s', $id, $t['sent'] ? 'yes' : 'no', $t['scan_pass'] ? 'PASS' : 'FAIL'));
    }
}

exit($summary['ok'] ? 0 : 1);
