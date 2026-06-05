<?php

declare(strict_types=1);

require_once __DIR__ . '/apply-sso.php';

function apply_cron_secret(): string
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }

    $local = __DIR__ . '/../config/sso.local.php';
    if (is_readable($local)) {
        $cfg = require $local;
        if (is_array($cfg) && !empty($cfg['cron_key'])) {
            $secret = (string) $cfg['cron_key'];

            return $secret;
        }
    }

    $env = (string) (getenv('APPLY_CRON_KEY') ?: '');
    if ($env !== '') {
        $secret = $env;

        return $secret;
    }

    $secret = hash('sha256', getApplySsoSecret() . '|olasentra-apply-cron-v1');

    return $secret;
}

function require_apply_cron_key(): void
{
    $expected = apply_cron_secret();
    $provided = (string) ($_GET['key'] ?? $_SERVER['HTTP_X_APPLY_CRON_KEY'] ?? '');

    if ($provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
}

function apply_cron_url(): string
{
    require_once __DIR__ . '/apply-urls.php';

    return apply_absolute_url('cron/sync-payroll.php?key=' . urlencode(apply_cron_secret()));
}
