<?php
/**
 * Event Staff System — Lightweight SMTP client (AUTH LOGIN, TLS/SSL)
 */

/** @var string Last SMTP failure (for admin test email). */
$GLOBALS['_event_staff_last_smtp_error'] = '';

function getLastSmtpError(): string
{
    return (string) ($GLOBALS['_event_staff_last_smtp_error'] ?? '');
}

function setLastSmtpError(string $message): void
{
    $GLOBALS['_event_staff_last_smtp_error'] = $message;
}

/**
 * @param resource $socket
 */
function smtpFail(string $step, string $response = ''): bool
{
    $detail = $response !== '' ? ' — ' . $response : '';
    setLastSmtpError($step . $detail);

    return false;
}

/**
 * @param array{host: string, port: int, encryption: string, username: string, password: string} $config
 */
function sendSmtpMessage(array $config, string $to, string $subject, string $body, string $fromName, string $fromEmail, ?string $htmlBody = null): bool
{
    setLastSmtpError('');
    $host        = trim($config['host']);
    $port        = (int) ($config['port'] ?: 587);
    $encryption  = strtolower(trim($config['encryption'] ?: 'tls'));
    $username    = trim($config['username']);
    $password    = (string) ($config['password'] ?? '');
    $fromEmail   = trim($fromEmail);
    $fromName    = trim($fromName);
    $to          = trim($to);

    if ($host === '') {
        return smtpFail('SMTP host is empty');
    }
    if ($fromEmail === '') {
        return smtpFail('From email is empty — set it in Email settings');
    }
    if ($to === '') {
        return smtpFail('Recipient email is empty');
    }

    $ehloHost = 'olasentra.com';
    if (str_contains($fromEmail, '@')) {
        $ehloHost = substr($fromEmail, strpos($fromEmail, '@') + 1) ?: $ehloHost;
    }

    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $errno  = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        30,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]])
    );

    if (!$socket) {
        return smtpFail("Cannot connect to {$host}:{$port}", $errstr !== '' ? "{$errno} {$errstr}" : '');
    }

    stream_set_timeout($socket, 30);

    if (!smtpExpect($socket, [220])) {
        $greet = getLastSmtpError();
        fclose($socket);

        return smtpFail('SMTP greeting failed', $greet);
    }
    if (!smtpCommand($socket, 'EHLO ' . $ehloHost, [250])) {
        fclose($socket);

        return smtpFail('EHLO failed', getLastSmtpError() !== '' ? getLastSmtpError() : smtpRead($socket));
    }

    if ($encryption === 'tls') {
        if (!smtpCommand($socket, 'STARTTLS', [220])) {
            fclose($socket);

            return smtpFail('STARTTLS failed', smtpRead($socket));
        }

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);

            return smtpFail('TLS handshake failed');
        }

        if (!smtpCommand($socket, 'EHLO ' . $ehloHost, [250])) {
            fclose($socket);

            return smtpFail('EHLO after TLS failed', smtpRead($socket));
        }
    }

    if ($username !== '') {
        if ($password === '') {
            fclose($socket);

            return smtpFail('SMTP password is empty — re-enter mailbox password and Save email settings');
        }
        if (!smtpCommand($socket, 'AUTH LOGIN', [334])) {
            fclose($socket);

            return smtpFail('AUTH LOGIN rejected', smtpRead($socket));
        }
        if (!smtpCommand($socket, base64_encode($username), [334])) {
            fclose($socket);

            return smtpFail('SMTP username rejected', smtpRead($socket));
        }
        if (!smtpCommand($socket, base64_encode($password), [235])) {
            fclose($socket);

            return smtpFail('SMTP password rejected (wrong password or username must be full email)', smtpRead($socket));
        }
    }

    $fromHeader = sprintf('"%s" <%s>', str_replace('"', '', $fromName), $fromEmail);
    $encodedSubject = smtpEncodeHeader($subject);
    $messageDomain  = $ehloHost;
    $messageId      = '<' . bin2hex(random_bytes(12)) . '@' . $messageDomain . '>';

    $headers = [
        'Date: ' . date('r'),
        'From: ' . $fromHeader,
        'Reply-To: ' . $fromEmail,
        'To: ' . $to,
        'Subject: ' . $encodedSubject,
        'Message-ID: ' . $messageId,
        'MIME-Version: 1.0',
        'X-Mailer: Event-Staff-System',
    ];

    if ($htmlBody !== null && trim($htmlBody) !== '') {
        $boundary = '=_' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $payloadBody = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . smtpNormalizeBody($body) . "\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . smtpNormalizeBody($htmlBody) . "\r\n\r\n"
            . "--{$boundary}--";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $payloadBody = smtpNormalizeBody($body);
    }

    $payload = implode("\r\n", $headers) . "\r\n\r\n" . $payloadBody;
    $payload = smtpDotStuff($payload);

    if (!smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250])) {
        fclose($socket);

        return smtpFail('MAIL FROM rejected — From email must match SMTP mailbox', smtpRead($socket));
    }
    if (!smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251])) {
        fclose($socket);

        return smtpFail('RCPT TO rejected', smtpRead($socket));
    }
    if (!smtpCommand($socket, 'DATA', [354])) {
        fclose($socket);

        return smtpFail('DATA command failed', smtpRead($socket));
    }
    if (fwrite($socket, $payload . "\r\n.\r\n") === false || !smtpExpect($socket, [250])) {
        fclose($socket);

        return smtpFail('Message body rejected', smtpRead($socket));
    }

    smtpCommand($socket, 'QUIT', [221]);
    fclose($socket);

    return true;
}

/**
 * @param resource $socket
 * @param int[] $expectedCodes
 */
function smtpExpect($socket, array $expectedCodes): bool
{
    $response = smtpRead($socket);
    if ($response === '') {
        return false;
    }

    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        setLastSmtpError($response);

        return false;
    }

    return true;
}

/**
 * @param resource $socket
 * @param int[] $expectedCodes
 */
function smtpCommand($socket, string $command, array $expectedCodes): bool
{
    if (!smtpWrite($socket, $command)) {
        return false;
    }

    return smtpExpect($socket, $expectedCodes);
}

/**
 * @param resource $socket
 */
function smtpWrite($socket, string $data): bool
{
    return fwrite($socket, $data . "\r\n") !== false;
}

/**
 * @param resource $socket
 */
function smtpRead($socket): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response = $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    return trim($response);
}

function smtpEncodeHeader(string $value): string
{
    if (preg_match('/[^\x20-\x7E]/', $value)) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    return $value;
}

function smtpNormalizeBody(string $body): string
{
    return str_replace(["\r\n", "\r"], "\n", $body);
}

function smtpDotStuff(string $payload): string
{
    $payload = str_replace("\n", "\r\n", $payload);

    return preg_replace('/^\./m', '..', $payload) ?? $payload;
}
