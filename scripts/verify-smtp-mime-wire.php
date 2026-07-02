<?php
/**
 * Verify multipart/alternative wire format AFTER smtpDotStuff() — required for SMTP PASS.
 *
 * Usage: php scripts/verify-smtp-mime-wire.php [--json]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/email-copy.php';
require_once $root . '/includes/mailer.php';

$jsonOut = in_array('--json', $argv ?? [], true);

function wire_check(string $label, bool $pass, string $detail = ''): array
{
    return ['check' => $label, 'pass' => $pass, 'detail' => $detail];
}

/**
 * @return array{payload: string, mime: array{content_type: string, transfer_encoding: string, body: string}}
 */
function build_sample_wire_payload(): array
{
    $bodyLines = [
        'Dear Verify,',
        '',
        'Thank you for your interest. Your staff registration was not approved at this time.',
        '',
        '* Kings Of Leon - 01/07/2026 - Static Security',
        '',
        'View your registration status anytime:',
        'https://register.olasentra.com/status.php?token=wireverify',
        '',
        'Regards,',
        'Security Update',
    ];
    $text = implode("\n", $bodyLines);
    $html = buildStaffEmailHtmlFromLines(
        $bodyLines,
        'https://register.olasentra.com/status.php?token=wireverify',
        'View my status'
    );
    $mime = buildEmailMimePayload($text, $html);
    $headers = [
        'Date: ' . date('r'),
        'From: "Security Update" <noreply@olasentra.com>',
        'To: info@olasentra.com',
        'Subject: Security Update - Registration update',
        'MIME-Version: 1.0',
        'Content-Type: ' . $mime['content_type'],
        'Content-Transfer-Encoding: ' . $mime['transfer_encoding'],
    ];

    return [
        'payload' => implode("\r\n", $headers) . "\r\n\r\n" . $mime['body'],
        'mime'    => $mime,
    ];
}

function extract_wire_body(string $payload): string
{
    $pos = strpos($payload, "\r\n\r\n");
    if ($pos === false) {
        return $payload;
    }

    return substr($payload, $pos + 4);
}

function parse_multipart_alternative(string $body, string $contentType): ?array
{
    if (!preg_match('/boundary="([^"]+)"/', $contentType, $bm)) {
        return null;
    }
    $boundary = $bm[1];
    $parts    = preg_split('/--' . preg_quote($boundary, '/') . '(?:--)?\r\n/', $body);
    if (!is_array($parts)) {
        return null;
    }
    $plain = null;
    $html  = null;
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || $part === '--') {
            continue;
        }
        if (str_starts_with($part, 'Content-Type: text/plain')) {
            $plain = preg_replace('/^Content-Type:[^\r\n]*\r\nContent-Transfer-Encoding:[^\r\n]*\r\n\r\n/s', '', $part);
        }
        if (str_starts_with($part, 'Content-Type: text/html')) {
            $raw = preg_replace('/^Content-Type:[^\r\n]*\r\nContent-Transfer-Encoding:[^\r\n]*\r\n\r\n/s', '', $part);
            $html = $raw !== null ? quoted_printable_decode($raw) : null;
        }
    }

    return ['plain' => $plain, 'html' => $html, 'boundary' => $boundary];
}

$beforeFix = static function (string $payload): string {
    $payload = str_replace("\n", "\r\n", $payload);

    return preg_replace('/^\./m', '..', $payload) ?? $payload;
};

$sample  = build_sample_wire_payload();
$raw     = $sample['payload'];
$mime    = $sample['mime'];
$after   = smtpDotStuff($raw);
$legacy  = $beforeFix($raw);
$body    = extract_wire_body($after);
$parsed  = parse_multipart_alternative($body, $mime['content_type']);
$plain   = $parsed['plain'] ?? '';
$html    = $parsed['html'] ?? '';

$checks = [
    wire_check('No \\r\\r\\n corruption after smtpDotStuff', substr_count($after, "\r\r\n") === 0, (string) substr_count($after, "\r\r\n")),
    wire_check('Legacy smtpDotStuff would corrupt (sanity)', substr_count($legacy, "\r\r\n") > 0, (string) substr_count($legacy, "\r\r\n")),
    wire_check('Multipart boundary lines use single CRLF', !preg_match('/\r\r\n--=/', $after), ''),
    wire_check('Plain part extractable from wire body', $plain !== '' && !str_contains($plain, 'Content-Type:'), mb_substr($plain, 0, 80)),
    wire_check('HTML part decodes without =3D artefacts', $html !== '' && !str_contains($html, '=3D') && str_contains($html, '<div'), mb_substr($html, 0, 80)),
    wire_check('Decoded HTML contains clickable link', str_contains($html, '<a href="https://register.olasentra.com/status.php'), ''),
    wire_check('Wire body does not start with inner MIME headers', !str_starts_with(trim($plain), 'Content-Type:'), ''),
];

$allPass = true;
foreach ($checks as $c) {
    $allPass = $allPass && $c['pass'];
}

$report = [
    'generated_at' => gmdate('c'),
    'all_pass'     => $allPass,
    'checks'       => $checks,
    'wire_stats'   => [
        'crlf_count'        => substr_count($after, "\r\n"),
        'double_cr_count'   => substr_count($after, "\r\r\n"),
        'legacy_double_cr'  => substr_count($legacy, "\r\r\n"),
        'inner_header_leak' => str_contains($after, "\r\n\r\nContent-Type: text/html"),
    ],
    'wire_sample_after' => substr($body, 0, 500),
    'decoded_html_sample' => mb_substr($html, 0, 300),
];

$out = $root . '/storage/reports/smtp-mime-wire-verify-latest.json';
@mkdir(dirname($out), 0777, true);
file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);

if ($jsonOut) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    echo ($allPass ? 'PASS' : 'FAIL') . ' — SMTP wire verification after smtpDotStuff' . PHP_EOL;
    foreach ($checks as $c) {
        echo sprintf('  [%s] %s%s', $c['pass'] ? 'OK' : 'FAIL', $c['check'], $c['detail'] !== '' ? ' — ' . $c['detail'] : '') . PHP_EOL;
    }
    echo 'Report: ' . $out . PHP_EOL;
}

exit($allPass ? 0 : 1);
