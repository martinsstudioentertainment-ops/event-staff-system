<?php

/**

 * Event Staff System — Email sending

 */



require_once __DIR__ . '/settings-repository.php';

/**
 * Normalize line endings for email bodies.
 */
function normalizeEmailLines(string $body): string
{
    return str_replace(["\r\n", "\r"], "\n", $body);
}

/**
 * Build a single-part MIME body (HTML when available — avoids raw multipart on mobile).
 *
 * @return array{content_type: string, transfer_encoding: string, body: string}
 */
function buildEmailMimePayload(string $textBody, ?string $htmlBody): array
{
    $html = $htmlBody !== null ? trim($htmlBody) : '';
    if ($html !== '') {
        return [
            'content_type'      => 'text/html; charset=UTF-8',
            'transfer_encoding' => 'quoted-printable',
            'body'              => quoted_printable_encode(normalizeEmailLines($html)),
        ];
    }

    return [
        'content_type'      => 'text/plain; charset=UTF-8',
        'transfer_encoding' => '8bit',
        'body'              => normalizeEmailLines($textBody),
    ];
}

require_once __DIR__ . '/smtp-mailer.php';



function getMailLogPath(): string

{

    $dir = dirname(__DIR__) . '/storage/logs';

    if (!is_dir($dir)) {

        mkdir($dir, 0755, true);

    }

    return $dir . '/mail.log';

}



function logMailAttempt(string $to, string $subject, string $body, bool $sent, string $note = ''): void

{

    $line = sprintf(

        "[%s] %s | To: %s | Subject: %s%s\n%s\n---\n",

        date('Y-m-d H:i:s'),

        $sent ? 'SENT' : 'LOGGED',

        $to,

        $subject,

        $note !== '' ? ' | ' . $note : '',

        $body

    );

    file_put_contents(getMailLogPath(), $line, FILE_APPEND | LOCK_EX);

}



/**

 * @return array{host: string, port: int, encryption: string, username: string, password: string}

 */

function getSmtpConfig(PDO $pdo): array

{

    return [

        'host'        => getSetting($pdo, 'smtp_host', ''),

        'port'        => (int) getSetting($pdo, 'smtp_port', '587'),

        'encryption'  => getSetting($pdo, 'smtp_encryption', 'tls'),

        'username'    => getSetting($pdo, 'smtp_username', ''),

        'password'    => getSetting($pdo, 'smtp_password', ''),

    ];

}



function getMailTransport(PDO $pdo): string

{

    $transport = getSetting($pdo, 'mail_transport', 'php_mail');



    return in_array($transport, ['php_mail', 'smtp', 'log'], true) ? $transport : 'php_mail';

}



function sendEmail(PDO $pdo, string $to, string $subject, string $body, ?string $htmlBody = null): bool

{

    $to = trim($to);

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {

        return false;

    }



    $fromName  = getSetting($pdo, 'mail_from_name', 'Event Staff System');

    $fromEmail = getSetting($pdo, 'mail_from_email', 'noreply@event-staff.local');

    $transport = getMailTransport($pdo);



    if ($transport === 'log') {

        logMailAttempt($to, $subject, $htmlBody !== null && $htmlBody !== '' ? $htmlBody : $body, false, 'log-only mode');

        return true;

    }



    if ($transport === 'smtp') {

        $sent = sendSmtpMessage(getSmtpConfig($pdo), $to, $subject, $body, $fromName, $fromEmail, $htmlBody);

        if ($sent) {

            logMailAttempt($to, $subject, $htmlBody !== null && $htmlBody !== '' ? $htmlBody : $body, true, 'smtp');

            return true;

        }



        $smtpNote = getLastSmtpError();
        logMailAttempt(
            $to,
            $subject,
            $body,
            false,
            $smtpNote !== '' ? 'smtp failed: ' . $smtpNote : 'smtp failed'
        );

        return false;

    }



    $fromHeader = sprintf('"%s" <%s>', str_replace('"', '', $fromName), $fromEmail);
    $mime       = buildEmailMimePayload($body, $htmlBody);
    $headers    = [
        'MIME-Version: 1.0',
        'From: ' . $fromHeader,
        'Reply-To: ' . $fromEmail,
        'X-Mailer: PHP/' . phpversion(),
        'Content-Type: ' . $mime['content_type'],
        'Content-Transfer-Encoding: ' . $mime['transfer_encoding'],
    ];
    $message = $mime['body'];



    $sent = @mail($to, $subject, $message, implode("\r\n", $headers));



    if (!$sent) {

        logMailAttempt($to, $subject, $body, false, 'mail() failed — saved to storage/logs/mail.log');

        return false;

    }



    logMailAttempt($to, $subject, $body, true, 'php_mail');

    return true;

}



function sendTestEmail(PDO $pdo, string $to): bool|string

{

    $to = trim($to);

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {

        return 'Please enter a valid test email address.';

    }

    $transport = getMailTransport($pdo);
    $fromEmail = trim(getSetting($pdo, 'mail_from_email', ''));

    if ($transport === 'smtp') {
        $host = trim(getSetting($pdo, 'smtp_host', ''));
        $user = trim(getSetting($pdo, 'smtp_username', ''));
        $pass = trim(getSetting($pdo, 'smtp_password', ''));

        if ($host === '') {
            return 'SMTP host is empty. For Namecheap/cPanel use mail.olasentra.com (see Email Accounts → Connect devices).';
        }
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return 'From email is invalid. Use your mailbox address, e.g. noreply@olasentra.com';
        }
        if ($user === '') {
            return 'SMTP username is empty. Use the full email (e.g. noreply@olasentra.com).';
        }
        if ($pass === '') {
            return 'SMTP password is empty. Re-enter the mailbox password, Save email settings, then send test again.';
        }
    } elseif ($transport === 'php_mail') {
        return 'Transport is PHP mail(), which usually fails on shared hosting. Switch to SMTP.';
    }

    $siteName = getSiteName($pdo);

    $subject  = $siteName . ' — Test Email';

    require_once __DIR__ . '/email-copy.php';

    $body     = implode("\n", [
        'This is a test email from ' . $siteName . '.',
        '',
        'Transport: ' . getMailTransport($pdo),
        'Sent at: ' . date('Y-m-d H:i:s'),
        '',
        'If you received this, your email settings are working.',
        '',
        getEmailShortFooter($pdo),
    ]);



    if (!sendEmail($pdo, $to, $subject, $body)) {

        $detail = getLastSmtpError();
        if ($detail !== '') {
            return 'Test email failed: ' . $detail;
        }

        return 'Test email could not be sent. Check storage/logs/mail.log on the server.';

    }



    return true;

}

