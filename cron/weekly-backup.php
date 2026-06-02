<?php
/**
 * Weekly full backup — database + settings + site files (overwrites previous copy).
 *
 * Schedule once per week, e.g. Sunday 03:00:
 *   0 3 * * 0 php /path/to/event-staff-system/cron/weekly-backup.php
 *
 * Also runs when "Auto backup" is enabled and legacy cron/auto-backup.php is called.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/system-settings.php';
require_once __DIR__ . '/../includes/weekly-backup.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');

if (!$isCli) {
    try {
        $pdo = getDB();
        $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
        $providedKey = trim((string) ($_GET['key'] ?? ''));

        if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "Forbidden\n";
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo "Database error\n";
        exit;
    }
}

try {
    $pdo    = getDB();
    $result = runWeeklyFullBackup($pdo);
    $line   = sprintf(
        "[%s] Weekly backup — %s\n",
        date('Y-m-d H:i:s'),
        $result['message']
    );

    $logDir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    file_put_contents($logDir . '/backups.log', $line, FILE_APPEND | LOCK_EX);

    if ($isCli) {
        echo $line;
        exit($result['success'] ? 0 : 1);
    }

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => $result['success'], 'result' => $result], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    if ($isCli) {
        fwrite(STDERR, 'Weekly backup failed: ' . $e->getMessage() . "\n");
        exit(1);
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Weekly backup failed.']);
    exit;
}
