<?php

declare(strict_types=1);

/**
 * Scan (and optionally purge) a registrant across all known tables and log directories.
 *
 *   php scripts/scan-registrant-everywhere.php --email=user@example.com
 *   php scripts/scan-registrant-everywhere.php --email=user@example.com --purge
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/registrant-complete-purge.php';

$opts  = getopt('', ['email:', 'purge', 'json']);
$email = strtolower(trim((string) ($opts['email'] ?? '')));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/scan-registrant-everywhere.php --email=user@example.com [--purge] [--json]\n");
    exit(1);
}

$pdo = getDB();
$scan = scanRegistrantEverywhere($pdo, $email);
$ctx  = collectRegistrantPurgeContext($pdo, $email);
$scan['filesystem_hits'] = scanRegistrantInFilesystem(
    $root,
    $email,
    (string) ($ctx['staff_row']['first_name'] ?? ''),
    (string) ($ctx['staff_row']['surname'] ?? '')
);

if (!empty($opts['purge'])) {
    $scan['purge_result'] = purgeRegistrantCompletely($pdo, $email, false);
}

$scan['generated_at'] = gmdate('c');

if (!empty($opts['json'])) {
    echo json_encode($scan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

echo "Registrant scan — {$email}\n";
echo str_repeat('-', 60) . "\n";
echo 'Staff ID: ' . ($scan['staff_id'] ?? 'none') . PHP_EOL;
echo 'Registration IDs: ' . (empty($scan['registration_ids']) ? 'none' : implode(', ', $scan['registration_ids'])) . PHP_EOL;
echo 'Total DB rows: ' . (int) ($scan['total_rows'] ?? 0) . PHP_EOL;

if (($scan['hits'] ?? []) === []) {
    echo "No database hits.\n";
} else {
    echo "\nDatabase hits:\n";
    foreach ($scan['hits'] as $hit) {
        echo sprintf(
            "  - %s.%s (%s): %d\n",
            $hit['table'] ?? '',
            $hit['column'] ?? '',
            $hit['via'] ?? '',
            (int) ($hit['count'] ?? 0)
        );
    }
}

if (($scan['filesystem_hits'] ?? []) === []) {
    echo "\nNo filesystem hits under storage/logs, uploads, docs.\n";
} else {
    echo "\nFilesystem hits:\n";
    foreach ($scan['filesystem_hits'] as $path) {
        echo "  - {$path}\n";
    }
}

if (!empty($scan['purge_result'])) {
    $pr = $scan['purge_result'];
    echo "\nPurge complete. Remaining rows: " . (int) ($pr['remaining_rows'] ?? 0) . PHP_EOL;
}

exit(0);
