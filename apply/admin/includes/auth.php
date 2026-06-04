<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/main-admin-bridge.php';

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
    refreshApplyAdminSession();

    if (!isApplyAdminLoggedIn()) {
        $return = $_SERVER['REQUEST_URI'] ?? '';
        $login  = 'login.php';
        if ($return !== '' && $return !== '/') {
            $login .= '?' . http_build_query(['return' => $return]);
        }
        header('Location: ' . $login);
        exit;
    }
}

// Pages under admin/admin/ include this file.
requireApplyAdmin();
