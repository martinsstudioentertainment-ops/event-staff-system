<?php

require_once __DIR__ . '/venues-schema.php';
require_once __DIR__ . '/event-times-schema.php';
require_once __DIR__ . '/go-live-schema.php';
require_once __DIR__ . '/event-main-security-schema.php';
require_once __DIR__ . '/events-repository.php';

function getLiveEventsMasterFilePath(): string
{
    return dirname(__DIR__) . '/database/live-events-2026.php';
}

/**
 * @return array{
 *     main_security_company: string,
 *     events: list<array<string, mixed>>,
 *     venue_catalog: array<string, array{name: string, venue_type: string, address: string}>
 * }
 */
function getLiveEventsVenueCatalog(): array
{
    return [
        'Malahide'      => ['name' => 'Malahide Castle', 'venue_type' => 'festival_site', 'address' => 'Malahide, Co. Dublin'],
        'Aviva Stadium' => ['name' => 'Aviva Stadium', 'venue_type' => 'arena', 'address' => 'Lansdowne Road, Dublin 4'],
        'Thomond Park'  => ['name' => 'Thomond Park', 'venue_type' => 'arena', 'address' => 'Limerick'],
        'Slane'         => ['name' => 'Slane Castle', 'venue_type' => 'festival_site', 'address' => 'Slane, Co. Meath'],
        'RHK'           => ['name' => 'RHK', 'venue_type' => 'arena', 'address' => 'Royal Hospital Kilmainham, Dublin 8'],
        'Croke Park'    => ['name' => 'Croke Park', 'venue_type' => 'arena', 'address' => 'Jones Road, Dublin 3'],
        'Stradbally'    => ['name' => 'Stradbally Hall', 'venue_type' => 'festival_site', 'address' => 'Stradbally, Co. Laois'],
    ];
}

/**
 * @return array{main_security_company: string, events: list<array<string, mixed>>}
 */
function loadLiveEventsMasterData(?string $dataFile = null): array
{
    $dataFile = $dataFile ?? getLiveEventsMasterFilePath();
    if (!is_file($dataFile)) {
        throw new RuntimeException('Master roster file not found: ' . $dataFile);
    }

    $loaded = require $dataFile;
    if (!is_array($loaded)) {
        throw new RuntimeException('Master roster file must return an array.');
    }

    $mainSecurity = '';
    $rows         = $loaded;
    if (isset($loaded['events']) && is_array($loaded['events'])) {
        $rows = $loaded['events'];
        if (trim((string) ($loaded['main_security_company'] ?? '')) !== '') {
            $mainSecurity = trim((string) $loaded['main_security_company']);
        }
    }

    return [
        'main_security_company' => $mainSecurity,
        'events'                => $rows,
    ];
}

/**
 * @return array{start: string, end: string, confirmed: int}|null
 */
function resolveLiveEventVenueId(PDO $pdo, string $locationName, ?array $venueMeta): int
{
    $stmt = $pdo->prepare('SELECT id FROM venues WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $stmt->execute(['name' => $locationName]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        return (int) $existing;
    }

    if (is_array($venueMeta)) {
        return createVenue($pdo, array_merge($venueMeta, ['is_active' => 1]));
    }

    return findOrCreateVenueByName($pdo, $locationName, ['location' => $locationName]);
}

/**
 * Link events that have a location name but no venue_id (fixes "Other / not linked" on registration).
 */
function relinkEventsToVenuesByLocation(PDO $pdo): int
{
    ensureVenuesSchema($pdo);

    $stmt = $pdo->query(
        "SELECT e.id, e.location
         FROM events e
         WHERE e.is_active = 1
           AND (e.venue_id IS NULL OR e.venue_id = 0)
           AND e.location IS NOT NULL
           AND TRIM(e.location) <> ''"
    );
    $rows = $stmt->fetchAll();
    $linked = 0;

    $update = $pdo->prepare('UPDATE events SET venue_id = :venue_id WHERE id = :id');

    foreach ($rows as $row) {
        $location = trim((string) $row['location']);
        $venueId  = resolveLiveEventVenueId($pdo, $location, null);
        if ($venueId > 0) {
            $update->execute(['venue_id' => $venueId, 'id' => (int) $row['id']]);
            $linked += $update->rowCount();
        }
    }

    return $linked;
}

/**
 * Direct SQL update so roster fields apply even if the admin event pipeline differs on the server.
 *
 * @param array<string, mixed> $fields
 */
