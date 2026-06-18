<?php

declare(strict_types=1);

require_once __DIR__ . '/schema/mobile-api-schema.php';

function mobileJwtEncode(array $payload, string $secret): string
{
    $header = mobileJwtBase64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR));
    $body   = mobileJwtBase64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
    $sig    = mobileJwtBase64UrlEncode(hash_hmac('sha256', $header . '.' . $body, $secret, true));

    return $header . '.' . $body . '.' . $sig;
}

/**
 * @return array<string, mixed>|null
 */
function mobileJwtDecode(string $jwt, string $secret): ?array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }

    [$headerB64, $payloadB64, $sigB64] = $parts;
    $expected = mobileJwtBase64UrlEncode(hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $secret, true));
    if (!hash_equals($expected, $sigB64)) {
        return null;
    }

    $payloadJson = mobileJwtBase64UrlDecode($payloadB64);
    if ($payloadJson === null) {
        return null;
    }

    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        return null;
    }

    $exp = (int) ($payload['exp'] ?? 0);
    if ($exp > 0 && $exp < time()) {
        return null;
    }

    if (($payload['aud'] ?? '') !== 'olasentra-mobile') {
        return null;
    }

    return $payload;
}

function mobileJwtBase64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function mobileJwtBase64UrlDecode(string $data): ?string
{
    $remainder = strlen($data) % 4;
    if ($remainder > 0) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    $decoded = base64_decode(strtr($data, '-_', '+/'), true);

    return $decoded === false ? null : $decoded;
}

function mobileIssueAccessToken(PDO $pdo, array $staff): string
{
    $staffId = (int) ($staff['id'] ?? 0);
    $email   = strtolower(trim((string) ($staff['email'] ?? '')));
    $ttl     = mobileJwtAccessTtl($pdo);
    $now     = time();

    $payload = [
        'sub'   => $staffId,
        'email' => $email,
        'iat'   => $now,
        'exp'   => $now + $ttl,
        'aud'   => 'olasentra-mobile',
        'jti'   => bin2hex(random_bytes(8)),
    ];

    return mobileJwtEncode($payload, mobileJwtSecret($pdo));
}

/**
 * @return array{ok: bool, email?: string, message?: string}
 */
function mobileVerifyGoogleIdToken(PDO $pdo, string $idToken): array
{
    $idToken = trim($idToken);
    if ($idToken === '') {
        return ['ok' => false, 'message' => 'Google ID token is required.'];
    }

    $clientId = trim(getSetting($pdo, 'google_oauth_client_id', ''));
    $url      = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $responseBody = curl_exec($ch);
    $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !is_string($responseBody)) {
        return ['ok' => false, 'message' => 'Invalid Google ID token.'];
    }

    $data = json_decode($responseBody, true);
    if (!is_array($data)) {
        return ['ok' => false, 'message' => 'Could not verify Google account.'];
    }

    if ($clientId !== '' && ($data['aud'] ?? '') !== $clientId) {
        return ['ok' => false, 'message' => 'Google token audience mismatch.'];
    }

    $verified = ($data['email_verified'] ?? '') === 'true' || ($data['email_verified'] ?? false) === true;
    if (!$verified) {
        return ['ok' => false, 'message' => 'Your Google email is not verified.'];
    }

    $email = strtolower(trim((string) ($data['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Could not read email from Google token.'];
    }

    return ['ok' => true, 'email' => $email];
}

function mobileStaffIsBlacklisted(PDO $pdo, string $email, ?array $staff = null): bool
{
    if ($staff !== null && (int) ($staff['is_blacklisted'] ?? 0) === 1) {
        return true;
    }

    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }

    try {
        require_once __DIR__ . '/../staff-blacklist.php';
        if (function_exists('isEmailBlacklisted') && isEmailBlacklisted($pdo, $email)) {
            return true;
        }
    } catch (Throwable $e) {
        // Fall through to direct query.
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT id FROM staff_blacklist WHERE LOWER(email) = :email AND removed_at IS NULL LIMIT 1'
        );
        $stmt->execute(['email' => $email]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
