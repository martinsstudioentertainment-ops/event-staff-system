<?php

declare(strict_types=1);

require_once __DIR__ . '/production-readiness.php';

function ensureNotificationCenterSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    if (tableExists($pdo, 'app_notifications')) {
        $ready = true;

        return;
    }

    $sql = file_get_contents(dirname(__DIR__) . '/database/migrate-phase43-notification-center.sql');
    if (is_string($sql) && trim($sql) !== '') {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            error_log('[EventStaff] ensureNotificationCenterSchema: ' . $e->getMessage());
        }
    }

    $ready = tableExists($pdo, 'app_notifications');
}
