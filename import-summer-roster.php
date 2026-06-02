<?php
/**
 * One-time summer roster import (production).
 *
 * 1. Add to server config.php: define('ROSTER_IMPORT_TOKEN', 'pick-a-long-random-string');
 * 2. Deploy this file with the rest of the app.
 * 3. Visit once (main site — not register subdomain if that folder is empty):
 *    https://olasentra.com/import-summer-roster.php?token=YOUR_TOKEN
 *    Or while logged in: https://admin.olasentra.com/import-roster.php
 * 4. DELETE this file from the server when done (or use Admin → Events → Import master roster).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/live-events-sync.php';

header('Content-Type: text/plain; charset=utf-8');

if (!defined('ROSTER_IMPORT_TOKEN') || ROSTER_IMPORT_TOKEN === '') {
    echo "Add to config.php:\n  define('ROSTER_IMPORT_TOKEN', 'your-secret-token-here');\n";
    exit(1);
}

$token = (string) ($_GET['token'] ?? '');
if ($token === '' || !hash_equals((string) ROSTER_IMPORT_TOKEN, $token)) {
    http_response_code(403);
    echo "Forbidden. Use: import-summer-roster.php?token=YOUR_TOKEN\n";
    exit;
}

try {
    $result = syncLiveEventsFromMasterFile(getDB(), false);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Import failed: ' . $e->getMessage() . "\n";
    exit;
}

foreach ($result['messages'] as $line) {
    echo $line . "\n";
}
foreach ($result['errors'] as $line) {
    echo 'ERROR: ' . $line . "\n";
}

echo "\nWorking for: {$result['main_security_company']}\n";
echo "Created {$result['created']}, updated {$result['updated']}, skipped {$result['skipped']}\n";
echo "Refresh https://register.olasentra.com/ — shifts should show 1Plus Security and real venues.\n";
