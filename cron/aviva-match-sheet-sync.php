<?php
declare(strict_types=1);
/**
 * AVIVA Match – Event Staff: import Google Form sheet rows (registered-only, cap 70).
 * CLI (cPanel cron, every minute):
 *   /usr/local/bin/php /home/olastofx/public_html/cron/aviva-match-sheet-sync.php
 * Web: /cron/aviva-match-sheet-sync.php?key=<reminder_cron_key>
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/aviva-match-sheet-sync.php';

$isCli = PHP_SAPI === 'cli';
$pdo = getDB();
if (!$isCli) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    $expected = trim((string) getSetting($pdo, 'reminder_cron_key', ''));
    $fallback = defined('OLASENTRA_CRON_FALLBACK_KEY') ? (string) OLASENTRA_CRON_FALLBACK_KEY : '';
    $given = trim((string) ($_GET['key'] ?? ''));
    $ok = $given !== '' && (($expected !== '' && hash_equals($expected, $given)) || ($fallback !== '' && hash_equals($fallback, $given)));
    if (!$ok) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }
}

$lockDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($lockDir)) { @mkdir($lockDir, 0755, true); }
$lock = fopen($lockDir . '/aviva-match-sheet-sync.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo json_encode(['ok' => true, 'skipped' => 'already running']) . PHP_EOL;
    exit;
}
try {
    $result = avivaMatchSheetSync($pdo);
} catch (Throwable $e) {
    error_log('[EventStaff] aviva-match-sheet-sync: ' . $e->getMessage());
    $result = ['ok' => false, 'error' => 'Sync failed; see error log.'];
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
