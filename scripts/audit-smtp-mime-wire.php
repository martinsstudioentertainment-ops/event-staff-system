<?php
/**
 * Audit SMTP wire payload after smtpDotStuff — explains production MIME rendering defects.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/email-copy.php';
require_once $root . '/includes/mailer.php';

$bodyLines = [
    'Dear Olayinka,',
    '',
    'Thank you for your interest. Your staff registration was not approved at this time.',
    '',
    '* Kings Of Leon - 01/07/2026 - Static Security',
    '  Contractor listed for this shift: Acme Security (confirm pay and duties with them).',
    '',
    'View your registration status anytime:',
    'https://register.olasentra.com/status.php?token=abc123',
    '',
    'Sent by the registration portal only.',
    '',
    'Regards,',
    'Security Update',
];
$text = implode("\n", $bodyLines);
$html = buildStaffEmailHtmlFromLines($bodyLines, 'https://register.olasentra.com/status.php?token=abc123', 'View my status');

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
$payloadBefore = implode("\r\n", $headers) . "\r\n\r\n" . $mime['body'];

$legacyDotStuff = static function (string $payload): string {
    $payload = str_replace("\n", "\r\n", $payload);

    return preg_replace('/^\./m', '..', $payload) ?? $payload;
};

$payloadAfterLegacy = $legacyDotStuff($payloadBefore);
$payloadAfter       = smtpDotStuff($payloadBefore);
$boundaryPos        = strpos($payloadAfter, '--=_Olasentra');
$boundaryPosLegacy  = strpos($payloadAfterLegacy, '--=_Olasentra');

$report = [
    'generated_at' => gmdate('c'),
    'fix_status' => substr_count($payloadAfter, "\r\r\n") === 0 ? 'fixed' : 'broken',
    'mime_builder' => [
        'content_type' => $mime['content_type'],
        'transfer_encoding' => $mime['transfer_encoding'],
        'is_multipart' => str_starts_with($mime['content_type'], 'multipart/alternative'),
        'plain_in_body' => str_contains($mime['body'], 'Content-Type: text/plain'),
        'html_in_body' => str_contains($mime['body'], 'Content-Type: text/html'),
    ],
    'before_fix_legacy_dotstuff' => [
        'double_cr_count' => substr_count($payloadAfterLegacy, "\r\r\n"),
        'corruption_detected' => substr_count($payloadAfterLegacy, "\r\r\n") > 0,
        'wire_sample' => $boundaryPosLegacy !== false
            ? substr($payloadAfterLegacy, $boundaryPosLegacy, 600)
            : '',
    ],
    'after_fix_dotstuff' => [
        'crlf_count' => substr_count($payloadAfter, "\r\n"),
        'double_cr_count' => substr_count($payloadAfter, "\r\r\n"),
        'corruption_detected' => substr_count($payloadAfter, "\r\r\n") > 0,
        'wire_sample' => $boundaryPos !== false
            ? substr($payloadAfter, $boundaryPos, 600)
            : '',
    ],
    'client_impact' => [
        'multipart_parsing_likely_broken' => substr_count($payloadAfter, "\r\r\n") > 0,
        'inner_headers_visible_as_body_text' => substr_count($payloadAfter, "\r\r\n") > 0,
        'qp_html_visible_if_parser_fails' => substr_count($payloadAfter, "\r\r\n") > 0,
    ],
];

$out = $root . '/storage/reports/smtp-mime-wire-audit-latest.json';
@mkdir(dirname($out), 0777, true);
file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

echo 'SMTP wire audit — multipart: ' . ($report['mime_builder']['is_multipart'] ? 'YES' : 'NO') . PHP_EOL;
echo 'Legacy dotstuff corruption (\\r\\r\\n): ' . ($report['before_fix_legacy_dotstuff']['corruption_detected'] ? 'YES' : 'NO') . PHP_EOL;
echo 'Current smtpDotStuff corruption (\\r\\r\\n): ' . ($report['after_fix_dotstuff']['corruption_detected'] ? 'YES' : 'NO') . PHP_EOL;
echo 'Report: ' . $out . PHP_EOL;
