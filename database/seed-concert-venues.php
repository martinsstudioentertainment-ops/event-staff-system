<?php
/**
 * Seed common Irish concert venues and optional bulk-link events.
 *
 * Usage:
 *   php database/seed-concert-venues.php
 *   php database/seed-concert-venues.php --link-default="Aviva Stadium"
 */

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/venues-repository.php';
require_once __DIR__ . '/../includes/events-repository.php';

$pdo = getDB();
ensureVenuesSchema($pdo);

$venues = [
    ['name' => '3Arena', 'venue_type' => 'arena', 'address' => 'North Wall Quay, Dublin 1'],
    ['name' => 'Aviva Stadium', 'venue_type' => 'arena', 'address' => 'Lansdowne Road, Dublin 4'],
    ['name' => 'Croke Park', 'venue_type' => 'arena', 'address' => 'Jones Road, Dublin 3'],
    ['name' => 'RDS Arena', 'venue_type' => 'arena', 'address' => 'Ballsbridge, Dublin 4'],
    ['name' => 'Marley Park', 'venue_type' => 'festival_site', 'address' => 'Grange Road, Rathfarnham, Dublin'],
    ['name' => 'Stradbally Hall', 'venue_type' => 'festival_site', 'address' => 'Stradbally, Co. Laois'],
    ['name' => 'Slane Castle', 'venue_type' => 'festival_site', 'address' => 'Slane, Co. Meath'],
];

$created = 0;
foreach ($venues as $row) {
    $stmt = $pdo->prepare('SELECT id FROM venues WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $stmt->execute(['name' => $row['name']]);
    if ($stmt->fetchColumn()) {
        continue;
    }
    createVenue($pdo, array_merge($row, ['is_active' => 1]));
    ++$created;
}

echo "Seeded {$created} new venue(s). Total venues: " . $pdo->query('SELECT COUNT(*) FROM venues')->fetchColumn() . "\n";

$defaultName = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--link-default=')) {
        $defaultName = trim(substr($arg, strlen('--link-default=')), '"\'');
    }
}

if ($defaultName !== '') {
    $stmt = $pdo->prepare('SELECT id FROM venues WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $stmt->execute(['name' => $defaultName]);
    $venueId = (int) $stmt->fetchColumn();
    if ($venueId < 1) {
        echo "Venue not found for link: {$defaultName}\n";
        exit(1);
    }
    $venue = getVenueById($pdo, $venueId);
    $upd = $pdo->prepare(
        'UPDATE events
         SET venue_id = :venue_id, location = :location
         WHERE is_active = 1 AND event_date >= CURDATE()
           AND (venue_id IS NULL OR venue_id = 0)'
    );
    $upd->execute(['venue_id' => $venueId, 'location' => (string) $venue['name']]);
    propagateVenueDetailsToLinkedEvents($pdo, $venueId);
    echo "Linked {$upd->rowCount()} upcoming event(s) to {$defaultName}\n";
}
