<?php

declare(strict_types=1);

/**
 * Restart the 24/7 background heartbeat if the self-chain stopped (server restart, timeout, etc.).
 * Runs on any page load (staff app, registration, check-in, public site) — not only admin.
 */

if (PHP_SAPI === 'cli') {
    return;
}

register_shutdown_function(static function (): void {
    try {
        if (!function_exists('getDB')) {
            return;
        }
        $pdo = getDB();
        require_once __DIR__ . '/google-sheets-auto-worker.php';
        systemHeartbeatEnsureLoopRunning($pdo);
    } catch (Throwable $e) {
        // Never block page loads for background automation.
    }
});
