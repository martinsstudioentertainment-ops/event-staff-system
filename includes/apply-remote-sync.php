<?php

declare(strict_types=1);

require_once __DIR__ . '/site-urls.php';
require_once __DIR__ . '/apply-sso.php';

/**
 * Derived cron key — matches apply/admin/includes/cron-auth.php default.
 */
function getApplyPortalCronKey(): string
{
    return hash('sha256', getApplySsoSecret() . '|olasentra-apply-cron-v1');
}

function getApplyPortalCronSyncUrl(?PDO $pdo = null, bool $force = false): string
{
    $root = getApplySiteUrl($pdo);
    if ($root === '') {
        return '';
    }

    $url = rtrim($root, '/') . '/admin/cron/sync-payroll.php?key=' . rawurlencode(getApplyPortalCronKey());
    if ($force) {
        $url .= '&force=1';
    }

    return $url;
}

/**
 * Fire-and-forget HTTP ping to apply payroll + sheet sync (no cPanel cron needed).
 */
function triggerApplyPortalSyncAsync(?PDO $pdo = null, bool $force = false): void
{
    $url = getApplyPortalCronSyncUrl($pdo, $force);
    if ($url === '') {
        return;
    }

    if (!function_exists('curl_init')) {
        return;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS        => 2000,
        CURLOPT_CONNECTTIMEOUT_MS => 800,
        CURLOPT_NOSIGNAL          => 1,
    ]);

    @curl_exec($ch);
    curl_close($ch);
}
