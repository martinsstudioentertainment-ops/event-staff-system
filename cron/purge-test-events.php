<?php
/**
 * Delete test events marked [TEST DELETE] (cron key required on web).
 *
 * Web: /cron/purge-test-events.php?key=KEY&confirm=1
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/event-complete-purge.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');
if (!$isCli) {
    require_once dirname(__DIR__) . '/includes/settings-repository.php';
    $pdo = getDB();
    $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $providedKey = trim((string) ($_GET['key'] ?? ''));
    if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
}

$confirm = $isCli ? in_array('--confirm', $argv ?? [], true) : !empty($_GET['confirm']);

function purge_test_json(array $payload, int $code = 200): void
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
    $stmt = $pdo->query(
        "SELECT id, name, event_date FROM events
         WHERE name LIKE '%[TEST DELETE]%'
         ORDER BY id ASC"
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$confirm) {
        purge_test_json(['ok' => true, 'mode' => 'scan', 'events' => $rows, 'count' => count($rows)]);
    }

    $deleted = [];
    $errors  = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $result = deleteEventCompletely($pdo, $id);
        if ($result['ok'] ?? false) {
            $deleted[] = ['id' => $id, 'name' => $row['name'] ?? ''];
        } else {
            $errors[] = ['id' => $id, 'name' => $row['name'] ?? '', 'error' => $result['error'] ?? 'failed'];
        }
    }

    purge_test_json([
        'ok'      => $errors === [],
        'mode'    => 'confirm',
        'deleted' => $deleted,
        'errors'  => $errors,
    ], $errors === [] ? 200 : 500);
} catch (Throwable $e) {
    purge_test_json(['ok' => false, 'error' => $e->getMessage()], 500);
}
