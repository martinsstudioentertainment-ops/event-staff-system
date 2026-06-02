<?php
/**
 * Set upcoming events to PSA security roles only (dsp, static) — removes steward from roles_needed.
 *
 * Usage:
 *   php database/align-event-security-roles.php
 *   php database/align-event-security-roles.php --dry-run
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/venues-repository.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

try {
    $pdo = getDB();
    ensureVenuesSchema($pdo);

    $stmt = $pdo->query(
        "SELECT id, name, event_date, roles_needed
         FROM events
         WHERE is_active = 1 AND event_date >= CURDATE()
         ORDER BY event_date ASC, name ASC"
    );
    $rows = $stmt->fetchAll();
    $updated = 0;

    foreach ($rows as $row) {
        $needed = normalizeRolesNeeded($row);
        $needed = array_values(array_filter(
            $needed,
            static fn(string $r): bool => in_array($r, ['dsp', 'static'], true)
        ));
        if ($needed === []) {
            $needed = ['dsp', 'static'];
        }
        $new = rolesNeededToString($needed);
        $old = (string) ($row['roles_needed'] ?? '');
        if ($old === $new) {
            continue;
        }

        echo ($dryRun ? '[dry-run] ' : '') . "Event #{$row['id']} {$row['name']} ({$row['event_date']}): {$old} → {$new}\n";

        if (!$dryRun) {
            $up = $pdo->prepare('UPDATE events SET roles_needed = :roles WHERE id = :id');
            $up->execute(['roles' => $new, 'id' => (int) $row['id']]);
            $updated++;
        }
    }

    echo $dryRun
        ? "Dry run complete. Re-run without --dry-run to apply.\n"
        : "Updated {$updated} upcoming event(s).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
