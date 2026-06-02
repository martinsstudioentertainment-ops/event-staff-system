<?php
/**
 * CLI: import summer 2026 master roster into the database.
 *
 * Usage:
 *   php database/sync-live-events.php
 *   php database/sync-live-events.php --dry-run
 */

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/live-events-sync.php';

$dryRun = in_array('--dry-run', $argv, true);

try {
    $result = syncLiveEventsFromMasterFile(getDB(), $dryRun);
} catch (Throwable $e) {
    echo $e->getMessage() . "\n";
    exit(1);
}

foreach ($result['messages'] as $line) {
    echo $line . "\n";
}
foreach ($result['errors'] as $line) {
    echo "ERROR: {$line}\n";
}

echo "\nWorking for: {$result['main_security_company']}\n";
echo "Done. Created {$result['created']}, updated {$result['updated']}, skipped {$result['skipped']}.\n";
if ($dryRun) {
    echo "Re-run without --dry-run to apply.\n";
}

exit($result['success'] ? 0 : 1);
