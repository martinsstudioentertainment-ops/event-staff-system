<?php
/**
 * Reset database to empty + fresh summer roster (CLI).
 *
 * Local:
 *   php database/reset-to-zero.php --confirm
 *   php database/reset-to-zero.php --confirm --keep-settings
 *   php database/reset-to-zero.php --confirm --data-only
 *
 * Production: use Admin → Go live → Reset database to zero (safer).
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/database-reset.php';

$confirm      = in_array('--confirm', $argv ?? [], true);
$dataOnly     = in_array('--data-only', $argv ?? [], true);
$keepSettings = in_array('--keep-settings', $argv ?? [], true);

if (!$confirm) {
    fwrite(STDERR, "This deletes all staff, attendance, invoices, and optionally all tables.\n");
    fwrite(STDERR, "Run: php database/reset-to-zero.php --confirm\n");
    fwrite(STDERR, "     php database/reset-to-zero.php --confirm --keep-settings\n");
    fwrite(STDERR, "     php database/reset-to-zero.php --confirm --data-only\n");
    exit(1);
}

if (isProductionApp()) {
    fwrite(STDERR, "Blocked in production CLI. Use Admin → Go live → Reset database to zero.\n");
    exit(1);
}

$pdo = getDB();

echo "Event Staff — database reset\n";
echo str_repeat('-', 40) . "\n";

if ($dataOnly) {
    $result = clearStaffAndOperationalData($pdo);
} else {
    $result = resetDatabaseToZero($pdo, [
        'keep_settings' => $keepSettings,
        'site_name'     => 'Olasentra',
    ]);
}

foreach ($result['messages'] as $line) {
    echo $line . "\n";
}
foreach ($result['errors'] as $line) {
    echo "ERROR: {$line}\n";
}

exit($result['success'] ? 0 : 1);
