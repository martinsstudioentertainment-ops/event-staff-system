<?php

declare(strict_types=1);

require_once __DIR__ . '/main-admin-bridge.php';

function applyBase64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function getApplySsoCookieName(): string
{
    return 'olasentra_apply_admin';
}

function apply_sso_secret_from_main_db_config(): ?string
{
    $configFile = __DIR__ . '/../config/eventstaff-database.php';
    if (!is_readable($configFile)) {
        return null;
    }

    $host = $db = $user = $pass = '';
    $eventPdo = null;
    try {
        include $configFile;
    } catch (Throwable $e) {
        error_log('[ApplySSO] eventstaff-database include: ' . $e->getMessage());

        return null;
    }

    if ($db !== '' && $pass !== '') {
        return hash('sha256', (string) $db . '|' . (string) $pass . '|olasentra-apply-sso-v1');
    }

    return null;
}

function getApplySsoCookieDomain(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;

    if ($host === 'olasentra.com' || str_ends_with($host, '.olasentra.com')) {
        return '.olasentra.com';
    }

    return '';
}

function clearApplySsoCookie(): void
{
    if (headers_sent()) {
        return;
    }

    $secure  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $options = [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    $domain = getApplySsoCookieDomain();
    if ($domain !== '') {
        $options['domain'] = $domain;
    }

    setcookie(getApplySsoCookieName(), '', $options);
}

function getApplySsoSecret(): string
{
    static $secret = null;
    if (is_string($secret) && $secret !== '') {
        return $secret;
    }

    if (defined('APPLY_SSO_SECRET') && (string) APPLY_SSO_SECRET !== '') {
        $secret = (string) APPLY_SSO_SECRET;

        return $secret;
    }

    $local = __DIR__ . '/../config/sso.local.php';
    if (is_readable($local)) {
        require $local;
        if (defined('APPLY_SSO_SECRET') && (string) APPLY_SSO_SECRET !== '') {
            $secret = (string) APPLY_SSO_SECRET;

            return $secret;
        }
    }

    $env = trim((string) (getenv('APPLY_SSO_SECRET') ?: ''));
    if ($env !== '') {
        $secret = $env;

        return $secret;
    }

    $fromMainDb = apply_sso_secret_from_main_db_config();
    if ($fromMainDb !== null) {
        $secret = $fromMainDb;

        return $secret;
    }

    $secret = hash('sha256', 'olasentra-apply-sso-dev');

    return $secret;
}

/**
 * @return array{admin_id: int, exp: int, nonce: string}|null
 */
function verifyApplySsoToken(string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }

    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return null;
    }

    [$b64, $sig] = $parts;
    $expected    = hash_hmac('sha256', $b64, getApplySsoSecret());
    if (!hash_equals($expected, $sig)) {
        return null;
    }

    $json = base64_decode(strtr($b64, '-_', '+/'), true);
    if ($json === false) {
        return null;
    }

    $payload = json_decode($json, true);
    if (!is_array($payload) || empty($payload['admin_id']) || empty($payload['exp'])) {
        return null;
    }

    if ((int) $payload['exp'] < time()) {
        return null;
    }

    return [
        'admin_id' => (int) $payload['admin_id'],
        'exp'      => (int) $payload['exp'],
        'nonce'    => (string) ($payload['nonce'] ?? ''),
    ];
}
