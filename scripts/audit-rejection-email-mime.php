<?php
/**
 * Audit snapshot: rejection email plain/HTML/MIME (no send).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/email-copy.php';
require_once $root . '/includes/mailer.php';

$siteName  = 'Security Update';
$firstName = 'Olayinka';
$statusUrl = 'https://register.olasentra.com/status.php?token=abc123';

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
$mime = buildEmailMimePayload($text, $html);

$plainInWire = null;
if (preg_match(
    '/Content-Type:\s*text\/plain[^\r\n]*\r\nContent-Transfer-Encoding:\s*[^\r\n]+\r\n\r\n(.*?)(?=\r\n--)/s',
    $mime['body'],
    $m
) === 1) {
    $plainInWire = str_replace("\r\n", "\n", $m[1]);
}

$htmlRaw = null;
if (preg_match(
    '/Content-Type:\s*text\/html[^\r\n]*\r\nContent-Transfer-Encoding:\s*[^\r\n]+\r\n\r\n(.*?)(?=\r\n--|$)/s',
    $mime['body'],
    $m
) === 1) {
    $htmlRaw = quoted_printable_decode($m[1]);
}

$report = [
    'generated_at'              => gmdate('c'),
    'template_function'         => 'sendConsolidatedRejectionEmail()',
    'plain_text_part'           => $text,
    'html_part'                 => $html,
    'mime_content_type'         => $mime['content_type'],
    'mime_transfer_encoding'    => $mime['transfer_encoding'],
    'plain_text_sent_in_mime'   => $plainInWire !== null,
    'html_sent_in_mime'         => $htmlRaw !== null,
    'multipart_alternative'     => str_starts_with($mime['content_type'], 'multipart/alternative'),
    'qp_soft_break_count'       => substr_count($mime['body'], "=\r\n") + substr_count($mime['body'], "=\n"),
    'mime_sample'               => substr($mime['body'], 0, 900),
    'plain_wire_extract'        => $plainInWire,
    'html_decoded_sample'       => $htmlRaw !== null ? substr($htmlRaw, 0, 600) : '',
];

$out = $root . '/storage/reports/email-rejection-template-audit-snapshot.json';
file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo ($report['multipart_alternative'] ? 'OK multipart/alternative' : 'FAIL not multipart') . PHP_EOL;
echo 'Plain in wire: ' . ($report['plain_text_sent_in_mime'] ? 'YES' : 'NO') . PHP_EOL;
echo 'QP soft breaks: ' . $report['qp_soft_break_count'] . PHP_EOL;
echo 'Report: storage/reports/email-rejection-template-audit-snapshot.json' . PHP_EOL;
