<?php

declare(strict_types=1);

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/site-urls.php';
require_once __DIR__ . '/google-drive-oauth.php';
require_once __DIR__ . '/staff-portal-session.php';
require_once __DIR__ . '/staff-portal-remember.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/staff-profile-gate.php';

function isStaffGoogleSigninConfigured(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? getDB();

    return trim(getSetting($pdo, 'google_oauth_client_id', '')) !== ''
        && isGoogleOAuthClientSecretConfigured($pdo);
}

function isStaffGoogleSigninEnabled(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? getDB();

    return isStaffGoogleSigninConfigured($pdo)
        && getSetting($pdo, 'staff_google_signin_enabled', '0') === '1';
}

function isStaffGoogleSigninRequired(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? getDB();

    if (!isStaffGoogleSigninEnabled($pdo)) {
        return false;
    }

    return getSetting($pdo, 'staff_google_signin_required', '1') === '1';
}

/**
 * Registration identity gate — Google required, or email OTP when Google is not required.
 */
function isRegistrationVerificationRequired(?PDO $pdo = null): bool
{
    $policy = getStaffAuthPolicy($pdo);

    return $policy['google_signin_required'] || $policy['registration_email_otp_enabled'];
}

/** True when registration must use Google (not email OTP). */
function isRegistrationGoogleRequired(?PDO $pdo = null): bool
{
    return isStaffGoogleSigninRequired($pdo);
}

/**
 * @return array{ok: false, message: string, code: string, status: int}|null
 */
function alternateStaffAuthBlockedByGoogleRequired(?PDO $pdo = null): ?array
{
    $pdo = $pdo ?? getDB();

    if (!isStaffGoogleSigninRequired($pdo)) {
        return null;
    }

    return [
        'ok'      => false,
        'message' => 'Sign-in with Google is required. Use Continue with Google.',
        'code'    => 'GOOGLE_REQUIRED',
        'status'  => 403,
    ];
}

function setRegistrationVerifiedSession(string $email, string $method): void
{
    initSecureSession();

    $email  = normalizeRegistrationEmail($email);
    $method = strtolower(trim($method));
    if (!in_array($method, ['google', 'email_otp'], true)) {
        $method = 'email_otp';
    }

    $at = time();

    $_SESSION['registration_verified_email']   = $email;
    $_SESSION['registration_verified_method']  = $method;
    $_SESSION['registration_verified_at']      = $at;
    $_SESSION['registration_google_email']     = $email;
    $_SESSION['registration_google_verified_at'] = $at;
    $_SESSION['registration_email_verified']   = ($method === 'email_otp');
}

function getRegistrationVerifiedEmail(): ?string
{
    if (!function_exists('initSecureSession')) {
        return null;
    }
    initSecureSession();

    $email = normalizeRegistrationEmail((string) ($_SESSION['registration_verified_email'] ?? ''));
    if ($email === '') {
        $email = normalizeRegistrationEmail((string) ($_SESSION['registration_google_email'] ?? ''));
    }

    $at = (int) ($_SESSION['registration_verified_at'] ?? $_SESSION['registration_google_verified_at'] ?? 0);
    if ($email === '' || $at < 1 || (time() - $at) > 604800) {
        return null;
    }

    return $email;
}

function getRegistrationVerifiedMethod(): ?string
{
    if (getRegistrationVerifiedEmail() === null) {
        return null;
    }

    initSecureSession();

    $method = strtolower(trim((string) ($_SESSION['registration_verified_method'] ?? '')));
    if (in_array($method, ['google', 'email_otp'], true)) {
        return $method;
    }

    if (!empty($_SESSION['registration_email_verified'])) {
        return 'email_otp';
    }

    return 'google';
}

function resolveRegistrationVerifiedEmailFromRequest(): string
{
    $sessionEmail = getRegistrationVerifiedEmail();
    if ($sessionEmail === null) {
        return '';
    }

    $fromPost = normalizeRegistrationEmail((string) ($_POST['registration_verified_email'] ?? $_POST['registration_verified_google_email'] ?? ''));
    if ($fromPost !== '' && strcasecmp($fromPost, $sessionEmail) !== 0) {
        error_log('[EventStaff] registration verified email POST/session mismatch');

        return '';
    }

    return $sessionEmail;
}

/**
 * Single source of truth for staff/registration authentication policy flags.
 *
 * @return array{
 *   google_signin_enabled: bool,
 *   google_signin_required: bool,
 *   staff_portal_email_otp_enabled: bool,
 *   mobile_email_otp_enabled: bool,
 *   registration_email_otp_enabled: bool,
 *   pps_signin_enabled: bool
 * }
 */
