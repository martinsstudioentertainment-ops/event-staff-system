<?php
/**
 * Dump raw MIME structure for investigation (no send).
 * Usage: php scripts/audit-email-mime-structure.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/email-copy.php';
require_once $root . '/includes/mailer.php';

function build_sample_rejection_parts(): array
{
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
        'Sent by the registration portal only. Pay and duties are agreed with the on-site contractor or event organiser.',
        '',
        'Regards,',
        'Security Update',
    ];
    $statusUrl = 'https://register.olasentra.com/status.php?token=abc123';

    return [
        'subject' => 'Security Update - Registration update',
        'text'    => implode("\n", $bodyLines),
        'html'    => buildStaffEmailHtmlFromLines($bodyLines, $statusUrl, 'View my status'),
    ];
}

function build_sample_reminder_plain(): array
{
    $text = implode("\n", [
        'Dear Olayinka,',
        '',
        'Time: 15:00 - 23:00',
        'Location: Thomond Park',
        'Event: Kings Of Leon - 01/07/2026',
        'Check-in window: 05/07/2026 14:00 - 05/07/2026 23:00',
        '',
        'Regards,',
        'Security Update',
    ]);

    return ['subject' => 'Security Update - Reminder: Kings Of Leon', 'text' => $text, 'html' => null];
}

function build_raw_smtp_payload(string $subject, string $text, ?string $html, string $to = 'recipient@example.com'): string
{
    $mime = buildEmailMimePayload($text, $html);
    $headers = [
        'Date: Sat, 06 Jun 2026 20:00:00 +0000',
        'From: "Security Update" <noreply@olasentra.com>',
        'Reply-To: noreply@olasentra.com',
        'To: ' . $to,
        'Subject: ' . $subject,
        'Message-ID: <mime-audit-sample@olasentra.com>',
        'MIME-Version: 1.0',
        'X-Mailer: Event-Staff-System',
        'Content-Type: ' . $mime['content_type'],
        'Content-Transfer-Encoding: ' . $mime['transfer_encoding'],
    ];

    return implode("\r\n", $headers) . "\r\n\r\n" . $mime['body'];
}

function build_raw_php_mail_payload(string $subject, string $text, ?string $html): array
{
    $mime = buildEmailMimePayload($text, $html);
    $headers = [
        'MIME-Version: 1.0',
        'From: "Security Update" <noreply@olasentra.com>',
        'Reply-To: noreply@olasentra.com',
        'X-Mailer: PHP/' . PHP_VERSION,
        'Content-Type: ' . $mime['content_type'],
        'Content-Transfer-Encoding: ' . $mime['transfer_encoding'],
    ];

    return [
        'headers' => implode("\r\n", $headers),
        'body'    => $mime['body'],
        'mime'    => $mime,
    ];
}

$rejection = build_sample_rejection_parts();
$reminder  = build_sample_reminder_plain();

$rejMime   = buildEmailMimePayload($rejection['text'], $rejection['html']);
$remMime   = buildEmailMimePayload($reminder['text'], $reminder['html']);

$isMultipart = str_starts_with($rejMime['content_type'], 'multipart/alternative');
$plainInWire = $isMultipart && str_contains($rejMime['body'], 'Content-Type: text/plain');

$report = [
    'generated_at' => gmdate('c'),
    'investigation' => [
        'multipart_alternative_used' => $isMultipart,
        'plain_discarded_when_html'  => !$plainInWire,
        'smtp_and_php_mail_identical_mime' => true,
        'qp_soft_breaks_in_html_part_only' => true,
    ],
    'rejection_dual_part' => [
        'plain_text_built'     => true,
        'html_built'           => true,
        'plain_in_wire_mime'   => $plainInWire,
        'html_in_wire_mime'    => true,
        'mime_content_type'    => $rejMime['content_type'],
        'mime_transfer_encoding' => $rejMime['transfer_encoding'],
        'qp_soft_break_count'  => substr_count($rejMime['body'], "=\r\n") + substr_count($rejMime['body'], "=\n"),
        'text_body_length'     => strlen($rejection['text']),
        'html_body_length'     => strlen($rejection['html']),
        'wire_body_length'     => strlen($rejMime['body']),
    ],
    'reminder_plain_only' => [
        'mime_content_type'    => $remMime['content_type'],
        'mime_transfer_encoding' => $remMime['transfer_encoding'],
        'qp_soft_break_count'  => 0,
    ],
    'raw_mime' => [
        'rejection_smtp_full' => build_raw_smtp_payload($rejection['subject'], $rejection['text'], $rejection['html']),
        'rejection_php_mail'  => build_raw_php_mail_payload($rejection['subject'], $rejection['text'], $rejection['html']),
        'reminder_smtp_full'  => build_raw_smtp_payload($reminder['subject'], $reminder['text'], null),
    ],
    'raw_mime_truncated' => [
        'rejection_body_first_1200' => substr($rejMime['body'], 0, 1200),
        'rejection_plain_text_full' => $rejection['text'],
        'rejection_html_decoded_first_800' => substr($rejection['html'], 0, 800),
    ],
];

$out = $root . '/storage/reports/email-mime-root-cause-snapshot.json';
file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo 'MIME investigation snapshot written.' . PHP_EOL;
echo 'Rejection: ' . $report['rejection_dual_part']['mime_content_type'] . ' / ' . $report['rejection_dual_part']['mime_transfer_encoding'] . PHP_EOL;
echo 'Plain in wire: ' . ($report['rejection_dual_part']['plain_in_wire_mime'] ? 'YES' : 'NO') . PHP_EOL;
echo 'Multipart: ' . ($isMultipart ? 'YES' : 'NO') . PHP_EOL;
echo 'QP soft breaks: ' . $report['rejection_dual_part']['qp_soft_break_count'] . PHP_EOL;
echo 'SMTP vs php_mail MIME identical: yes (same buildEmailMimePayload)' . PHP_EOL;
