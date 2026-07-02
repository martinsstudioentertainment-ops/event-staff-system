<?php

declare(strict_types=1);

/**
 * Read-only bridge from main ERP to Apply vault DB (staff_master).
 *
 * Layouts supported:
 * - Monorepo / Laragon: {project}/apply/admin/config/database.php
 * - Namecheap split docroot: {account}/apply/admin/config/database.php (sibling of public_html)
 */
function applyVaultDatabaseConfigCandidates(): array
{
    $platformDir = __DIR__;
    $candidates  = [];

    if (defined('APPLY_VAULT_DATABASE_CONFIG') && (string) APPLY_VAULT_DATABASE_CONFIG !== '') {
        $candidates[] = (string) APPLY_VAULT_DATABASE_CONFIG;
    }

    $webRoot     = dirname($platformDir, 2);
    $accountRoot = dirname($platformDir, 3);

    $candidates[] = $webRoot . '/apply/admin/config/database.php';
    $candidates[] = $accountRoot . '/apply/admin/config/database.php';
    $candidates[] = dirname($webRoot) . '/apply/admin/config/database.php';

    return array_values(array_unique($candidates));
}

function getApplyVaultDatabaseConfigPath(): ?string
{
    foreach (applyVaultDatabaseConfigCandidates() as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function getApplyVaultPdo(): ?PDO
{
    static $applyPdo = null;
    static $loaded   = false;

    if ($loaded) {
        return $applyPdo instanceof PDO ? $applyPdo : null;
    }

    $loaded = true;
    $path   = getApplyVaultDatabaseConfigPath();

    if ($path === null) {
        return null;
    }

    try {
        /** @noinspection PhpIncludeInspection */
        require $path;
        if (isset($applyPdo) && $applyPdo instanceof PDO) {
            return $applyPdo;
        }
        if (isset($pdo) && $pdo instanceof PDO) {
            $applyPdo = $pdo;

            return $applyPdo;
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] apply-vault-bridge: ' . $e->getMessage());
    }

    return null;
}

/** @return array{connected: bool, config_path: ?string, candidates: list<string>, vault_rows: ?int} */
function getApplyVaultBridgeStatus(): array
{
    $path = getApplyVaultDatabaseConfigPath();
    $pdo  = getApplyVaultPdo();
    $rows = null;

    if ($pdo instanceof PDO) {
        try {
            $rows = (int) $pdo->query('SELECT COUNT(*) FROM staff_master')->fetchColumn();
        } catch (Throwable $e) {
            $rows = null;
        }
    }

    return [
        'connected'   => $pdo instanceof PDO,
        'config_path' => $path,
        'candidates'  => applyVaultDatabaseConfigCandidates(),
        'vault_rows'  => $rows,
    ];
}

/**
 * Remove a staff vault row (apply site) and linked PSA uploads by email.
 *
 * @return array<string, mixed>
 */
function purgeApplyVaultByEmail(?PDO $applyPdo, string $email): array
{
    if (!$applyPdo instanceof PDO) {
        return [
            'ok'      => false,
            'email'   => strtolower(trim($email)),
            'error'   => 'Apply vault database not connected',
            'deleted' => 0,
        ];
    }

    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'email' => $email, 'error' => 'Invalid email', 'deleted' => 0];
    }

    $stmt = $applyPdo->prepare('SELECT * FROM staff_master WHERE LOWER(TRIM(email)) = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return [
            'ok'      => true,
            'email'   => $email,
            'deleted' => 0,
            'message' => 'No apply vault record for this email.',
        ];
    }

    $vaultId = (int) ($row['id'] ?? 0);
    $filesRemoved = 0;
    $applyRoot    = dirname(__DIR__, 2) . '/apply/admin';
    foreach (['psa_front_image', 'psa_back_image'] as $field) {
        if (!array_key_exists($field, $row)) {
            continue;
        }
        $stored = trim((string) ($row[$field] ?? ''));
        if ($stored === '') {
            continue;
        }
        $candidates = [
            $applyRoot . '/' . ltrim(str_replace('\\', '/', $stored), '/'),
            dirname(__DIR__, 2) . '/' . ltrim(str_replace('\\', '/', $stored), '/'),
        ];
        foreach ($candidates as $path) {
            if (is_file($path) && @unlink($path)) {
                $filesRemoved++;
                break;
            }
        }
    }

    $stmt = $applyPdo->prepare('DELETE FROM staff_master WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $vaultId]);
    $deleted = $stmt->rowCount();

    return [
        'ok'             => true,
        'email'          => $email,
        'vault_id'       => $vaultId,
        'deleted'        => $deleted,
        'files_removed'  => $filesRemoved,
        'still_present'  => findApplyVaultRecordByEmail($applyPdo, $email) !== null,
    ];
}

/** @return array<string, mixed>|null */
function findApplyVaultRecordByEmail(?PDO $applyPdo, string $email): ?array
{
    if (!$applyPdo instanceof PDO) {
        return null;
    }

    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    try {
        $stmt = $applyPdo->prepare(
            'SELECT id, first_name, last_name, email, phone, psa_licence, profile_status
             FROM staff_master WHERE LOWER(TRIM(email)) = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}