function getStaffAuthPolicy(?PDO $pdo = null): array
{
    $pdo = $pdo ?? getDB();

    $googleEnabled  = isStaffGoogleSigninEnabled($pdo);
    $googleRequired = isStaffGoogleSigninRequired($pdo);

    require_once __DIR__ . '/signin-display.php';

    $ppsEnabled = isSigninPpsRequired($pdo) && !$googleRequired;
    $otpBase    = getSetting($pdo, 'mobile_email_otp_enabled', '1') === '1';
    $portalOtp  = getSetting($pdo, 'staff_portal_email_otp_enabled', '1') === '1';

    return [
        'google_signin_enabled'          => $googleEnabled,
        'google_signin_required'         => $googleRequired,
        'staff_portal_email_otp_enabled' => $portalOtp && !$googleRequired,
        'mobile_email_otp_enabled'       => $otpBase && !$googleRequired,
        'registration_email_otp_enabled' => $otpBase && !$googleRequired,
        'pps_signin_enabled'             => $ppsEnabled,
    ];
}

function staffGoogleOAuthRedirectUri(?PDO $pdo = null): string
{
    $pdo = $pdo ?? getDB();
    $override = trim(getSetting($pdo, 'staff_google_oauth_redirect_uri', ''));
    if ($override !== '') {
        return $override;
    }

    return rtrim(getRegistrationSiteUrl($pdo), '/') . '/staff-google-oauth-callback.php';
}

/**
 * @return list<string>
 */
function staffGoogleOAuthScopes(): array
{
    return [
        'openid',
        'email',
        'profile',
    ];
}

function staffGoogleOAuthValidateState(string $state): bool
{
    if (!function_exists('initSecureSession')) {
        return false;
    }
    initSecureSession();

    $expected = (string) ($_SESSION['staff_google_oauth_state'] ?? '');
    $created  = (int) ($_SESSION['staff_google_oauth_state_time'] ?? 0);
    unset($_SESSION['staff_google_oauth_state'], $_SESSION['staff_google_oauth_state_time']);

    if ($expected === '' || $created < time() - 900) {
        return false;
    }

    return hash_equals($expected, $state);
}

