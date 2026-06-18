<?php

declare(strict_types=1);

/** Ensure mobile_app_releases table exists. */
function ensureMobileAppReleaseSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;

    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'mobile_app_releases'")->fetchColumn();
        if ($exists) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }

    $path = dirname(__DIR__, 3) . '/database/migrate-phase70-mobile-app-releases.sql';
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
