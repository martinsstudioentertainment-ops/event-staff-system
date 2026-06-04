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

function getApplySsoSecret(): string
{
    loadMainEventStaffConfig();

    if (defined('APPLY_SSO_SECRET') && (string) APPLY_SSO_SECRET !== '') {
        return (string) APPLY_SSO_SECRET;
    }

    $local = __DIR__ . '/../config/sso.local.php';
    if (is_readable($local)) {
        require $local;
        if (defined('APPLY_SSO_SECRET') && (string) APPLY_SSO_SECRET !== '') {
            return (string) APPLY_SSO_SECRET;
        }
    }

    if (defined('DB_NAME') && defined('DB_PASS')) {
        return hash('sha256', (string) DB_NAME . '|' . (string) DB_PASS . '|olasentra-apply-sso-v1');
    }

    return hash('sha256', 'olasentra-apply-sso-dev');
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
