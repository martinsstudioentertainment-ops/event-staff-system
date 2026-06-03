<?php
/**
 * Optional OAuth (user Gmail) for Drive copy/create — service accounts cannot own new files.
 */

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/site-urls.php';

function isGoogleOAuthClientSecretConfigured(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? getDB();

    return trim(getSetting($pdo, 'google_oauth_client_secret', '')) !== '';
}

function googleDriveOAuthConfigured(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? getDB();

    if (trim(getSetting($pdo, 'google_oauth_client_id', '')) === ''
        || !isGoogleOAuthClientSecretConfigured($pdo)) {
        return false;
    }

    if (trim(getSetting($pdo, 'google_oauth_refresh_token', '')) !== '') {
        return true;
    }

    $access  = trim(getSetting($pdo, 'google_oauth_access_token', ''));
    $expires = (int) getSetting($pdo, 'google_oauth_token_expires', '0');

    return $access !== '' && $expires > time() + 60;
}

function googleDriveOAuthRedirectUri(?PDO $pdo = null): string
{
    $pdo = $pdo ?? getDB();
    $override = trim(getSetting($pdo, 'google_oauth_redirect_uri', ''));
    if ($override !== '') {
        return $override;
    }

    return normalizePublicSiteUrl(getAdminSiteUrl($pdo)) . '/google-drive-oauth-callback.php';
}

function googleDriveOAuthJavaScriptOrigin(?PDO $pdo = null): string
{
    $parts = parse_url(googleDriveOAuthRedirectUri($pdo));

    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    $port = isset($parts['port']) ? ':' . $parts['port'] : '';

    return $parts['scheme'] . '://' . $parts['host'] . $port;
}

/**
 * @return list<string>
 */
function googleDriveOAuthScopes(): array
{
    return [
        // drive.file cannot copy an existing template in your folder — need full Drive for copy
        'https://www.googleapis.com/auth/drive',
        'https://www.googleapis.com/auth/spreadsheets',
    ];
}

function googleDriveOAuthStoreState(PDO $pdo, string $state): void
{
    setSetting($pdo, 'google_oauth_state', $state);
    setSetting($pdo, 'google_oauth_state_time', (string) time());
}

function googleDriveOAuthValidateState(PDO $pdo, string $state): bool
{
    $expected = trim(getSetting($pdo, 'google_oauth_state', ''));
    $created  = (int) getSetting($pdo, 'google_oauth_state_time', '0');
    setSetting($pdo, 'google_oauth_state', '');
    setSetting($pdo, 'google_oauth_state_time', '');

    if ($expected === '' || $created < time() - 900) {
        return false;
    }

    return hash_equals($expected, $state);
}

function googleDriveOAuthAuthorizeUrl(?PDO $pdo = null): string
{
    $pdo = $pdo ?? getDB();
    if (function_exists('initSecureSession')) {
        initSecureSession();
    }

    $clientId = trim(getSetting($pdo, 'google_oauth_client_id', ''));
    $state    = bin2hex(random_bytes(16));
    $_SESSION['google_drive_oauth_state'] = $state;
    googleDriveOAuthStoreState($pdo, $state);

    $params = [
        'client_id'     => $clientId,
        'redirect_uri'  => googleDriveOAuthRedirectUri($pdo),
        'response_type' => 'code',
        'scope'         => implode(' ', googleDriveOAuthScopes()),
        'access_type'   => 'offline',
        'prompt'        => 'consent',
        'state'         => $state,
    ];

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/**
 * @return array{ok: bool, message: string}
 */
function googleDriveOAuthExchangeCode(PDO $pdo, string $code): array
{
    $clientId     = trim(getSetting($pdo, 'google_oauth_client_id', ''));
    $clientSecret = trim(getSetting($pdo, 'google_oauth_client_secret', ''));
    if ($clientId === '' || $clientSecret === '') {
        return ['ok' => false, 'message' => 'OAuth client ID and secret are required in Settings.'];
    }

    $body = http_build_query([
        'code'          => $code,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => googleDriveOAuthRedirectUri($pdo),
        'grant_type'    => 'authorization_code',
    ]);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $responseBody = curl_exec($ch);
    $codeHttp     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($codeHttp !== 200 || !is_string($responseBody)) {
        $data = is_string($responseBody) ? json_decode($responseBody, true) : null;
        if (is_array($data) && ($data['error'] ?? '') === 'invalid_client') {
            return [
                'ok'      => false,
                'message' => 'OAuth client secret is wrong. In Google Cloud open Credentials → your Web client → copy the Client secret (starts with GOCSPX-), paste it here, Save, then Connect again. Use a Web application client, not Desktop or API key.',
            ];
        }

        $snippet = is_string($responseBody) ? mb_substr($responseBody, 0, 200) : '';

        return ['ok' => false, 'message' => 'Token exchange failed (HTTP ' . $codeHttp . '). ' . $snippet];
    }

    $data = json_decode($responseBody, true);
    if (!is_array($data) || empty($data['access_token'])) {
        return ['ok' => false, 'message' => 'Google did not return an access token. Check redirect URI matches Google Cloud exactly.'];
    }

    setSetting($pdo, 'google_oauth_access_token', (string) $data['access_token']);
    setSetting($pdo, 'google_oauth_token_expires', (string) (time() + (int) ($data['expires_in'] ?? 3600)));
    clearSettingsCache();

    if (!empty($data['refresh_token'])) {
        setSetting($pdo, 'google_oauth_refresh_token', (string) $data['refresh_token']);

        return ['ok' => true, 'message' => 'Google account connected for creating sheets in your Drive.'];
    }

    return [
        'ok'      => true,
        'message' => 'Google connected for now. For a permanent connection: open https://myaccount.google.com/permissions, remove olasentra.com access, then Connect again.',
    ];
}

function googleDriveGetUserAccessToken(?PDO $pdo = null): string
{
    $pdo = $pdo ?? getDB();
    if (!googleDriveOAuthConfigured($pdo)) {
        return '';
    }

    $expires = (int) getSetting($pdo, 'google_oauth_token_expires', '0');
    $cached  = trim(getSetting($pdo, 'google_oauth_access_token', ''));
    if ($cached !== '' && $expires > time() + 60) {
        return $cached;
    }

    $body = http_build_query([
        'client_id'     => trim(getSetting($pdo, 'google_oauth_client_id', '')),
        'client_secret' => trim(getSetting($pdo, 'google_oauth_client_secret', '')),
        'refresh_token' => trim(getSetting($pdo, 'google_oauth_refresh_token', '')),
        'grant_type'    => 'refresh_token',
    ]);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $responseBody = curl_exec($ch);
    $codeHttp     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($codeHttp !== 200 || !is_string($responseBody)) {
        return '';
    }

    $data = json_decode($responseBody, true);
    $token = is_array($data) ? (string) ($data['access_token'] ?? '') : '';
    if ($token === '') {
        return '';
    }

    setSetting($pdo, 'google_oauth_access_token', $token);
    setSetting($pdo, 'google_oauth_token_expires', (string) (time() + (int) ($data['expires_in'] ?? 3600)));

    return $token;
}
