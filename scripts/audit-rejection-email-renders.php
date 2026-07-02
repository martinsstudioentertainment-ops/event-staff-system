<?php
/**
 * Build rejection email render previews for audit screenshots.
 * Usage: php scripts/audit-rejection-email-renders.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/email-copy.php';
require_once $root . '/includes/mailer.php';

$siteName  = 'Security Update';
$firstName = 'Olayinka';
$statusUrl = 'https://register.olasentra.com/status.php?token=abc123example';
$subject   = $siteName . ' - Registration update';

$bodyLines = [
    'Dear ' . $firstName . ',',
    '',
    'Thank you for your interest. Your staff registration was not approved at this time.',
    '',
    '* Kings Of Leon - 01/07/2026 - Static Security',
    '  Contractor listed for this shift: Acme Security (confirm pay and duties with them).',
    '',
    'View your registration status anytime:',
    $statusUrl,
    '',
    'If you have questions, please contact us using the contact details on the website.',
    '',
    'Sent by the registration portal only. Pay and duties are agreed with the on-site contractor or event organiser.',
    '',
    'Regards,',
    $siteName,
];

$text = implode("\n", $bodyLines);
$html = buildStaffEmailHtmlFromLines($bodyLines, $statusUrl, 'View my status');
function client_chrome(string $client, string $subject, string $innerHtml, string $subtitle): string
{
    $subjectEsc = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
    $styles = match ($client) {
        'gmail' => 'body{margin:0;background:#f6f8fc;font-family:Roboto,Arial,sans-serif}.wrap{max-width:560px;margin:24px auto;background:#fff;border:1px solid #dadce0;border-radius:8px;padding:20px}.subj{font-size:20px;font-weight:500;color:#202124;margin:0 0 12px}.meta{font-size:12px;color:#5f6368;margin-bottom:16px}',
        'outlook' => 'body{margin:0;background:#f3f2f1;font-family:Segoe UI,Arial,sans-serif}.wrap{max-width:640px;margin:20px auto;background:#fff;border:1px solid #c8c6c4;padding:18px}.subj{font-size:18px;font-weight:600;color:#323130;margin:0 0 8px}.meta{font-size:11px;color:#605e5c;margin-bottom:14px}',
        'apple' => 'body{margin:0;background:#f2f2f7;font-family:-apple-system,BlinkMacSystemFont,sans-serif}.wrap{max-width:390px;margin:16px auto;background:#fff;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.08)}.subj{font-size:17px;font-weight:600;color:#000;margin:0 0 6px}.meta{font-size:12px;color:#8e8e93;margin-bottom:12px}',
        default => 'body{font-family:sans-serif;padding:16px}',
    };

    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . $subjectEsc . '</title><style>'
        . $styles
        . '.broken{font-family:Consolas,monospace;font-size:11px;line-height:1.45;white-space:pre-wrap;word-break:break-all;color:#111}'
        . '.plain{white-space:pre-wrap;font-family:inherit;line-height:1.55}'
        . '</style></head><body><div class="wrap"><p class="subj">' . $subjectEsc . '</p><p class="meta">' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</p>'
        . $innerHtml . '</div></body></html>';
}

$outDir = $root . '/docs/screenshots/email-rejection/html';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$clients = ['gmail', 'outlook', 'apple'];
$mime       = buildEmailMimePayload($text, $html);
$wirePlain  = $text;
if (preg_match(
    '/Content-Type:\s*text\/plain[^\r\n]*\r\nContent-Transfer-Encoding:\s*[^\r\n]+\r\n\r\n(.*?)(?=\r\n--)/s',
    $mime['body'],
    $m
) === 1) {
    $wirePlain = str_replace("\r\n", "\n", $m[1]);
}

$renders = [
    'html'  => ['subtitle' => 'HTML part rendered (multipart/alternative — text/html)', 'content' => $html],
    'plain' => ['subtitle' => 'Plain-text part from wire MIME (multipart/alternative — text/plain)', 'content' => '<pre class="plain">' . htmlspecialchars($wirePlain, ENT_QUOTES, 'UTF-8') . '</pre>'],
];

foreach ($renders as $mode => $cfg) {
    foreach ($clients as $client) {
        $file = $outDir . '/rejection-' . $mode . '-' . $client . '.html';
        file_put_contents($file, client_chrome($client, $subject, $cfg['content'], $cfg['subtitle']));
    }
}

$report = json_decode((string) file_get_contents($root . '/storage/reports/email-rejection-template-audit-snapshot.json'), true) ?: [];
$report['render_previews'] = array_map(static fn (string $c): string => 'docs/screenshots/email-rejection/html/rejection-{mode}-' . $c . '.html', $clients);
$report['generated_at'] = gmdate('c');
file_put_contents($root . '/storage/reports/email-rejection-template-audit-snapshot.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo 'Wrote ' . count($renders) * count($clients) . ' preview HTML files to docs/screenshots/email-rejection/html/' . PHP_EOL;
