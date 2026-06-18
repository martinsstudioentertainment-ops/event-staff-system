<?php

function isProductionApp(): bool
{
    return defined('APP_ENV') && strtolower((string) APP_ENV) === 'production';
}

function shouldBootstrapSession(): bool
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === '') {
        return true;
    }

    $base = basename($script);
    $skip = [
        'theme.css.php',
        'manifest.php',
        'pwa-icon.php',
    ];

    if (in_array($base, $skip, true)) {
        return false;
    }

    return !str_contains($script, '/cron/');
}

function initSecureSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    if (headers_sent($file, $line)) {
        error_log(sprintf(
            '[EventStaff] Session not started: output began in %s on line %d',
            $file ?? 'unknown',
            $line ?? 0
        ));

        return;
    }

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function guardDevOnlyEndpoint(string $message = 'This tool is disabled in production.'): void
{
    if (!isProductionApp()) {
        return;
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

const ADMIN_LOGIN_MAX_ATTEMPTS = 5;
const ADMIN_LOGIN_LOCK_MINUTES = 15;

/** Idle timeout before automatic sign-out (staff portal). */
const APP_SESSION_IDLE_TTL = 300;

/** Admin panel idle timeout before automatic sign-out (10 minutes). */
const ADMIN_SESSION_IDLE_TTL = 600;

function touchSessionActivity(string $key = 'app_session_last_activity'): void
{
    $_SESSION[$key] = time();
}

function sessionIdleExpired(string $key = 'app_session_last_activity', int $ttl = APP_SESSION_IDLE_TTL): bool
{
    $last = (int) ($_SESSION[$key] ?? 0);

    return $last > 0 && (time() - $last) > $ttl;
}

function adminLoginLockKey(): string
{
    return 'admin_login_' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

/**
 * @return array{locked: bool, message: string, remaining: int}
 */
function getAdminLoginLockStatus(): array
{
    initSecureSession();

    $key     = adminLoginLockKey();
    $attempts = (int) ($_SESSION[$key . '_attempts'] ?? 0);
    $until    = (int) ($_SESSION[$key . '_locked_until'] ?? 0);
    $now      = time();

    if ($until > $now) {
        $mins = (int) ceil(($until - $now) / 60);

        return [
            'locked'    => true,
            'message'   => 'Too many failed attempts. Try again in ' . max(1, $mins) . ' minute(s).',
            'remaining' => $attempts,
        ];
    }

    if ($until > 0 && $until <= $now) {
        unset($_SESSION[$key . '_attempts'], $_SESSION[$key . '_locked_until']);
        $attempts = 0;
    }

    return [
        'locked'    => false,
        'message'   => '',
        'remaining' => $attempts,
    ];
}

function recordAdminLoginFailure(): void
{
    initSecureSession();

    $key      = adminLoginLockKey();
    $attempts = (int) ($_SESSION[$key . '_attempts'] ?? 0) + 1;
    $_SESSION[$key . '_attempts'] = $attempts;

    if ($attempts >= ADMIN_LOGIN_MAX_ATTEMPTS) {
        $_SESSION[$key . '_locked_until'] = time() + (ADMIN_LOGIN_LOCK_MINUTES * 60);
    }
}

function clearAdminLoginFailures(): void
{
    initSecureSession();

    $key = adminLoginLockKey();
    unset($_SESSION[$key . '_attempts'], $_SESSION[$key . '_locked_until']);
}

function registrationPrivacyAccepted(array $data): bool
{
    return !empty($data['privacy_consent']) && (string) $data['privacy_consent'] === '1';
}
