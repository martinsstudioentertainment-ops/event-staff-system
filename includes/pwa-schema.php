<?php

/** Ensure PWA push subscription table exists. */
function ensurePwaSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;

    try {
        $exists = $pdo->query("SHOW TABLES LIKE 'push_subscriptions'")->fetchColumn();
        if ($exists) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }

    $path = dirname(__DIR__) . '/database/migrate-phase29-pwa.sql';
    if (!is_file($path)) {
        return;
    }

    $sql = file_get_contents($path);
    if ($sql !== false && trim($sql) !== '') {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // Table may already exist from parallel request.
        }
    }
}
