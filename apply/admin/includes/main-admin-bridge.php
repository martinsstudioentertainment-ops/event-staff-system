<?php

declare(strict_types=1);

/**
 * Connect apply admin to main Event Staff admin_users (same login + SSO).
 */

/**
 * Resolve a shared include from the main ERP (Laragon monorepo or cPanel public_html).
 */
function apply_locate_main_include(string $relativePath): ?string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $candidates     = [
        dirname(__DIR__, 3) . '/includes/' . $relativePath,
        dirname(__DIR__, 3) . '/public_html/includes/' . $relativePath,
        dirname(__DIR__, 4) . '/public_html/includes/' . $relativePath,
        '/home/olastofx/public_html/includes/' . $relativePath,
    ];

    foreach ($candidates as $candidate) {
        if (is_readable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function apply_require_main_include(string $relativePath): bool
{
    $path = apply_locate_main_include($relativePath);
    if ($path === null) {
        return false;
    }

    require_once $path;

    return true;
}

function apply_require_app_environment(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    if (!apply_require_main_include('app-environment.php')) {
        require_once __DIR__ . '/app-environment-shim.php';
    }

    $loaded = true;
}

function apply_require_date_format_lib(): void
{
    static $loaded = false;
    if ($loaded || function_exists('getDisplayDateFormatOptions')) {
        $loaded = true;

        return;
    }

    if (apply_require_main_include('date-format.php')) {
        $loaded = true;

        return;
    }

    require_once __DIR__ . '/date-format.php';
    $loaded = true;
}

/**
 * Read one row from main ERP system_settings without loading public_html/config.php.
 */
function apply_read_main_setting(PDO $pdo, string $key, string $default = ''): string
{
    $key = trim($key);
    if ($key === '') {
        return $default;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT setting_value FROM system_settings WHERE setting_key = :key LIMIT 1'
        );
        $stmt->execute(['key' => $key]);
        $value = trim((string) $stmt->fetchColumn());

        return $value !== '' ? $value : $default;
    } catch (Throwable $e) {
        error_log('[ApplyBridge] apply_read_main_setting(' . $key . '): ' . $e->getMessage());

        return $default;
    }
}

function apply_format_sheet_date_safe(?string $date): string
{
    apply_require_date_format_lib();
    $pdo = getMainAdminPdo();

    return formatSheetDate($date, $pdo);
}

function getMainAdminPdo(): ?PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = __DIR__ . '/../config/eventstaff-database.php';
    if (is_readable($config)) {
        $eventPdo = null;
        try {
            require $config;
        } catch (Throwable $e) {
            error_log('[ApplyBridge] eventstaff-database.php: ' . $e->getMessage());

            return null;
        }

        if ($eventPdo instanceof PDO) {
            $pdo = $eventPdo;

            return $pdo;
        }

        error_log('[ApplyBridge] eventstaff-database.php did not set $eventPdo');
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

    apply_require_app_environment();
    touchSessionActivity();
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
