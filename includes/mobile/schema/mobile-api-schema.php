<?php

declare(strict_types=1);

/** Ensure mobile API infrastructure tables exist. */
function ensureMobileApiSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;

    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'mobile_refresh_tokens'")->fetchColumn();
        if ($exists) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }

    $path = dirname(__DIR__, 3) . '/database/migrate-phase69-mobile-api.sql';
    if (!is_file($path)) {
        return;
    }

    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        return;
    }

    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if ($statement === '' || str_starts_with($statement, '--')) {
            continue;
        }
        try {
            $pdo->exec($statement);
        } catch (Throwable $e) {
            // Table may already exist from parallel request.
        }
    }
}

function mobileApiIsEnabled(PDO $pdo): bool
{
    return getSetting($pdo, 'mobile_api_enabled', '0') === '1';
}

function mobileJwtAccessTtl(PDO $pdo): int
{
    $ttl = (int) getSetting($pdo, 'mobile_jwt_access_ttl', '900');

    return max(60, min($ttl, 3600));
}

function mobileJwtRefreshDays(PDO $pdo): int
{
    $days = (int) getSetting($pdo, 'mobile_jwt_refresh_days', '90');

    return max(1, min($days, 365));
}

function mobileJwtSecret(PDO $pdo): string
{
    $secret = trim(getSetting($pdo, 'mobile_jwt_secret', ''));
    if ($secret !== '') {
        return $secret;
    }

    $secret = bin2hex(random_bytes(32));
    setSetting($pdo, 'mobile_jwt_secret', $secret);

    return $secret;
}

function mobileGenerateJwtSecret(PDO $pdo): string
{
    $secret = bin2hex(random_bytes(32));
    setSetting($pdo, 'mobile_jwt_secret', $secret);

    return $secret;
}
