<?php

declare(strict_types=1);

/**
 * Guards public registration APIs (session CSRF + light rate limit).
 */

require_once __DIR__ . '/app-environment.php';

function requireRegistrationApiCsrf(?string $token): void
{
    initSecureSession();

    require_once __DIR__ . '/auth.php';

    if (!verifyCsrf($token !== '' && $token !== null ? $token : null)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['found' => false, 'error' => 'Invalid session token.']);
        exit;
    }
}

function throttleRegistrationLookup(string $email): void
{
    initSecureSession();

    $key  = 'reg_lookup_' . md5(strtolower($email));
    $now  = time();
    $last = (int) ($_SESSION[$key] ?? 0);

    if ($last > 0 && ($now - $last) < 2) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['found' => false, 'error' => 'Please wait before looking up again.']);
        exit;
    }

    $_SESSION[$key] = $now;
}
