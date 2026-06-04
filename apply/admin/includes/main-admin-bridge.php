<?php

declare(strict_types=1);

/**
 * Connect apply admin to main Event Staff admin_users (same login + SSO).
 */

function loadMainEventStaffConfig(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $candidates = [
        dirname(__DIR__, 3) . '/config.php',
        dirname(__DIR__, 4) . '/public_html/config.php',
        '/home/olastofx/public_html/config.php',
    ];

    foreach ($candidates as $path) {
        if (is_readable($path)) {
            require_once $path;
            $loaded = true;

            return;
        }
    }
}

function getMainAdminPdo(): ?PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = __DIR__ . '/../config/eventstaff-database.php';
    if (is_readable($config)) {
        require $config;
        if (isset($eventPdo) && $eventPdo instanceof PDO) {
            $pdo = $eventPdo;

            return $pdo;
        }
    }

    loadMainEventStaffConfig();
    if (function_exists('getDB')) {
        try {
            $pdo = getDB();

            return $pdo;
        } catch (Throwable $e) {
            return null;
        }
    }

    return null;
}

/**
 * @return array<string, mixed>|null
 */
function fetchMainAdminUser(int $adminId): ?array
{
    $pdo = getMainAdminPdo();
    if ($pdo === null) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, username, full_name, email, role, is_active
         FROM admin_users
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $adminId]);
    $user = $stmt->fetch();

    return $user && (int) ($user['is_active'] ?? 0) === 1 ? $user : null;
}

function applyAdminRoleAllowed(string $role): bool
{
    return in_array($role, ['admin', 'manager'], true);
}

function setApplyAdminSession(array $user): void
{
    $_SESSION['admin_id']       = (int) $user['id'];
    $_SESSION['admin_username'] = (string) ($user['username'] ?? '');
    $_SESSION['admin_name']     = (string) ($user['full_name'] ?? '');
    $_SESSION['admin_role']     = (string) ($user['role'] ?? 'staff');
    $_SESSION['admin_email']    = (string) ($user['email'] ?? '');
    $_SESSION['admin_from_main'] = true;
}

/**
 * @return array{ok: bool, error: string}
 */
function attemptMainAdminLogin(string $username, string $password): array
{
    $pdo = getMainAdminPdo();
    if ($pdo === null) {
        return ['ok' => false, 'error' => 'Main admin database is not configured.'];
    }

    $stmt = $pdo->prepare(
        'SELECT id, username, password_hash, full_name, email, role, is_active
         FROM admin_users
         WHERE username = :username
         LIMIT 1'
    );
    $stmt->execute(['username' => trim($username)]);
    $user = $stmt->fetch();

    if (!$user || !(int) ($user['is_active'] ?? 0)) {
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }

    if (!applyAdminRoleAllowed((string) ($user['role'] ?? ''))) {
        return ['ok' => false, 'error' => 'Your main admin role cannot access Apply admin.'];
    }

    if (!password_verify($password, (string) ($user['password_hash'] ?? ''))) {
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }

    setApplyAdminSession($user);

    return ['ok' => true, 'error' => ''];
}
