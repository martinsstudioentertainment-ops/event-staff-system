<?php

/**
 * Signed SSO tokens between main admin and apply.olasentra.com admin.
 */

require_once __DIR__ . '/helpers.php';

function getApplySsoSecret(): string
{
    if (defined('APPLY_SSO_SECRET') && (string) APPLY_SSO_SECRET !== '') {
        return (string) APPLY_SSO_SECRET;
    }

    if (defined('DB_NAME') && defined('DB_PASS')) {
        return hash('sha256', (string) DB_NAME . '|' . (string) DB_PASS . '|olasentra-apply-sso-v1');
    }

    return hash('sha256', 'olasentra-apply-sso-dev');
}

function createApplySsoToken(int $adminId, int $ttlSeconds = 120): string
{
    $payload = [
        'admin_id' => $adminId,
        'exp'      => time() + max(30, $ttlSeconds),
        'nonce'    => bin2hex(random_bytes(8)),
    ];

    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $b64  = base64UrlEncode($json);
    $sig  = hash_hmac('sha256', $b64, getApplySsoSecret());

    return $b64 . '.' . $sig;
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

function getApplySsoCookieName(): string
{
    return 'olasentra_apply_admin';
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

/** Set cross-subdomain cookie so apply.olasentra.com can sign in without sso.php. */
function setApplySsoCookie(int $adminId, int $ttlSeconds = 300): void
{
    if (headers_sent()) {
        return;
    }

    $token  = createApplySsoToken($adminId, $ttlSeconds);
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $domain = getApplySsoCookieDomain();

    $options = [
        'expires'  => time() + max(30, $ttlSeconds),
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];

    if ($domain !== '') {
        $options['domain'] = $domain;
    }

    setcookie(getApplySsoCookieName(), $token, $options);
}
