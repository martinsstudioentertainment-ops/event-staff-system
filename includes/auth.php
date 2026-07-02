<?php
/**
 * Event Staff System — Admin authentication
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/app-environment.php';

if (session_status() === PHP_SESSION_NONE) {
    initSecureSession();
}

function isAdminLoggedIn(): bool
{
    return !empty($_SESSION['admin_id']);
}

function requireAdmin(): void
{
    if (!isAdminLoggedIn()) {
        header('Location: login.php');
        exit;
    }

    $adminIdleTtl = defined('ADMIN_SESSION_IDLE_TTL') ? ADMIN_SESSION_IDLE_TTL : APP_SESSION_IDLE_TTL;
    if (sessionIdleExpired('app_session_last_activity', $adminIdleTtl)) {
        logoutAdmin();
        header('Location: login.php?timeout=1');
        exit;
    }

    require_once __DIR__ . '/admin-users-schema.php';
    refreshAdminSession(getDB());

    if (!isAdminLoggedIn()) {
        header('Location: login.php');
        exit;
    }

    touchSessionActivity();
    enforceDefaultPasswordChange();
}

function enforceDefaultPasswordChange(): void
{
    if (!isProductionApp()) {
        return;
    }

    try {
        $pdo = getDB();
    } catch (Throwable $e) {
        return;
    }

    require_once __DIR__ . '/production-readiness.php';
    if (!adminAccountUsesDefaultPassword($pdo)) {
        return;
    }

    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $allowed = [
        'settings-account.php',
        'logout.php',
        'login.php',
        'go-live.php',
        'go-live-action.php',
    ];

    if (in_array($script, $allowed, true)) {
        return;
    }

    setAdminFlash('error', 'Change the default admin password before using the admin panel.');
    header('Location: settings-account.php');
    exit;
}

function getAdminUser(): ?array
{
    if (!isAdminLoggedIn()) {
        return null;
    }

    return [
        'id'       => (int) $_SESSION['admin_id'],
        'username' => (string) ($_SESSION['admin_username'] ?? ''),
        'name'     => (string) ($_SESSION['admin_name'] ?? ''),
        'role'     => (string) ($_SESSION['admin_role'] ?? 'staff'),
        'email'    => (string) ($_SESSION['admin_email'] ?? ''),
    ];
}

function getAdminRole(): string
{
    return (string) ($_SESSION['admin_role'] ?? 'staff');
}

function refreshAdminSession(PDO $pdo): void
{
    if (!isAdminLoggedIn()) {
        return;
    }

    require_once __DIR__ . '/admin-users-schema.php';
    ensureAdminUsersSchema($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, username, full_name, email, role, is_active FROM admin_users WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => (int) $_SESSION['admin_id']]);
    $user = $stmt->fetch();

    if (!$user || !(int) $user['is_active']) {
        logoutAdmin();
        return;
    }

    $_SESSION['admin_username'] = $user['username'];
    $_SESSION['admin_name']     = $user['full_name'];
    $_SESSION['admin_role']     = $user['role'];
    $_SESSION['admin_email']    = (string) ($user['email'] ?? '');
}

function attemptAdminLogin(string $username, string $password): bool
{
    $pdo  = getDB();

    require_once __DIR__ . '/admin-users-schema.php';
    ensureAdminUsersSchema($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, username, password_hash, full_name, email, role, is_active
         FROM admin_users WHERE username = :username LIMIT 1'
    );
    $stmt->execute(['username' => trim($username)]);
    $user = $stmt->fetch();

    if (!$user || !(int) $user['is_active'] || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    $_SESSION['admin_id']       = (int) $user['id'];
    $_SESSION['admin_username'] = $user['username'];
    $_SESSION['admin_name']     = $user['full_name'];
    $_SESSION['admin_role']     = $user['role'] ?? 'staff';
    $_SESSION['admin_email']    = (string) ($user['email'] ?? '');

    require_once __DIR__ . '/admin-users-repository.php';
    touchAdminLastLogin($pdo, (int) $user['id']);
    touchSessionActivity();

    return true;
}

function logoutAdmin(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}

function setAdminFlash(string $type, string $message): void
{
    $_SESSION['admin_flash'] = ['type' => $type, 'message' => $message];
}

function getAdminFlash(): ?array
{
    if (empty($_SESSION['admin_flash'])) {
        return null;
    }

    $flash = $_SESSION['admin_flash'];
    unset($_SESSION['admin_flash']);

    return $flash;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(?string $token): bool
{
    return is_string($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

/**
 * Admin JSON APIs — require login and enforce idle timeout.
 */
function requireAdminApiSession(): void
{
    if (!isAdminLoggedIn()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $adminIdleTtl = defined('ADMIN_SESSION_IDLE_TTL') ? ADMIN_SESSION_IDLE_TTL : APP_SESSION_IDLE_TTL;
    if (sessionIdleExpired('app_session_last_activity', $adminIdleTtl)) {
        logoutAdmin();
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Session expired']);
        exit;
    }

    touchSessionActivity();
}

require_once __DIR__ . '/admin-capabilities.php';
