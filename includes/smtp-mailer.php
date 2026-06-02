<?php
/**
 * Event Staff System — Lightweight SMTP client (AUTH LOGIN, TLS/SSL)
 */

/**
 * @param array{host: string, port: int, encryption: string, username: string, password: string} $config
 */
function sendSmtpMessage(array $config, string $to, string $subject, string $body, string $fromName, string $fromEmail, ?string $htmlBody = null): bool
{
    $host        = trim($config['host']);
    $port        = (int) ($config['port'] ?: 587);
    $encryption  = strtolower(trim($config['encryption'] ?: 'tls'));
    $username    = trim($config['username']);
    $password    = (string) ($config['password'] ?? '');
    $fromEmail   = trim($fromEmail);
    $fromName    = trim($fromName);
    $to          = trim($to);

    if ($host === '' || $fromEmail === '' || $to === '') {
        return false;
    }

    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $errno  = 0;
    $errstr = '';

    $socket = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]])
    );

    if (!$socket) {
        return false;
    }

    stream_set_timeout($socket, 20);

    if (!smtpExpect($socket, [220]) || !smtpCommand($socket, 'EHLO localhost', [250])) {
        fclose($socket);
        return false;
    }

    if ($encryption === 'tls') {
        if (!smtpCommand($socket, 'STARTTLS', [220])) {
            fclose($socket);
            return false;
        }

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return false;
        }

        if (!smtpCommand($socket, 'EHLO localhost', [250])) {
            fclose($socket);
            return false;
        }
    }

    if ($username !== '' && $password !== '') {
        if (!smtpCommand($socket, 'AUTH LOGIN', [334])
            || !smtpCommand($socket, base64_encode($username), [334])
            || !smtpCommand($socket, base64_encode($password), [235])) {
            fclose($socket);
            return false;
        }
    }

    $fromHeader = sprintf('"%s" <%s>', str_replace('"', '', $fromName), $fromEmail);
    $encodedSubject = smtpEncodeHeader($subject);
    $messageId      = '<' . bin2hex(random_bytes(8)) . '@event-staff.local>';

    $headers = [
        'Date: ' . date('r'),
        'From: ' . $fromHeader,
        'To: ' . $to,
        'Subject: ' . $encodedSubject,
        'Message-ID: ' . $messageId,
        'MIME-Version: 1.0',
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

    $ok = smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250])
        && smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251])
        && smtpCommand($socket, 'DATA', [354])
        && fwrite($socket, $payload . "\r\n.\r\n") !== false
        && smtpExpect($socket, [250]);

    smtpCommand($socket, 'QUIT', [221]);
    fclose($socket);

    return $ok;
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
    return in_array($code, $expectedCodes, true);
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
