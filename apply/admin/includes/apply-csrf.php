<?php

declare(strict_types=1);

function applyInitSecureSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
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

function applyCsrfToken(): string
{
    applyInitSecureSession();

    if (empty($_SESSION['apply_csrf_token'])) {
        $_SESSION['apply_csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['apply_csrf_token'];
}

function verifyApplyCsrf(?string $token): bool
{
    applyInitSecureSession();

    return is_string($token) && hash_equals((string) ($_SESSION['apply_csrf_token'] ?? ''), $token);
}
