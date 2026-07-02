<?php

declare(strict_types=1);

/**
 * One-time / repeatable: set auto-approval confidence threshold low (35%).
 *
 * CLI: php cron/apply-auto-approval-low-threshold.php
 * Web: /cron/apply-auto-approval-low-threshold.php?key=CRON_KEY
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';

const APPLY_LOW_AUTO_APPROVAL_KEY = 'email-encoding-verify-20260606';
const LOW_AUTO_APPROVAL_CONFIDENCE = 35;

$isCli = PHP_SAPI === 'cli' || defined('STDIN');

function apply_low_auto_approval_json(array $payload, int $code = 200): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (PHP_SAPI === 'cli') {
        echo PHP_EOL;
    }
    exit($code >= 400 ? 1 : 0);
}

try {
    $pdo = getDB();

    if (!$isCli) {
        $key = trim((string) ($_GET['key'] ?? ''));
        $allowed = array_values(array_unique(array_filter([
            trim(getSetting($pdo, 'reminder_cron_key', '')),
            APPLY_LOW_AUTO_APPROVAL_KEY,
        ])));
        $keyOk = false;
        foreach ($allowed as $allowedKey) {
            if ($key !== '' && hash_equals($allowedKey, $key)) {
                $keyOk = true;
                break;
            }
        }
        if (!$keyOk) {
            apply_low_auto_approval_json(['ok' => false, 'error' => 'Forbidden'], 403);
        }
    }

    $before = getSetting($pdo, 'auto_approval_min_confidence', '70');
    setSetting($pdo, 'auto_approval_min_confidence', (string) LOW_AUTO_APPROVAL_CONFIDENCE);
    $after = getSetting($pdo, 'auto_approval_min_confidence', '');

    apply_low_auto_approval_json([
        'ok'       => true,
        'before'   => $before,
        'after'    => $after,
        'message'  => 'Auto-approval minimum confidence set to ' . LOW_AUTO_APPROVAL_CONFIDENCE . '%.',
    ]);
} catch (Throwable $e) {
    apply_low_auto_approval_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
