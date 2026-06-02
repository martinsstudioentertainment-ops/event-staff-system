<?php
/**
 * Set staff_needed on active upcoming events that are still blank.
 * Uses system setting default_event_staff_needed (default 30).
 *
 * Usage: php database/apply-default-staff-needed.php
 */

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/settings-repository.php';
require_once __DIR__ . '/../includes/event-capacity.php';

$pdo = getDB();

$key = 'default_event_staff_needed';
$current = getSetting($pdo, $key, '');
if ($current === '') {
    setSetting($pdo, $key, '30');
    echo "Set system default_event_staff_needed = 30\n";
}

$default = getDefaultEventStaffNeeded($pdo);
if ($default === null) {
    echo "No valid default_event_staff_needed in settings. Set a number in Admin or run with default 30.\n";
    exit(1);
}

$stmt = $pdo->prepare(
    'UPDATE events
     SET staff_needed = :needed
     WHERE is_active = 1
       AND event_date >= CURDATE()
       AND (staff_needed IS NULL OR staff_needed = 0)'
);
$stmt->execute(['needed' => $default]);
$updated = $stmt->rowCount();

echo "Updated {$updated} event(s) to staff_needed = {$default}.\n";
echo "Edit individual events in Admin → Events → Edit if counts differ.\n";
