<?php
/**
 * Apply shift times from database/event-shift-times.php.
 * - Clears times_confirmed on all events (registration shows date only).
 * - For each row in the data file: sets start/end + times_confirmed = 1.
 *
 * Usage: php database/apply-event-shift-times.php
 */

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/event-times-schema.php';
require_once __DIR__ . '/../includes/events-repository.php';

$dataFile = __DIR__ . '/event-shift-times.php';
if (!is_file($dataFile)) {
    echo "Missing {$dataFile}\n";
    exit(1);
}

$rows = require $dataFile;
if (!is_array($rows)) {
    echo "event-shift-times.php must return an array.\n";
    exit(1);
}

$pdo = getDB();
ensureEventTimesSchema($pdo);

$pdo->exec('UPDATE events SET times_confirmed = 0');

$normalizeTime = static function (string $time): string {
    $time = trim($time);
    if (preg_match('/^\d{2}:\d{2}$/', $time)) {
        return $time . ':00';
    }
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
        return $time;
    }

    return '';
};

$find = $pdo->prepare(
    'SELECT id, name, event_date, start_time, end_time
     FROM events
     WHERE event_date = :event_date AND name = :name
     LIMIT 2'
);

$update = $pdo->prepare(
    'UPDATE events
     SET start_time = :start_time, end_time = :end_time, times_confirmed = 1
     WHERE id = :id'
);

$applied = 0;
$skipped = 0;

foreach ($rows as $index => $row) {
    if (!is_array($row)) {
        continue;
    }

    $date  = trim((string) ($row['date'] ?? ''));
    $name  = trim((string) ($row['name'] ?? ''));
    $start = $normalizeTime((string) ($row['start'] ?? ''));
    $end   = $normalizeTime((string) ($row['end'] ?? ''));

    if ($date === '' || $name === '' || $start === '' || $end === '') {
        echo 'Skip row ' . ($index + 1) . ": missing date, name, start, or end\n";
        $skipped++;
        continue;
    }

    $find->execute(['event_date' => $date, 'name' => $name]);
    $matches = $find->fetchAll();

    if (count($matches) === 0) {
        echo "No event: {$name} on {$date}\n";
        $skipped++;
        continue;
    }

    if (count($matches) > 1) {
        echo "Ambiguous: {$name} on {$date} (" . count($matches) . " rows)\n";
        $skipped++;
        continue;
    }

    $event = $matches[0];
    if ($start >= $end) {
        echo "Invalid times for {$name} on {$date}: end must be after start\n";
        $skipped++;
        continue;
    }

    $update->execute([
        'start_time' => $start,
        'end_time'   => $end,
        'id'         => (int) $event['id'],
    ]);

    echo 'OK #' . $event['id'] . ' ' . $name . ' ' . $date . ' ' . substr($start, 0, 5) . '–' . substr($end, 0, 5) . "\n";
    $applied++;
}

$withTimes = (int) $pdo->query('SELECT COUNT(*) FROM events WHERE times_confirmed = 1')->fetchColumn();
$total     = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();

echo "\nApplied {$applied} shift(s), skipped {$skipped}.\n";
echo "Events showing time on registration: {$withTimes} / {$total}\n";
echo "Edit database/event-shift-times.php and run this script again to add more.\n";
