<?php

declare(strict_types=1);

/** Ensure Sprint 6 platform maturity tables exist (local dev / missed migration). */
function ensurePlatformMaturitySchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $migration = dirname(__DIR__, 2) . '/database/migrate-phase54-platform-maturity.sql';
    if (is_file($migration)) {
        try {
            $pdo->exec((string) file_get_contents($migration));
        } catch (PDOException $e) {
            if (!str_contains($e->getMessage(), 'already exists')) {
                error_log('[EventStaff] platform schema: ' . $e->getMessage());
            }
        }
    }

    $done = true;
}

function requirePlatformFeature(PDO $pdo, string $flagKey, string $label): void
{
    require_once __DIR__ . '/../feature-flags.php';

    if (!isFeatureEnabled($pdo, $flagKey)) {
        setAdminFlash('error', $label . ' is disabled. Enable it in Feature flags (superuser).');
        header('Location: dashboard.php');
        exit;
    }
}