function staffGoogleOAuthAuthorizeUrl(?PDO $pdo = null, string $returnUrl = 'staff-app.php'): string
{
    $pdo = $pdo ?? getDB();
    initSecureSession();

    $clientId = trim(getSetting($pdo, 'google_oauth_client_id', ''));
    $state    = bin2hex(random_bytes(16));
    $_SESSION['staff_google_oauth_state']      = $state;
    $_SESSION['staff_google_oauth_state_time'] = time();
    $_SESSION['staff_google_return']           = staffGoogleSanitizeReturnUrl($returnUrl);

    $params = [
        'client_id'     => $clientId,
        'redirect_uri'  => staffGoogleOAuthRedirectUri($pdo),
        'response_type' => 'code',
        'scope'         => implode(' ', staffGoogleOAuthScopes()),
        'access_type'   => 'online',
        'prompt'        => 'select_account',
        'state'         => $state,
    ];

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

function staffGoogleSanitizeReturnUrl(string $url): string
{
    $url = trim($url);
    if ($url === '' || str_contains($url, '://') || str_starts_with($url, '//')) {
        return 'staff-app.php';
    }

    return $url;
}

/**
 * Exchange code for staff login only — does NOT overwrite admin Drive OAuth tokens.
 *
 * @return array{ok: bool, message: string, email?: string}
 */
function staffGoogleOAuthExchangeCode(PDO $pdo, string $code): array
{
    $clientId     = trim(getSetting($pdo, 'google_oauth_client_id', ''));
    $clientSecret = trim(getSetting($pdo, 'google_oauth_client_secret', ''));
    if ($clientId === '' || $clientSecret === '') {
        return ['ok' => false, 'message' => 'Google sign-in is not configured on the server.'];
    }

    $body = http_build_query([
        'code'          => $code,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => staffGoogleOAuthRedirectUri($pdo),
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
        return ['ok' => false, 'message' => 'Google sign-in failed (token exchange). Check redirect URI in Google Cloud.'];
    }

    $data = json_decode($responseBody, true);
    $accessToken = is_array($data) ? (string) ($data['access_token'] ?? '') : '';
    if ($accessToken === '') {
        return ['ok' => false, 'message' => 'Google did not return an access token.'];
    }

    $profile = staffGoogleFetchProfileFromAccessToken($accessToken);
    if (!$profile['ok']) {
        return ['ok' => false, 'message' => $profile['message']];
    }

    return ['ok' => true, 'message' => 'Signed in.', 'email' => $profile['email']];
}

/**
 * @return array{ok: bool, message: string, email: string}
 */
function staffGoogleFetchProfileFromAccessToken(string $accessToken): array
{
    $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $responseBody = curl_exec($ch);
    $codeHttp     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($codeHttp !== 200 || !is_string($responseBody)) {
        return ['ok' => false, 'message' => 'Could not verify your Google account.', 'email' => ''];
    }

    $data = json_decode($responseBody, true);
    if (!is_array($data)) {
        return ['ok' => false, 'message' => 'Could not read your email address from Google.', 'email' => ''];
    }

    $verified = $data['verified_email'] ?? $data['email_verified'] ?? false;
    if (!$verified) {
        return ['ok' => false, 'message' => 'Your Google email is not verified. Verify it in your Google account settings, then try again.', 'email' => ''];
    }

    $email = strtolower(trim((string) ($data['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Could not read your email address from Google.', 'email' => ''];
    }

    return ['ok' => true, 'message' => 'OK', 'email' => $email];
}

/**
 * @return array{ok: bool, message: string, staff?: array<string, mixed>}
 */
function authenticateStaffPortalByGoogleEmail(PDO $pdo, string $email): array
{
    $email = normalizeRegistrationEmail($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Invalid Google account email.'];
    }

    ensureStaffRecordForEmail($pdo, $email);
    $staff = getStaffByEmail($pdo, $email);
    if ($staff === null) {
        return [
            'ok'      => false,
            'message' => 'This email is not registered yet. Register for an event first using the same email address.',
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM staff_registrations WHERE LOWER(email) = :email AND status != 'rejected'"
    );
    $stmt->execute(['email' => strtolower($email)]);
    $regCount = (int) $stmt->fetchColumn();

    if ($regCount < 1 && (int) ($staff['profile_completed'] ?? 0) !== 1) {
        return [
            'ok'      => false,
            'message' => 'This email is not on our staff list yet. Register for a shift first, then sign in with Google.',
        ];
    }

    return ['ok' => true, 'message' => 'OK', 'staff' => $staff];
}

/**
 * Complete staff portal login after Google OAuth.
 *
 * @return array{ok: bool, message: string, redirect?: string}
 */
function completeStaffGoogleSignin(PDO $pdo, string $code): array
{
    $exchange = staffGoogleOAuthExchangeCode($pdo, $code);
    if (!$exchange['ok']) {
        return $exchange;
    }

    $auth = authenticateStaffPortalByGoogleEmail($pdo, (string) ($exchange['email'] ?? ''));
    if (!$auth['ok'] || empty($auth['staff'])) {
        return ['ok' => false, 'message' => $auth['message']];
    }

    $staff = $auth['staff'];
    establishStaffPortalSessionWithRemember($pdo, $staff);
    $_SESSION['staff_profile_return'] = 'staff-app.php';

    initSecureSession();
    $redirect = staffGoogleSanitizeReturnUrl((string) ($_SESSION['staff_google_return'] ?? 'staff-app.php'));
    unset($_SESSION['staff_google_return']);

    return [
        'ok'       => true,
        'message'  => 'Signed in with Google.',
        'redirect' => staffPortalLandingUrl($pdo, $staff),
    ];
}

function isRegistrationGoogleReturnUrl(string $returnUrl): bool
{
    $returnUrl = strtolower(trim($returnUrl));

    return str_contains($returnUrl, 'index.php')
        || str_starts_with($returnUrl, 'register');
}

function getRegistrationVerifiedGoogleEmail(): ?string
{
    return getRegistrationVerifiedEmail();
}

function clearRegistrationVerifiedSession(): void
{
    unset(
        $_SESSION['registration_verified_email'],
        $_SESSION['registration_verified_method'],
        $_SESSION['registration_verified_at'],
        $_SESSION['registration_google_email'],
        $_SESSION['registration_google_verified_at'],
        $_SESSION['registration_email_verified']
    );
}

function clearRegistrationGoogleEmailSession(): void
{
    clearRegistrationVerifiedSession();
}

/**
 * Verify Google account email for new registration (no existing staff record required).
 *
 * @return array{ok: bool, message: string, redirect?: string, email?: string}
 */
function completeRegistrationGoogleVerify(PDO $pdo, string $code): array
{
    $exchange = staffGoogleOAuthExchangeCode($pdo, $code);
    if (!$exchange['ok']) {
        return $exchange;
    }

    $email = normalizeRegistrationEmail((string) ($exchange['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Could not read your email address from Google.'];
    }

    setRegistrationVerifiedSession($email, 'google');

    $redirect = staffGoogleSanitizeReturnUrl((string) ($_SESSION['staff_google_return'] ?? 'index.php'));
    unset($_SESSION['staff_google_return']);

    return [
        'ok'       => true,
        'message'  => 'Email verified.',
        'redirect' => $redirect,
        'email'    => $email,
    ];
}

function renderStaffGoogleSignInButton(PDO $pdo, string $returnUrl = 'staff-app.php', bool $blockStyle = true): void
{
    require_once __DIR__ . '/helpers.php';

    if (!isStaffGoogleSigninEnabled($pdo)) {
        return;
    }

    $href = 'staff-google-signin.php?' . http_build_query(['return' => staffGoogleSanitizeReturnUrl($returnUrl)]);
    $class = $blockStyle ? 'btn btn--google btn--block' : 'btn btn--google';
    ?>
    <a class="<?= h($class) ?>" href="<?= h($href) ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/>
            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
        </svg>
        Continue with Google
    </a>
    <?php
}
