<?php
/**
 * Verify multipart/alternative MIME structure after mailer fix.
 *
 * Usage: php scripts/verify-email-mime.php [--json]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/email-copy.php';
require_once $root . '/includes/mailer.php';

$jsonOut = in_array('--json', $argv ?? [], true);

function mime_check(string $label, bool $pass, string $detail = ''): array
{
    return ['check' => $label, 'pass' => $pass, 'detail' => $detail];
}

function extract_mime_plain_part(string $body): ?string
{
    if (preg_match(
        '/Content-Type:\s*text\/plain[^\r\n]*\r\nContent-Transfer-Encoding:\s*[^\r\n]+\r\n\r\n(.*?)(?=\r\n--)/s',
        $body,
        $m
    ) !== 1) {
        return null;
    }

    return str_replace("\r\n", "\n", $m[1]);
}

function extract_mime_html_part_raw(string $body): ?string
{
    if (preg_match(
        '/Content-Type:\s*text\/html[^\r\n]*\r\nContent-Transfer-Encoding:\s*[^\r\n]+\r\n\r\n(.*?)(?=\r\n--|$)/s',
        $body,
        $m
    ) !== 1) {
        return null;
    }

    return $m[1];
}

$bodyLines = [
    'Dear Test,',
    '',
    'Thank you for your interest. Your staff registration was not approved at this time.',
    '',
    '* Kings Of Leon - 01/07/2026 - Static Security',
    '  Contractor listed for this shift: Acme Security (confirm pay and duties with them).',
    '',
    'View your registration status anytime:',
    'https://register.olasentra.com/status.php?token=test',
    '',
    'Sent by the registration portal only.',
    '',
    'Regards,',
    'Security Update',
];
$statusUrl = 'https://register.olasentra.com/status.php?token=test';
$text      = implode("\n", $bodyLines);
$html      = buildStaffEmailHtmlFromLines($bodyLines, $statusUrl, 'View my status');

$dualMime     = buildEmailMimePayload($text, $html);
$plainOnly    = buildEmailMimePayload($text, null);
$htmlOnly     = buildEmailMimePayload('', '<p>HTML only</p>');
$reminderText = "Dear Test,\n\nTime: 15:00 - 23:00\n";
$reminderMime = buildEmailMimePayload($reminderText, null);

$plainExtracted = extract_mime_plain_part($dualMime['body']);
$htmlRaw        = extract_mime_html_part_raw($dualMime['body']);
$htmlDecoded    = $htmlRaw !== null ? quoted_printable_decode($htmlRaw) : '';

$checks = [
    mime_check(
        'Dual-format uses multipart/alternative',
        str_starts_with($dualMime['content_type'], 'multipart/alternative'),
        $dualMime['content_type']
    ),
    mime_check(
        'Dual-format outer CTE is 8bit',
        $dualMime['transfer_encoding'] === '8bit',
        $dualMime['transfer_encoding']
    ),
    mime_check(
        'Plain part present in wire MIME',
        $plainExtracted !== null && $plainExtracted !== '',
        $plainExtracted !== null ? 'length ' . strlen($plainExtracted) : 'missing'
    ),
    mime_check(
        'Plain part matches source text',
        $plainExtracted === $text,
        'extracted plain equals template plain'
    ),
    mime_check(
        'Plain part has no HTML tags',
        $plainExtracted !== null
            && !str_contains($plainExtracted, '<br')
            && !str_contains($plainExtracted, '<p>')
            && !str_contains($plainExtracted, '<div'),
        $plainExtracted ?? ''
    ),
    mime_check(
        'Plain part has intact words (Contractor, Security, listed, Sent)',
        $plainExtracted !== null
            && str_contains($plainExtracted, 'Contractor listed')
            && str_contains($plainExtracted, 'Acme Security')
            && str_contains($plainExtracted, 'Sent by'),
        ''
    ),
    mime_check(
        'HTML part decodes and contains clickable link',
        str_contains($htmlDecoded, '<a href="https://register.olasentra.com/status.php?token=test"')
            && str_contains($htmlDecoded, 'View my status'),
        substr($htmlDecoded, 0, 200)
    ),
    mime_check(
        'HTML QP isolated inside html part (plain not QP-wrapped)',
        $plainExtracted !== null && !str_contains($plainExtracted, '=3D') && !str_contains($plainExtracted, "=\n"),
        ''
    ),
    mime_check(
        'Plain-only unchanged (text/plain 8bit)',
        $plainOnly['content_type'] === 'text/plain; charset=UTF-8'
            && $plainOnly['transfer_encoding'] === '8bit',
        $plainOnly['content_type']
    ),
    mime_check(
        'Reminder plain-only unaffected',
        $reminderMime['content_type'] === 'text/plain; charset=UTF-8'
            && !str_starts_with($reminderMime['content_type'], 'multipart/'),
        $reminderMime['content_type']
    ),
    mime_check(
        'HTML-only fallback when plain empty',
        $htmlOnly['content_type'] === 'text/html; charset=UTF-8',
        $htmlOnly['content_type']
    ),
    mime_check(
        'Boundary markers well-formed',
        preg_match('/--=_Olasentra_[a-f0-9]{24}\r\n/', $dualMime['body']) === 1
            && str_ends_with(trim($dualMime['body']), '--'),
        'boundary prefix _Olasentra_'
    ),
];

$allPass = true;
foreach ($checks as $c) {
    if (!$c['pass']) {
        $allPass = false;
    }
}

$report = [
    'generated_at' => gmdate('c'),
    'all_pass'     => $allPass,
    'dual_mime'    => [
        'content_type'         => $dualMime['content_type'],
        'transfer_encoding'    => $dualMime['transfer_encoding'],
        'body_length'          => strlen($dualMime['body']),
        'plain_extracted'      => $plainExtracted,
        'html_decoded_sample'  => substr($htmlDecoded, 0, 500),
        'mime_sample'          => substr($dualMime['body'], 0, 900),
    ],
    'checks' => $checks,
];

$outDir = $root . '/storage/reports';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}
file_put_contents($outDir . '/email-mime-verify-latest.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

if ($jsonOut) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($allPass ? 0 : 1);
}

echo ($allPass ? 'PASS' : 'FAIL') . ' — email MIME verification' . PHP_EOL;
foreach ($checks as $c) {
    echo ($c['pass'] ? '  OK' : '  FAIL') . ' ' . $c['check'] . PHP_EOL;
}
echo 'Report: storage/reports/email-mime-verify-latest.json' . PHP_EOL;
exit($allPass ? 0 : 1);