function forceApplyLiveEventRow(PDO $pdo, int $eventId, array $fields): void
{
    if ($eventId < 1) {
        return;
    }

    ensureEventMainSecuritySchema($pdo);
    ensureGoLiveStaffNeededColumn($pdo);
    ensureEventTimesSchema($pdo);

    $venueId = (int) ($fields['venue_id'] ?? 0);
    $stmt    = $pdo->prepare(
        'UPDATE events
         SET location = :location,
             venue_id = :venue_id,
             staff_needed = :staff_needed,
             main_security_company = :main_security_company,
             start_time = :start_time,
             end_time = :end_time,
             times_confirmed = :times_confirmed,
             is_active = 1,
             work_type = \'special_event\',
             roles_needed = \'dsp,static\'
         WHERE id = :id'
    );
    $stmt->execute([
        'location'              => (string) ($fields['location'] ?? ''),
        'venue_id'              => $venueId > 0 ? $venueId : null,
        'staff_needed'          => (int) ($fields['staff_needed'] ?? 0),
        'main_security_company' => (string) ($fields['main_security_company'] ?? ''),
        'start_time'            => (string) ($fields['start_time'] ?? '09:00:00'),
        'end_time'              => (string) ($fields['end_time'] ?? '23:00:00'),
        'times_confirmed'       => (int) ($fields['times_confirmed'] ?? 0),
        'id'                    => $eventId,
    ]);
}

/**
 * @return array<string, mixed>|null
 */
