<?php

declare(strict_types=1);

const STAFF_REMEMBER_COOKIE_NAME = 'olasentra_staff_session';
const STAFF_REMEMBER_DAYS        = 90;

function getStaffRememberSecret(PDO $pdo): string
{
    require_once __DIR__ . '/settings-repository.php';

    $key = trim(getSetting($pdo, 'reminder_cron_key', ''));
    if ($key !== '') {
        return hash('sha256', 'staff-remember:' . $key);
    }

    return hash('sha256', 'staff-remember:' . DB_NAME . ':' . DB_HOST);
}

function issueStaffRememberCookie(PDO $pdo, array $staff): void
{
    $staffId = (int) ($staff['id'] ?? 0);
    $email   = strtolower(trim((string) ($staff['email'] ?? '')));
    if ($staffId < 1 || $email === '') {
        return;
    }

    $expiry = time() + (STAFF_REMEMBER_DAYS * 86400);
    $payload = $staffId . '|' . $expiry . '|' . $email;
    $sig     = hash_hmac('sha256', $payload, getStaffRememberSecret($pdo));
    $value   = base64_encode($payload . '|' . $sig);

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie(STAFF_REMEMBER_COOKIE_NAME, $value, [
        'expires'  => $expiry,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (session_status() === PHP_SESSION_ACTIVE) {
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires'  => $expiry,
            'path'     => $params['path'] !== '' ? $params['path'] : '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

function establishStaffPortalSessionWithRemember(PDO $pdo, array $staff): void
{
    require_once __DIR__ . '/staff-portal-session.php';

    establishStaffPortalSession($staff);
    issueStaffRememberCookie($pdo, $staff);
    $_SESSION['staff_portal_remembered'] = 1;
}

function clearStaffRememberCookie(): void
{
    if (headers_sent()) {
        return;
    }

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie(STAFF_REMEMBER_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Restore PHP session from the phone remember cookie (stay signed in).
 */
function restoreStaffPortalFromRememberCookie(PDO $pdo): bool
{
    if ((int) ($_SESSION['staff_portal_staff_id'] ?? 0) > 0) {
        return true;
    }

    $raw = $_COOKIE[STAFF_REMEMBER_COOKIE_NAME] ?? '';
    if ($raw === '') {
        return false;
    }

    $decoded = base64_decode($raw, true);
    if ($decoded === false) {
        return false;
    }

    $parts = explode('|', $decoded);
    if (count($parts) !== 4) {
        return false;
    }

    [$staffIdRaw, $expiryRaw, $email, $sig] = $parts;
    $staffId = (int) $staffIdRaw;
    $expiry  = (int) $expiryRaw;
    if ($staffId < 1 || $expiry < time() || $email === '' || $sig === '') {
        clearStaffRememberCookie();

        return false;
    }

    $payload   = $staffId . '|' . $expiry . '|' . $email;
    $expected  = hash_hmac('sha256', $payload, getStaffRememberSecret($pdo));
    if (!hash_equals($expected, $sig)) {
        clearStaffRememberCookie();

        return false;
    }

    $staff = getStaffById($pdo, $staffId);
    if ($staff === null || strtolower(trim((string) ($staff['email'] ?? ''))) !== strtolower($email)) {
        clearStaffRememberCookie();

        return false;
    }

    establishStaffPortalSession($staff);
    $_SESSION['staff_portal_remembered'] = 1;

    return true;
}

function staffPortalUsesRememberCookie(): bool
{
    if (!empty($_SESSION['staff_portal_remembered'])) {
        return true;
    }

    $raw = trim((string) ($_COOKIE[STAFF_REMEMBER_COOKIE_NAME] ?? ''));

    return $raw !== '';
}
