<?php

/**
 * Register visitor tracking on public page loads (runs after response via shutdown).
 */

if (PHP_SAPI === 'cli') {
    return;
}

require_once __DIR__ . '/website-visitor-tracking.php';

register_shutdown_function(static function (): void {
    try {
        trackWebsiteVisit();
    } catch (Throwable $e) {
        // Never block page loads for analytics.
    }
});
