<?php
/**
 * P0 SMTP MIME fix — production verification orchestrator.
 *
 * 1. Local wire verify (post smtpDotStuff)
 * 2. Production wire audit on server
 * 3. Production test sends (5 templates + rejection)
 * 4. Wire-decode inbox previews + screenshots
 *
 * Usage:
 *   php scripts/verify-smtp-mime-production.php
 *   php scripts/verify-smtp-mime-production.php --to=info@olasentra.com --key=email-encoding-verify-20260606
 */
declare(strict_types=1);

$opts    = getopt('', ['base::', 'to::', 'key::', 'json', 'no-screenshots', 'skip-send']);
$baseUrl = rtrim((string) ($opts['base'] ?? 'https://register.olasentra.com'), '/');
$to      = trim((string) ($opts['to'] ?? ''));
$key     = trim((string) ($opts['key'] ?? 'email-encoding-verify-20260606'));
$jsonOut = array_key_exists('json', $opts);
$root    = dirname(__DIR__);
$shotDir = $root . '/docs/screenshots/email-smtp-fix';
$htmlDir = $shotDir . '/html';
$reportPath = $root . '/storage/reports/smtp-mime-production-verify-latest.json';

function out(bool $jsonOut, string $msg): void
{
    if (!$jsonOut) {
        echo $msg . PHP_EOL;
    }
}

function fetch_json(string $url): ?array
{
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
        'android' => 'body{margin:0;background:#fff;font-family:Roboto,Arial,sans-serif}.wrap{max-width:360px;margin:12px auto;padding:16px}.subj{font-size:16px;font-weight:500;color:#202124;margin:0 0 8px}.meta{font-size:11px;color:#5f6368;margin-bottom:12px}',
        default => 'body{font-family:sans-serif;padding:16px}',
    };
    $label = ucfirst($client);
    $content = $innerHtml !== ''
        ? $innerHtml
        : '<pre style="white-space:pre-wrap;font-family:inherit">' . htmlspecialchars($plain, ENT_QUOTES, 'UTF-8') . '</pre>';

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . $subjectEsc . ' — ' . $label . '</title><style>' . $styles . '</style></head><body>'
        . '<div class="wrap"><p class="subj">' . $subjectEsc . '</p><p class="meta">' . $label . ' — post-SMTP wire decode preview</p>'
        . $content . '</div></body></html>';
}

// 1. Local wire verify
out($jsonOut, '[1/4] Local SMTP wire verify...');
passthru('php ' . escapeshellarg($root . '/scripts/verify-smtp-mime-wire.php'), $wireCode);
$wireReport = json_decode((string) file_get_contents($root . '/storage/reports/smtp-mime-wire-verify-latest.json'), true);

// 2. Production wire audit
out($jsonOut, '[2/4] Production wire audit...');
$wireAuditUrl = $baseUrl . '/cron/smtp-mime-wire-audit.php?' . http_build_query(['key' => $key]);
$prodWire = fetch_json($wireAuditUrl);

// 3. Production sends
$sendReport = null;
if (!array_key_exists('skip-send', $opts)) {
    out($jsonOut, '[3/4] Production test sends...');
    $qs = http_build_query(array_filter(['key' => $key, 'to' => $to]));
    $sendReport = fetch_json($baseUrl . '/cron/email-production-verify.php?' . $qs);
    if (is_array($sendReport)) {
        file_put_contents($root . '/storage/reports/email-production-verify-latest.json', json_encode($sendReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
} else {
    $sendReport = json_decode((string) @file_get_contents($root . '/storage/reports/email-production-verify-latest.json'), true);
}

// 4. Inbox previews from production-sent template HTML (what clients render when MIME parses)
out($jsonOut, '[4/4] Building inbox previews...');
if (!is_dir($htmlDir)) {
    mkdir($htmlDir, 0755, true);
}
$clients = ['gmail', 'outlook', 'android'];
$previewCount = 0;
$templateIds = ['registration_confirmation', 'registration_approved', 'access_pass', 'rejection_email', 'admin_alert'];
foreach ($templateIds as $id) {
    $tpl = $sendReport['templates'][$id] ?? null;
    if (!is_array($tpl)) {
        continue;
    }
    $subject = (string) ($tpl['subject'] ?? $id);
    $html    = (string) ($tpl['html'] ?? '');
    $text    = (string) ($tpl['text'] ?? '');
    foreach ($clients as $client) {
        $file = $htmlDir . '/' . $id . '-' . $client . '.html';
        file_put_contents($file, client_chrome($client, $subject, $html, $text));
        $previewCount++;
    }
}

if (!array_key_exists('no-screenshots', $opts)) {
    $ps1 = $root . '/scripts/capture-email-smtp-fix-screenshots.ps1';
    if (is_file($ps1)) {
        out($jsonOut, 'Capturing screenshots...');
        passthru('powershell -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($ps1), $shotCode);
    }
}

$wirePass = ($wireCode === 0) && ($wireReport['all_pass'] ?? false);
$prodWirePass = is_array($prodWire) && ($prodWire['ok'] ?? false);
$sendPass = is_array($sendReport) && ($sendReport['ok'] ?? false) && ($sendReport['transport'] ?? '') === 'smtp';
$allTemplatesSent = true;
foreach ($templateIds as $id) {
    if (!($sendReport['templates'][$id]['sent'] ?? false)) {
        $allTemplatesSent = false;
    }
}

$summary = [
    'generated_at' => gmdate('c'),
    'wire_local_pass' => $wirePass,
    'wire_production_pass' => $prodWirePass,
    'production_send_pass' => $sendPass && $allTemplatesSent,
    'to' => $sendReport['to'] ?? '',
    'transport' => $sendReport['transport'] ?? '',
    'templates_sent' => array_map(static fn ($id) => $sendReport['templates'][$id]['sent'] ?? false, array_combine($templateIds, $templateIds)),
    'previews' => $previewCount,
    'inbox_manual_required' => true,
    'inbox_note' => 'Confirm fresh messages in info@olasentra.com Gmail (web + Android) and Outlook — no inner Content-Type lines, no =3D, links clickable.',
    'prod_wire_audit' => $prodWire,
];

$summary['automated_pass'] = $wirePass && $prodWirePass && $sendPass && $allTemplatesSent;
$summary['full_pass'] = false;

file_put_contents($reportPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);

if ($jsonOut) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    out($jsonOut, ($summary['automated_pass'] ? 'AUTOMATED PASS' : 'AUTOMATED FAIL') . ' — SMTP MIME production verify');
    out($jsonOut, 'Wire local: ' . ($wirePass ? 'PASS' : 'FAIL'));
    out($jsonOut, 'Wire production: ' . ($prodWirePass ? 'PASS' : 'FAIL'));
    out($jsonOut, 'SMTP sends: ' . ($sendPass && $allTemplatesSent ? 'PASS' : 'FAIL') . ' → ' . ($summary['to'] ?? ''));
    out($jsonOut, 'INBOX: manual confirmation required on Gmail web, Gmail Android, Outlook');
    out($jsonOut, 'Report: ' . $reportPath);
}

exit($summary['automated_pass'] ? 0 : 1);
