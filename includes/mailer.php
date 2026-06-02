<?php

/**

 * Event Staff System — Email sending

 */



require_once __DIR__ . '/settings-repository.php';

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



        logMailAttempt($to, $subject, $body, false, 'smtp failed — saved to storage/logs/mail.log');

        return false;

    }



    $fromHeader = sprintf('"%s" <%s>', str_replace('"', '', $fromName), $fromEmail);

    if ($htmlBody !== null && trim($htmlBody) !== '') {
        $boundary = '=_' . bin2hex(random_bytes(12));
        $headers = [
            'MIME-Version: 1.0',
            'From: ' . $fromHeader,
            'Reply-To: ' . $fromEmail,
            'X-Mailer: PHP/' . phpversion(),
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $message = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
            . $body . "\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
            . $htmlBody . "\r\n\r\n"
            . "--{$boundary}--";
    } else {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/plain; charset=UTF-8',
            'From: ' . $fromHeader,
            'Reply-To: ' . $fromEmail,
            'X-Mailer: PHP/' . phpversion(),
        ];
        $message = $body;
    }



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



    $siteName = getSiteName($pdo);

    $subject  = $siteName . ' — Test Email';

    $body     = implode("\n", [

        'This is a test email from ' . $siteName . '.',

        '',

        'Transport: ' . getMailTransport($pdo),

        'Sent at: ' . date('Y-m-d H:i:s'),

        '',

        'If you received this, your email settings are working.',

    ]);



    if (!sendEmail($pdo, $to, $subject, $body)) {

        return 'Test email could not be sent. Check SMTP settings or storage/logs/mail.log.';

    }



    return true;

}

