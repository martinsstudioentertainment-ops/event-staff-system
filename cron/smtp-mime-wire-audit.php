<?php
/**
 * Production SMTP wire audit — confirms deployed smtpDotStuff() on server.
 *
 *   https://register.olasentra.com/cron/smtp-mime-wire-audit.php?key=email-encoding-verify-20260606
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/email-copy.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/config.php';

const SMTP_WIRE_AUDIT_FALLBACK_KEY = 'email-encoding-verify-20260606';

$key = trim((string) ($_GET['key'] ?? ''));
$allowed = [SMTP_WIRE_AUDIT_FALLBACK_KEY];
try {
    $pdo = getDB();
    $cronKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    if ($cronKey !== '') {
        $allowed[] = $cronKey;
    }
} catch (Throwable) {
    // config optional for key list
}

$keyOk = false;
foreach ($allowed as $allowedKey) {
    if ($key !== '' && hash_equals($allowedKey, $key)) {
        $keyOk = true;
        break;
    }
}
if (!$keyOk) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$bodyLines = [
    'Dear Verify,',
    '',
    'SMTP wire audit sample body.',
    '',
    'View your registration status anytime:',
    'https://register.olasentra.com/status.php?token=wireaudit',
];
$text = implode("\n", $bodyLines);
$html = buildStaffEmailHtmlFromLines($bodyLines, 'https://register.olasentra.com/status.php?token=wireaudit', 'View my status');
$mime = buildEmailMimePayload($text, $html);
$headers = [
    'MIME-Version: 1.0',
    'Content-Type: ' . $mime['content_type'],
    'Content-Transfer-Encoding: ' . $mime['transfer_encoding'],
];
$payloadBefore = implode("\r\n", $headers) . "\r\n\r\n" . $mime['body'];
$payloadAfter  = smtpDotStuff($payloadBefore);

$legacyDotStuff = static function (string $payload): string {
    $payload = str_replace("\n", "\r\n", $payload);

    return preg_replace('/^\./m', '..', $payload) ?? $payload;
};
$payloadLegacy = $legacyDotStuff($payloadBefore);

$report = [
    'ok' => substr_count($payloadAfter, "\r\r\n") === 0,
    'generated_at' => gmdate('c'),
    'server' => 'production',
    'fix_status' => substr_count($payloadAfter, "\r\r\n") === 0 ? 'fixed' : 'broken',
    'legacy_double_cr' => substr_count($payloadLegacy, "\r\r\n"),
    'current_double_cr' => substr_count($payloadAfter, "\r\r\n"),
    'is_multipart' => str_starts_with($mime['content_type'], 'multipart/alternative'),
];

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
