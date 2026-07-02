<?php

declare(strict_types=1);

require_once __DIR__ . '/system-settings.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/settings-repository.php';

const ADMIN_LOGIN_OTP_TTL_SECONDS = 600;
const ADMIN_TRUSTED_DEVICE_DAYS = 30;

function isAdminLoginOtpEnabled(?PDO $pdo = null): bool
{
    $pdo = $pdo ?? getDB();

    if (isAdmin2faRequired($pdo)) {
        return true;
    }

    return getSetting($pdo, 'admin_login_otp_enabled', '1') === '1';
}

/** Email address that always receives login verification codes. */
function getAdminLoginOtpEmail(PDO $pdo): string
{
    $email = strtolower(trim(getSetting($pdo, 'admin_login_otp_email', '')));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }

    $email = strtolower(trim(getSetting($pdo, 'notify_admin_email', '')));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }

    $email = strtolower(trim(getSetting($pdo, 'company_email', '')));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $email;
    }

    return 'olabodeoluwafemi2580@gmail.com';
}

function getAdminTrustedDeviceCookieName(): string
{
    return 'olasentra_admin_trusted';
}

function getAdminTrustedDeviceSecret(): string
{
    if (defined('DB_NAME') && defined('DB_PASS')) {
        return hash('sha256', (string) DB_NAME . '|' . (string) DB_PASS . '|olasentra-admin-trusted-v1');
    }

    return hash('sha256', 'olasentra-admin-trusted-dev');
}

function createAdminTrustedDeviceToken(int $adminId, int $ttlDays = ADMIN_TRUSTED_DEVICE_DAYS): string
{
    $payload = [
        'admin_id' => $adminId,
        'exp'      => time() + max(1, $ttlDays) * 86400,
        'nonce'    => bin2hex(random_bytes(8)),
    ];
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $b64  = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $sig  = hash_hmac('sha256', $b64, getAdminTrustedDeviceSecret());

    return $b64 . '.' . $sig;
}

/**
 * @return array{admin_id: int, exp: int}|null
 */
function verifyAdminTrustedDeviceToken(string $token): ?array
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
    if (!hash_equals(hash_hmac('sha256', $b64, getAdminTrustedDeviceSecret()), $sig)) {
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
    ];
}

function hasValidAdminTrustedDeviceCookie(int $adminId): bool
{
    $token = (string) ($_COOKIE[getAdminTrustedDeviceCookieName()] ?? '');
    $payload = verifyAdminTrustedDeviceToken($token);

    return $payload !== null && (int) $payload['admin_id'] === $adminId;
}

function setAdminTrustedDeviceCookie(int $adminId): void
{
    if (headers_sent()) {
        return;
    }

    $token  = createAdminTrustedDeviceToken($adminId);
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $domain = '';
    $host   = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host   = preg_replace('/:\d+$/', '', $host) ?? $host;
    if ($host === 'olasentra.com' || str_ends_with($host, '.olasentra.com')) {
        $domain = '.olasentra.com';
    }

    $options = [
        'expires'  => time() + ADMIN_TRUSTED_DEVICE_DAYS * 86400,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if ($domain !== '') {
        $options['domain'] = $domain;
    }

    setcookie(getAdminTrustedDeviceCookieName(), $token, $options);
}

/**
 * @param array<string, mixed> $user
 */
function beginAdminLoginOtpChallenge(array $user): void
{
    $_SESSION['admin_login_otp_pending'] = [
        'admin_id'   => (int) $user['id'],
        'username'   => (string) ($user['username'] ?? ''),
        'full_name'  => (string) ($user['full_name'] ?? ''),
        'email'      => (string) ($user['email'] ?? ''),
        'role'       => (string) ($user['role'] ?? 'staff'),
        'expires_at' => time() + ADMIN_LOGIN_OTP_TTL_SECONDS,
    ];
    unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_name'], $_SESSION['admin_role'], $_SESSION['admin_email']);
}

function clearAdminLoginOtpChallenge(): void
{
    unset($_SESSION['admin_login_otp_pending']);
}

/** @return array<string, mixed>|null */
function getAdminLoginOtpPending(): ?array
{
    $pending = $_SESSION['admin_login_otp_pending'] ?? null;
    if (!is_array($pending) || empty($pending['admin_id'])) {
        return null;
    }

    if ((int) ($pending['expires_at'] ?? 0) < time()) {
        clearAdminLoginOtpChallenge();

        return null;
    }

    return $pending;
}

function sendAdminLoginOtpEmail(PDO $pdo, int $adminId, string $username): bool
{
    $code = (string) random_int(100000, 999999);
    $_SESSION['admin_login_otp_code_hash'] = password_hash($code, PASSWORD_DEFAULT);
    $_SESSION['admin_login_otp_code_expires'] = time() + ADMIN_LOGIN_OTP_TTL_SECONDS;

    $to       = getAdminLoginOtpEmail($pdo);
    $siteName = getSiteName($pdo);
    $subject  = $siteName . ' — Admin login code';
    $body = implode("\n", [
        'Your admin login verification code is: ' . $code,
        '',
        'This code expires in 10 minutes.',
        'Account: ' . $username,
        'If you did not try to sign in, ignore this email.',
        '',
        $siteName,
    ]);

    require_once __DIR__ . '/email-layout.php';
    $html = buildEmailOtpContent($pdo, $code, $username, 10, 'Use this verification code to complete your admin sign-in:');

    return sendEmail($pdo, $to, $subject, $body, $html);
}

function verifyAdminLoginOtpCode(string $code): bool
{
    $hash = (string) ($_SESSION['admin_login_otp_code_hash'] ?? '');
    $exp  = (int) ($_SESSION['admin_login_otp_code_expires'] ?? 0);
    if ($hash === '' || $exp < time()) {
        return false;
    }

    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }

    if (!password_verify($code, $hash)) {
        return false;
    }

    unset($_SESSION['admin_login_otp_code_hash'], $_SESSION['admin_login_otp_code_expires']);

    return true;
}

/**
 * Complete session after password (+ optional OTP).
 *
 * @param array<string, mixed> $user
 */
function finalizeAdminLoginSession(PDO $pdo, array $user, bool $rememberDevice = false): void
{
    $_SESSION['admin_id']       = (int) $user['id'];
    $_SESSION['admin_username'] = (string) ($user['username'] ?? '');
    $_SESSION['admin_name']     = (string) ($user['full_name'] ?? '');
    $_SESSION['admin_role']     = (string) ($user['role'] ?? 'staff');
    $_SESSION['admin_email']    = (string) ($user['email'] ?? '');

    require_once __DIR__ . '/admin-users-repository.php';
    touchAdminLastLogin($pdo, (int) $user['id']);
    touchSessionActivity();
    clearAdminLoginOtpChallenge();

    if ($rememberDevice) {
        setAdminTrustedDeviceCookie((int) $user['id']);
    }

    require_once __DIR__ . '/apply-sso.php';
    setApplySsoCookie((int) $user['id'], 86400);
}

/**
 * Verify username/password without starting a session.
 *
 * @return array<string, mixed>|null
 */
function verifyAdminCredentials(PDO $pdo, string $username, string $password): ?array
{
    require_once __DIR__ . '/admin-users-schema.php';
    ensureAdminUsersSchema($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, username, password_hash, full_name, email, role, is_active
         FROM admin_users WHERE username = :username LIMIT 1'
    );
    $stmt->execute(['username' => trim($username)]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !(int) ($user['is_active'] ?? 0) || !password_verify($password, (string) ($user['password_hash'] ?? ''))) {
        return null;
    }

    return $user;
}
