<?php

require_once __DIR__ . '/venues-repository.php';
require_once __DIR__ . '/registration-forms.php';
require_once __DIR__ . '/events-repository.php';

/**
 * Active events + venues for a registration form (venue-first UI).
 *
 * @return array{
 *     form: array<string, mixed>,
 *     venues: list<array<string, mixed>>,
 *     eventsByVenue: array<string, list<array<string, mixed>>>,
 *     unassignedVenue: array<string, mixed>|null
 * }
 */
function getRegistrationOptionsForForm(PDO $pdo, string $formSlug): array
{
    ensureVenuesSchema($pdo);

    $form = getRegistrationForm($pdo, $formSlug);
    if ($form === null) {
        $defaults = getDefaultRegistrationForms();
        $form     = $defaults[$formSlug] ?? null;
    }

    if ($form === null) {
        return [
            'form'            => [],
            'venues'          => [],
            'eventsByVenue'   => [],
            'unassignedVenue' => null,
        ];
    }

    $workTypes = getFormAllowedWorkTypes($form);
    $staffRole = normalizeStaffRole((string) ($form['staff_role'] ?? $formSlug));
    $events    = fetchRegistrationEvents($pdo, $workTypes, $staffRole);

    $venuesMap       = [];
    $eventsByVenue   = [];
    $unassigned      = [];

    foreach ($events as $event) {
        if (!isEventOpenForRegistration($event)) {
            continue;
        }

        $formatted = formatRegistrationEvent($event);
        $venueId   = (int) ($event['venue_id'] ?? 0);

        if ($venueId > 0) {
            $key = (string) $venueId;
            if (!isset($eventsByVenue[$key])) {
                $eventsByVenue[$key] = [];
            }
            $eventsByVenue[$key][] = $formatted;

            if (!isset($venuesMap[$venueId])) {
                $venuesMap[$venueId] = formatRegistrationVenueFromEvent($event);
            }
        } else {
            $unassigned[] = $formatted;
        }
    }

    $venues = array_values($venuesMap);
    usort($venues, static fn(array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

    foreach ($eventsByVenue as &$list) {
        usort($list, static fn(array $a, array $b): int => strcmp((string) $a['sortDate'], (string) $b['sortDate']));
    }
    unset($list);

    usort($unassigned, static fn(array $a, array $b): int => strcmp((string) $a['sortDate'], (string) $b['sortDate']));

    $unassignedVenue = null;
    if ($unassigned !== []) {
        $unassignedVenue = [
            'id'               => 0,
            'name'             => 'Other / not linked to a venue',
            'address'          => null,
            'venueType'        => 'other',
            'venueTypeLabel'   => 'Other',
        ];
        $eventsByVenue['0'] = $unassigned;
        $venues[]           = $unassignedVenue;
    }

    return [
        'form' => [
            'slug'             => (string) ($form['slug'] ?? $formSlug),
            'staffRole'        => $staffRole,
            'selectionMode'    => (string) ($form['selection_mode'] ?? 'venue_first'),
            'allowedWorkTypes' => $workTypes,
            'workTypeLabels'   => array_map(
                static fn(string $type): string => formatWorkTypeLabel($type),
                $workTypes
            ),
        ],
        'venues'          => $venues,
        'eventsByVenue'   => $eventsByVenue,
        'unassignedVenue' => $unassignedVenue,
    ];
}

/**
 * @param string[] $workTypes
 * @return array<int, array<string, mixed>>
 */
function fetchRegistrationEvents(PDO $pdo, array $workTypes, string $staffRole): array
{
    if ($workTypes === []) {
        return [];
    }

    $placeholders = [];
    $params       = [];

    foreach ($workTypes as $index => $type) {
        $key            = 'wt_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key]   = $type;
    }

    $sql = 'SELECT e.*, v.name AS venue_name, v.address AS venue_address, v.venue_type
            FROM events e
            LEFT JOIN venues v ON v.id = e.venue_id AND v.is_active = 1
            WHERE e.is_active = 1
              AND e.event_date >= CURDATE()
              AND e.work_type IN (' . implode(', ', $placeholders) . ')
            ORDER BY COALESCE(v.name, e.location, e.name) ASC, e.event_date ASC, e.name ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return array_values(array_filter(
        $rows,
        static fn(array $row): bool => eventAcceptsStaffRole($row, $staffRole)
            && isEventOpenForRegistration($row)
    ));
}

/**
 * @param array<string, mixed> $event
 * @return array<string, mixed>
 */
function formatRegistrationEvent(array $event): array
{
    $sortDate = normalizeEventDateYmd((string) ($event['event_date'] ?? ''));

    return [
        'id'                  => (int) $event['id'],
        'name'                => (string) $event['name'],
        'mainSecurityCompany' => formatEventMainSecurityLabel($event),
        'date'                => $sortDate !== '' ? date('d.m.Y', strtotime($sortDate)) : '',
        'sortDate'            => $sortDate,
        'openForRegistration' => true,
        'workType'            => (string) ($event['work_type'] ?? 'special_event'),
        'workTypeLabel'       => formatWorkTypeLabel((string) ($event['work_type'] ?? 'special_event')),
        'time'                => formatEventTimeRangeLabelForStaff($event),
        'location'            => formatEventLocationLabel($event),
        'venueName'           => trim((string) ($event['venue_name'] ?? $event['location'] ?? '')),
        'venueId'             => (int) ($event['venue_id'] ?? 0),
    ];
}

/**
 * @param array<string, mixed> $event
 * @return array<string, mixed>
 */
function formatRegistrationVenueFromEvent(array $event): array
{
    $venueId = (int) ($event['venue_id'] ?? 0);

    return [
        'id'             => $venueId,
        'name'           => (string) ($event['venue_name'] ?? $event['location'] ?? 'Venue'),
        'address'        => trim((string) ($event['venue_address'] ?? '')) ?: null,
        'venueType'      => (string) ($event['venue_type'] ?? 'other'),
        'venueTypeLabel' => formatVenueTypeLabel((string) ($event['venue_type'] ?? 'other')),
    ];
}

/**
 * @param int[] $eventIds
 * @return int[] ineligible event ids
 */
function getIneligibleEventIdsForForm(PDO $pdo, array $eventIds, array $form): array
{
    if ($eventIds === []) {
        return [];
    }

    ensureVenuesSchema($pdo);

    $workTypes = getFormAllowedWorkTypes($form);
    $staffRole = normalizeStaffRole((string) ($form['staff_role'] ?? ''));

    $invalid = [];

    foreach ($eventIds as $eventId) {
        $event = getEventById($pdo, $eventId);
        if ($event === null || (int) ($event['is_active'] ?? 0) !== 1) {
            $invalid[] = $eventId;
            continue;
        }

        if (!isEventOpenForRegistration($event)) {
            $invalid[] = $eventId;
            continue;
        }

        $workType = (string) ($event['work_type'] ?? 'special_event');
        if (!in_array($workType, $workTypes, true)) {
            $invalid[] = $eventId;
            continue;
        }

        if (!eventAcceptsStaffRole($event, $staffRole)) {
            $invalid[] = $eventId;
        }
    }

    return $invalid;
}
