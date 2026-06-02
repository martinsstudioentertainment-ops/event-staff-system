<?php

require_once __DIR__ . '/admin-capabilities.php';
require_once __DIR__ . '/admin-users-schema.php';

function adminUsersEnsureSchema(PDO $pdo): void
{
    ensureAdminUsersSchema($pdo);
}

/** @return list<array<string, mixed>> */
function listAdminUsers(PDO $pdo): array
{
    adminUsersEnsureSchema($pdo);
    $stmt = $pdo->query(
        'SELECT id, username, full_name, email, role, is_active, created_at, last_login_at
         FROM admin_users
         ORDER BY FIELD(role, \'admin\', \'manager\', \'staff\'), full_name ASC, username ASC'
    );

    return $stmt->fetchAll() ?: [];
}

/** @return array<string, mixed>|null */
function getAdminUserRecord(PDO $pdo, int $id): ?array
{
    adminUsersEnsureSchema($pdo);
    $stmt = $pdo->prepare(
        'SELECT id, username, full_name, email, role, is_active, created_at, last_login_at
         FROM admin_users WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/** @return array<string, string> */
function validateAdminUserInput(array $data, bool $isCreate): array
{
    $errors = [];

    $username = trim((string) ($data['username'] ?? ''));
    $fullName = trim((string) ($data['full_name'] ?? ''));
    $email    = trim((string) ($data['email'] ?? ''));
    $role     = trim((string) ($data['role'] ?? 'staff'));
    $password = (string) ($data['password'] ?? '');

    if ($username === '') {
        $errors['username'] = 'Username is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
        $errors['username'] = 'Username must be 3–50 characters (letters, numbers, . _ -).';
    }

    if ($fullName === '') {
        $errors['full_name'] = 'Full name is required.';
    } elseif (strlen($fullName) > 100) {
        $errors['full_name'] = 'Full name is too long.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }

    if (!in_array($role, getAdminRoleOptions(), true)) {
        $errors['role'] = 'Invalid role selected.';
    }

    if ($isCreate && strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    } elseif (!$isCreate && $password !== '' && strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }

    return $errors;
}

function adminUsernameExists(PDO $pdo, string $username, ?int $excludeId = null): bool
{
    $sql = 'SELECT id FROM admin_users WHERE username = :username';
    $params = ['username' => trim($username)];

    if ($excludeId !== null) {
        $sql .= ' AND id != :id';
        $params['id'] = $excludeId;
    }

    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);

    return (bool) $stmt->fetchColumn();
}

/** @return true|string */
function createAdminUser(PDO $pdo, array $data): bool|string
{
    $errors = validateAdminUserInput($data, true);
    if ($errors !== []) {
        return reset($errors);
    }

    if (adminUsernameExists($pdo, (string) $data['username'])) {
        return 'That username is already taken.';
    }

    $stmt = $pdo->prepare(
        'INSERT INTO admin_users (username, password_hash, full_name, email, role, is_active)
         VALUES (:username, :password_hash, :full_name, :email, :role, :is_active)'
    );
    $stmt->execute([
        'username'      => trim((string) $data['username']),
        'password_hash' => password_hash((string) $data['password'], PASSWORD_DEFAULT),
        'full_name'     => trim((string) $data['full_name']),
        'email'         => trim((string) ($data['email'] ?? '')) ?: null,
        'role'          => (string) $data['role'],
        'is_active'     => !empty($data['is_active']) ? 1 : 0,
    ]);

    return true;
}

/** @return true|string */
function updateAdminUser(PDO $pdo, int $id, array $data, int $actingAdminId): bool|string
{
    $existing = getAdminUserRecord($pdo, $id);
    if (!$existing) {
        return 'User not found.';
    }

    $errors = validateAdminUserInput($data, false);
    if ($errors !== []) {
        return reset($errors);
    }

    if (adminUsernameExists($pdo, (string) $data['username'], $id)) {
        return 'That username is already taken.';
    }

    $newRole   = (string) $data['role'];
    $newActive = !empty($data['is_active']) ? 1 : 0;

    if ((int) $existing['id'] === $actingAdminId) {
        if ($newRole !== 'admin') {
            return 'You cannot change your own role away from Administrator.';
        }
        if ($newActive !== 1) {
            return 'You cannot deactivate your own account.';
        }
    }

    if ($existing['role'] === 'admin' && $newRole !== 'admin') {
        $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
        if ($adminCount <= 1) {
            return 'At least one active Administrator is required.';
        }
    }

    $password = (string) ($data['password'] ?? '');
    $sql      = 'UPDATE admin_users SET username = :username, full_name = :full_name, email = :email,
                 role = :role, is_active = :is_active';
    $params   = [
        'username'  => trim((string) $data['username']),
        'full_name' => trim((string) $data['full_name']),
        'email'     => trim((string) ($data['email'] ?? '')) ?: null,
        'role'      => $newRole,
        'is_active' => $newActive,
        'id'        => $id,
    ];

    if ($password !== '') {
        $sql .= ', password_hash = :password_hash';
        $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }

    $sql .= ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);

    return true;
}

/** @return true|string */
function deactivateAdminUser(PDO $pdo, int $id, int $actingAdminId): bool|string
{
    if ($id === $actingAdminId) {
        return 'You cannot deactivate your own account.';
    }

    $existing = getAdminUserRecord($pdo, $id);
    if (!$existing) {
        return 'User not found.';
    }

    if ($existing['role'] === 'admin') {
        $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
        if ($adminCount <= 1) {
            return 'At least one active Administrator is required.';
        }
    }

    $pdo->prepare('UPDATE admin_users SET is_active = 0 WHERE id = :id')->execute(['id' => $id]);

    return true;
}

function touchAdminLastLogin(PDO $pdo, int $id): void
{
    adminUsersEnsureSchema($pdo);
    $pdo->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = :id')->execute(['id' => $id]);
}
