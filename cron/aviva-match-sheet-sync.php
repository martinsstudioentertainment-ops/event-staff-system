<?php
declare(strict_types=1);
/**
 * AVIVA Match – Event Staff: import Google Form sheet rows (registered-only, cap 70).
 * COMMAND LINE ONLY (no web/key path). Web access is also denied in cron/.htaccess.
 * cPanel cron (every 5 min):
 *   cd /home/olastofx/public_html && /usr/local/bin/php -q cron/aviva-match-sheet-sync.php >> /home/olastofx/public_html/storage/logs/aviva-cron-stderr.log 2>&1
 * Result log (one JSON line per run): storage/logs/aviva-match-sheet-sync.log
 */

// Command-line detection. cPanel's /usr/local/bin/php can be the CGI binary (PHP_SAPI 'cgi-fcgi'),
// which is why the earlier key-checked cron never imported. A real web request always has REQUEST_METHOD.
$isCommandLine = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg'
    || (!isset($_SERVER['REQUEST_METHOD']) && !isset($_SERVER['SERVER_SOFTWARE']) && !isset($_SERVER['HTTP_HOST']));
if (!$isCommandLine) {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo "Forbidden\n";
    exit;
}

@set_time_limit(240);
chdir(dirname(__DIR__));
// Sensible defaults for code that reads web server vars (only inside this script).
$_SERVER['HTTP_HOST']     = $_SERVER['HTTP_HOST'] ?? 'olasentra.com';
$_SERVER['SERVER_NAME']   = $_SERVER['SERVER_NAME'] ?? 'olasentra.com';
$_SERVER['HTTPS']         = $_SERVER['HTTPS'] ?? 'on';
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__);
$_SERVER['REQUEST_URI']   = $_SERVER['REQUEST_URI'] ?? '/cron/aviva-match-sheet-sync.php';
$_SERVER['REMOTE_ADDR']   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/aviva-match-sheet-sync.php';

$logDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }

function avivaMatchCronLog(string $logDir, array $result): void
{
    $file = $logDir . '/aviva-match-sheet-sync.log';
    $line = json_encode(['ts' => date('c'), 'sapi' => PHP_SAPI] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    // keep the last ~500 lines
    if (@filesize($file) > 400000) {
        $lines = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
        @file_put_contents($file, implode(PHP_EOL, array_slice($lines, -500)) . PHP_EOL, LOCK_EX);
    }
}

$lock = fopen($logDir . '/aviva-match-sheet-sync.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $result = ['ok' => true, 'skipped' => 'already running'];
    avivaMatchCronLog($logDir, $result);
    echo json_encode($result) . PHP_EOL;
    exit;
}
try {
    $result = avivaMatchSheetSync(getDB());
} catch (Throwable $e) {
    error_log('[EventStaff] aviva-match-sheet-sync: ' . $e->getMessage());
    $result = ['ok' => false, 'error' => 'Sync failed: ' . substr($e->getMessage(), 0, 200)];
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
avivaMatchCronLog($logDir, $result);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
