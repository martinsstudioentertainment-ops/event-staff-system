<?php

declare(strict_types=1);

/**
 * One-off production diagnostic — delete after use.
 * Upload to public_html/scripts/diag-otp-send.php and open with ?key=olasentra-diag
 */

if (($_GET['key'] ?? '') !== 'olasentra-diag') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/mobile/services/MobileOtpService.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = getDB();
$email = trim((string) ($_GET['email'] ?? 'testotp99988@example.com'));

echo "Transport: " . getMailTransport($pdo) . "\n";
echo "SMTP host: " . getSetting($pdo, 'smtp_host', '') . "\n\n";

try {
    $html = buildEmailHtmlFromPlainText($pdo, 'Test subject', "Line one\nLine two");
    echo "buildEmailHtmlFromPlainText: OK (" . strlen($html) . " bytes)\n\n";
} catch (Throwable $e) {
    echo "buildEmailHtmlFromPlainText FAILED: " . $e->getMessage() . "\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n\n";
}

$subject = 'Olasentra diag test';
$body    = "Diagnostic test at " . date('c');
$sent    = sendEmail($pdo, $email, $subject, $body, null);
echo "sendEmail to {$email}: " . ($sent ? 'SENT' : 'FAILED') . "\n";
$smtpErr = function_exists('getLastSmtpError') ? getLastSmtpError() : '';
if ($smtpErr !== '') {
    echo "SMTP error: {$smtpErr}\n";
}

echo "\nmobileOtpSend registration:\n";
$result = mobileOtpSend($pdo, $email, 'registration');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
