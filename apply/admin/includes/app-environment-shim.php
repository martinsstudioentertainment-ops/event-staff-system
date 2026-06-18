<?php

declare(strict_types=1);

/**
 * Local fallback when main ERP includes/app-environment.php is not on the Apply host path.
 * Keeps session idle timeout behaviour aligned with main admin.
 */

if (!defined('APP_SESSION_IDLE_TTL')) {
    define('APP_SESSION_IDLE_TTL', 300);
}

if (!defined('ADMIN_SESSION_IDLE_TTL')) {
    define('ADMIN_SESSION_IDLE_TTL', 600);
}

if (!function_exists('touchSessionActivity')) {
    function touchSessionActivity(string $key = 'app_session_last_activity'): void
    {
        $_SESSION[$key] = time();
    }
}

if (!function_exists('sessionIdleExpired')) {
    function sessionIdleExpired(string $key = 'app_session_last_activity', int $ttl = APP_SESSION_IDLE_TTL): bool
    {
        $last = (int) ($_SESSION[$key] ?? 0);

        return $last > 0 && (time() - $last) > $ttl;
    }
}