function getLiveRosterSampleEvent(PDO $pdo, string $name = 'Nick Cave'): ?array
{
    ensureEventMainSecuritySchema($pdo);
    $stmt = $pdo->prepare(
        "SELECT id, name, event_date, location, venue_id, staff_needed,
                main_security_company, times_confirmed, start_time, end_time
         FROM events
         WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))
         ORDER BY event_date ASC
         LIMIT 1"
    );
    $stmt->execute(['name' => $name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function parseLiveEventTimes(string $times): ?array
{
    $times = strtoupper(trim($times));
    if ($times === '' || $times === 'TBC') {
        return null;
    }

    $times = str_replace([' ', '.'], ['', ''], $times);
    if (!preg_match('/^(\d{1,2}):?(\d{2})-(\d{1,2}):?(\d{2})$/', $times, $m)) {
        return null;
    }

    $startH = (int) $m[1];
    $startM = (int) $m[2];
    $endH   = (int) $m[3];
    $endM   = (int) $m[4];

    if ($startH < 0 || $startH > 23 || $endH < 0 || $endH > 23 || $startM > 59 || $endM > 59) {
        return null;
    }

    $start = sprintf('%02d:%02d:00', $startH, $startM);
    $end   = sprintf('%02d:%02d:00', $endH, $endM);

    if ($start >= $end) {
        return null;
    }

    return ['start' => $start, 'end' => $end, 'confirmed' => 1];
}

/**
 * Force listed contractor onto every event row in the master roster (by date + name).
 *
 * @param list<array<string, mixed>> $rows
 */
function applyMasterRosterContractorToEvents(PDO $pdo, array $rows, string $company): int
{
    ensureEventMainSecuritySchema($pdo);
    $company = trim($company);
    if ($company === '') {
        return 0;
    }

    $stmt = $pdo->prepare(
        'UPDATE events
         SET main_security_company = :company
         WHERE event_date = :event_date
           AND LOWER(TRIM(name)) = LOWER(TRIM(:name))'
    );

    $filled = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $date = trim((string) ($row['date'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        if ($date === '' || $name === '') {
            continue;
        }
        $employer = trim((string) ($row['main_security_company'] ?? $company));
        if ($employer === '') {
            $employer = $company;
        }
        $stmt->execute([
            'company'    => $employer,
            'event_date' => $date,
            'name'       => $name,
        ]);
        $filled += $stmt->rowCount();
    }

    return $filled;
}

/**
 * Import master roster: date, event name, location, staff needed, est times, working for.
 *
 * @return array{
 *     success: bool,
 *     created: int,
 *     updated: int,
 *     skipped: int,
 *     main_security_company: string,
 *     messages: list<string>,
 *     errors: list<string>
 * }
 */
function syncLiveEventsFromMasterFile(PDO $pdo, bool $dryRun = false, ?string $dataFile = null): array
{
    $master       = loadLiveEventsMasterData($dataFile);
    $rows         = $master['events'];
    $mainSecurity = $master['main_security_company'];
    $venueCatalog = getLiveEventsVenueCatalog();

    ensureVenuesSchema($pdo);
    ensureGoLiveStaffNeededColumn($pdo);
    ensureEventTimesSchema($pdo);
    ensureEventMainSecuritySchema($pdo);
    require_once __DIR__ . '/event-reporting-schema.php';
    require_once __DIR__ . '/event-work-type-schema.php';
    ensureEventReportingSchema($pdo);
    ensureEventWorkTypeSchema($pdo);

    $messages = [];
    $errors   = [];

    foreach ($venueCatalog as $catalog) {
        $stmt = $pdo->prepare('SELECT id FROM venues WHERE LOWER(name) = LOWER(:name) LIMIT 1');
        $stmt->execute(['name' => $catalog['name']]);
        if (!$stmt->fetchColumn()) {
            if ($dryRun) {
                $messages[] = "Would create venue: {$catalog['name']}";
            } else {
                createVenue($pdo, array_merge($catalog, ['is_active' => 1]));
                $messages[] = "Created venue: {$catalog['name']}";
            }
        }
    }

    $find = $pdo->prepare(
        'SELECT id, name, location, staff_needed, times_confirmed
         FROM events
         WHERE event_date = :event_date AND LOWER(TRIM(name)) = LOWER(TRIM(:name))
         LIMIT 2'
    );

    $created = 0;
    $updated = 0;
    $skipped = 0;

    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $date        = trim((string) ($row['date'] ?? ''));
        $name        = trim((string) ($row['name'] ?? ''));
        $locationKey = trim((string) ($row['location'] ?? ''));
        $staffNeeded = (int) ($row['staff_needed'] ?? 0);
        $timesRaw    = trim((string) ($row['times'] ?? 'TBC'));
        $venueMeta   = $venueCatalog[$locationKey] ?? null;
        $locationName = $venueMeta['name'] ?? $locationKey;

        if ($date === '' || $name === '' || $locationKey === '') {
            $errors[] = 'Row ' . ($index + 1) . ': missing date, name, or location';
            $skipped++;
            continue;
        }

        if ($staffNeeded < 1) {
            $errors[] = "Row " . ($index + 1) . ": invalid staff needed for {$name}";
            $skipped++;
            continue;
        }

        $parsedTimes = parseLiveEventTimes($timesRaw);
        $employer    = trim((string) ($row['main_security_company'] ?? $mainSecurity));
        $venueId     = resolveLiveEventVenueId($pdo, $locationName, $venueMeta);
        $eventData   = [
            'name'                  => $name,
            'event_date'            => $date,
            'location'              => $locationName,
            'venue_id'              => $venueId > 0 ? (string) $venueId : '',
            'main_security_company' => $employer,
            'staff_needed'          => $staffNeeded,
            'is_active'             => 1,
            'work_type'             => 'special_event',
            'roles_needed'          => 'dsp,static',
            'start_time'            => $parsedTimes['start'] ?? '09:00',
            'end_time'              => $parsedTimes['end'] ?? '23:00',
            'times_confirmed'       => $parsedTimes['confirmed'] ?? 0,
        ];

        $find->execute(['event_date' => $date, 'name' => $name]);
        $matches = $find->fetchAll();

        if (count($matches) > 1) {
            $errors[] = "Ambiguous: {$name} on {$date} — remove duplicate rows in Admin first";
            $skipped++;
            continue;
        }

        $timeLabel = $parsedTimes ? substr($parsedTimes['start'], 0, 5) . '–' . substr($parsedTimes['end'], 0, 5) : 'TBC';

        if (count($matches) === 0) {
            if ($dryRun) {
                $messages[] = "[dry-run] Create: {$date} {$name} @ {$locationName} · {$staffNeeded} staff · {$timeLabel}";
                $created++;
                continue;
            }

            try {
                $id = createEvent($pdo, $eventData);
            } catch (Throwable $e) {
                $errors[] = "createEvent {$name}: " . $e->getMessage();
                $skipped++;
                continue;
            }
            forceApplyLiveEventRow($pdo, $id, [
                'location'              => $locationName,
                'venue_id'              => $venueId,
                'staff_needed'          => $staffNeeded,
                'main_security_company' => $employer,
                'start_time'            => $parsedTimes['start'] ?? '09:00:00',
                'end_time'              => $parsedTimes['end'] ?? '23:00:00',
                'times_confirmed'       => $parsedTimes['confirmed'] ?? 0,
            ]);
            $messages[] = "Created #{$id} {$date} {$name} @ {$locationName} · {$staffNeeded} staff · {$timeLabel}";
            $created++;
            continue;
        }

        $existing = $matches[0];
        if ($dryRun) {
            $messages[] = "[dry-run] Update #{$existing['id']} {$date} {$name} → {$locationName} · {$staffNeeded} staff · {$timeLabel}";
            $updated++;
            continue;
        }

        $eventId = (int) $existing['id'];
        try {
            updateEvent($pdo, $eventId, $eventData);
        } catch (Throwable $e) {
            $errors[] = "updateEvent #{$eventId} {$name}: " . $e->getMessage();
        }
        forceApplyLiveEventRow($pdo, $eventId, [
            'location'              => $locationName,
            'venue_id'              => $venueId,
            'staff_needed'          => $staffNeeded,
            'main_security_company' => $employer,
            'start_time'            => $parsedTimes['start'] ?? '09:00:00',
            'end_time'              => $parsedTimes['end'] ?? '23:00:00',
            'times_confirmed'       => $parsedTimes['confirmed'] ?? 0,
        ]);
        $messages[] = "Updated #{$eventId} {$date} {$name} @ {$locationName} · {$staffNeeded} staff · {$timeLabel}";
        $updated++;
    }

    if (!$dryRun) {
        $relinked = relinkEventsToVenuesByLocation($pdo);
        if ($relinked > 0) {
            $messages[] = "Linked {$relinked} event(s) to venues by location.";
        }

        if ($mainSecurity !== '') {
            $filled = applyMasterRosterContractorToEvents($pdo, $rows, $mainSecurity);
            if ($filled > 0) {
                $messages[] = "Set listed contractor on {$filled} roster event(s): {$mainSecurity}.";
            }
        }
    }

    return [
        'success'               => $errors === [],
        'created'               => $created,
        'updated'               => $updated,
        'skipped'               => $skipped,
        'main_security_company' => $mainSecurity,
        'messages'              => $messages,
        'errors'                => $errors,
    ];
}
