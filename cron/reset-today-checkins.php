<?php
/**
 * List or clear today's check-in records (dashboard “Today's check-ins” metric).
 *
 * CLI:
 *   php cron/reset-today-checkins.php --scan
 *   php cron/reset-today-checkins.php --confirm
 *
 * Web:
 *   /cron/reset-today-checkins.php?key=KEY&scan=1
 *   /cron/reset-today-checkins.php?key=KEY&confirm=1
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');
$opts  = $isCli ? getopt('', ['scan', 'confirm', 'key::']) : [];

function reset_checkins_json(array $payload, int $code = 200): void
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

if (!$isCli) {
    $pdo = getDB();
    require_once dirname(__DIR__) . '/includes/settings-repository.php';
    $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $providedKey = trim((string) ($_GET['key'] ?? ''));
    if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
        reset_checkins_json(['ok' => false, 'error' => 'Forbidden'], 403);
    }
}

$scan    = $isCli ? array_key_exists('scan', $opts) : !empty($_GET['scan']);
$confirm = $isCli ? array_key_exists('confirm', $opts) : !empty($_GET['confirm']);

if (!$scan && !$confirm) {
    reset_checkins_json([
        'ok'    => false,
        'error' => 'Use scan=1 to list or confirm=1 to delete today\'s check-ins',
    ], 400);
}

try {
    $pdo   = getDB();
    $rows  = listTodayCheckinRows($pdo);
    $count = getTodayCheckinCount($pdo);

    if ($scan) {
        reset_checkins_json([
            'ok'              => true,
            'mode'            => 'scan',
            'today_checkins'  => $count,
            'rows'            => $rows,
        ]);
    }

    $result = resetAllTodayCheckins($pdo);
    reset_checkins_json([
        'ok'             => true,
        'mode'           => 'confirm',
        'deleted'        => $result['deleted'],
        'cleared_rows'   => $result['rows'],
        'today_checkins' => getTodayCheckinCount($pdo),
    ]);
} catch (Throwable $e) {
    reset_checkins_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
