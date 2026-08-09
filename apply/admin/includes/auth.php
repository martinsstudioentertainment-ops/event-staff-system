<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/main-admin-bridge.php';
apply_require_app_environment();

function tryApplyAdminCookieLogin(): bool
{
    if (isApplyAdminLoggedIn()) {
        return true;
    }

    require_once __DIR__ . '/apply-sso.php';

    $token = (string) ($_COOKIE[getApplySsoCookieName()] ?? '');
    if ($token === '') {
        return false;
    }

    $payload = verifyApplySsoToken($token);
    if ($payload === null) {
        return false;
    }

    $user = fetchMainAdminUser($payload['admin_id']);
    if ($user === null || !applyAdminRoleAllowed((string) ($user['role'] ?? ''))) {
        return false;
    }

    setApplyAdminSession($user);
    touchSessionActivity();

    return true;
}

function isApplyAdminLoggedIn(): bool
{
    return !empty($_SESSION['admin_id']);
}

function refreshApplyAdminSession(): void
{
    if (!isApplyAdminLoggedIn()) {
        return;
    }

    $user = fetchMainAdminUser((int) $_SESSION['admin_id']);
    if ($user === null || !applyAdminRoleAllowed((string) ($user['role'] ?? ''))) {
        unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_name'], $_SESSION['admin_role'], $_SESSION['admin_email'], $_SESSION['admin_from_main']);

        return;
    }

    setApplyAdminSession($user);
}

function requireApplyAdmin(): void
{
    try {
        tryApplyAdminCookieLogin();

        if (isApplyAdminLoggedIn() && sessionIdleExpired('app_session_last_activity', ADMIN_SESSION_IDLE_TTL)) {
            unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_name'], $_SESSION['admin_role'], $_SESSION['admin_email'], $_SESSION['admin_from_main']);
            header('Location: ' . apply_login_path() . '?timeout=1');
            exit;
        }

        refreshApplyAdminSession();

        if (!isApplyAdminLoggedIn()) {
            $return = $_SERVER['REQUEST_URI'] ?? '';
            $login  = apply_login_path();
            if ($return !== '' && $return !== '/') {
                $login .= '?' . http_build_query(['return' => $return]);
            }
            header('Location: ' . $login);
            exit;
        }

        touchSessionActivity();
    } catch (Throwable $e) {
        error_log('[ApplyAuth] ' . $e->getMessage());
        require_once __DIR__ . '/apply-friendly.php';
        apply_render_error_page(
            'Apply admin unavailable',
            'We could not verify your session. Please sign in again from the main ERP or Apply admin login.'
        );
    }
}

// Pages under admin/admin/ include this file.
require_once __DIR__ . '/apply-friendly.php';
requireApplyAdmin();
