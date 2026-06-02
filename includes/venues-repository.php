<?php

require_once __DIR__ . '/venues-schema.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/registration-forms.php';
require_once __DIR__ . '/work-types-repository.php';

/** @return array<string, string> */
function getVenueTypeOptions(): array
{
    return [
        'nightclub'     => 'Nightclub',
        'office'        => 'Office / corporate',
        'arena'         => 'Arena / stadium',
        'festival_site' => 'Festival site',
        'corporate'     => 'Corporate venue',
        'other'         => 'Other',
    ];
}

/** @return string[] */
function getStaffRoleValuesForEvents(): array
{
    return ['dsp', 'static', 'steward'];
}

function formatVenueTypeLabel(string $venueType): string
{
    $options = getVenueTypeOptions();

    return $options[$venueType] ?? ucfirst(str_replace('_', ' ', $venueType));
}

function slugifyVenueName(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'venue';
}

function uniqueVenueSlug(PDO $pdo, string $name, ?int $excludeId = null): string
{
    $base = slugifyVenueName($name);
    $slug = $base;
    $n    = 2;

    while (venueSlugExists($pdo, $slug, $excludeId)) {
        $slug = $base . '-' . $n;
        ++$n;
    }

    return $slug;
}

function venueSlugExists(PDO $pdo, string $slug, ?int $excludeId = null): bool
{
    ensureVenuesSchema($pdo);

    $sql = 'SELECT id FROM venues WHERE slug = :slug';
    if ($excludeId !== null && $excludeId > 0) {
        $sql .= ' AND id != :exclude_id';
    }
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $params = ['slug' => $slug];
    if ($excludeId !== null && $excludeId > 0) {
        $params['exclude_id'] = $excludeId;
    }
    $stmt->execute($params);

    return (bool) $stmt->fetchColumn();
}

/**
 * @return array<int, array<string, mixed>>
 */
function getAllVenues(PDO $pdo, bool $activeOnly = false): array
{
    ensureVenuesSchema($pdo);

    $sql = 'SELECT v.*, COUNT(e.id) AS event_count
            FROM venues v
            LEFT JOIN events e ON e.venue_id = v.id
            ' . ($activeOnly ? 'WHERE v.is_active = 1 ' : '') . '
            GROUP BY v.id
            ORDER BY v.name ASC';

    return $pdo->query($sql)->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function getVenueById(PDO $pdo, int $id): ?array
{
    ensureVenuesSchema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM venues WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, string>
 */
function validateVenueData(array $data): array
{
    $errors = [];
    $name   = trim((string) ($data['name'] ?? ''));

    if ($name === '') {
        $errors['name'] = 'Venue name is required.';
    } elseif (strlen($name) > 150) {
        $errors['name'] = 'Venue name is too long.';
    }

    $address = trim((string) ($data['address'] ?? ''));
    if (strlen($address) > 255) {
        $errors['address'] = 'Address is too long.';
    }

    $venueType = trim((string) ($data['venue_type'] ?? 'other'));
    if (!array_key_exists($venueType, getVenueTypeOptions())) {
        $errors['venue_type'] = 'Please select a valid venue type.';
    }

    $eircode = normalizeEircode((string) ($data['venue_eircode'] ?? ''));
    if ($eircode !== '' && !isValidEircode($eircode)) {
        $errors['venue_eircode'] = 'Please enter a valid Eircode (e.g. D02 X285).';
    }

    return $errors;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function normalizeVenuePayload(array $data, PDO $pdo, ?int $excludeId = null): array
{
    $name = trim((string) ($data['name'] ?? ''));

    return [
        'name'          => $name,
        'slug'          => uniqueVenueSlug($pdo, $name, $excludeId),
        'address'       => trim((string) ($data['address'] ?? '')) ?: null,
        'venue_type'    => trim((string) ($data['venue_type'] ?? 'other')),
        'venue_eircode' => normalizeEircode((string) ($data['venue_eircode'] ?? '')) ?: null,
        'venue_lat'     => normalizeCoordinate(isset($data['venue_lat']) ? (string) $data['venue_lat'] : null),
        'venue_lng'     => normalizeCoordinate(isset($data['venue_lng']) ? (string) $data['venue_lng'] : null),
        'is_active'     => !empty($data['is_active']) ? 1 : 0,
    ];
}

/**
 * @param array<string, mixed> $data
 */
function createVenue(PDO $pdo, array $data): int
{
    ensureVenuesSchema($pdo);
    $payload = normalizeVenuePayload($data, $pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO venues (name, slug, address, venue_type, venue_eircode, venue_lat, venue_lng, is_active)
         VALUES (:name, :slug, :address, :venue_type, :venue_eircode, :venue_lat, :venue_lng, :is_active)'
    );
    $stmt->execute($payload);

    return (int) $pdo->lastInsertId();
}

/**
 * @param array<string, mixed> $data
 */
function updateVenue(PDO $pdo, int $id, array $data): bool
{
    ensureVenuesSchema($pdo);
    $payload = normalizeVenuePayload($data, $pdo, $id);
    $payload['id'] = $id;

    $stmt = $pdo->prepare(
        'UPDATE venues
         SET name = :name, slug = :slug, address = :address, venue_type = :venue_type,
             venue_eircode = :venue_eircode, venue_lat = :venue_lat, venue_lng = :venue_lng, is_active = :is_active
         WHERE id = :id'
    );
    $stmt->execute($payload);

    $updated = $stmt->rowCount() > 0;
    if ($updated) {
        propagateVenueDetailsToLinkedEvents($pdo, $id);
    }

    return $updated;
}

/**
 * When a venue is edited, copy name / eircode / GPS to all linked events.
 */
function propagateVenueDetailsToLinkedEvents(PDO $pdo, int $venueId): void
{
    $venue = getVenueById($pdo, $venueId);
    if ($venue === null) {
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE events
         SET location = :location,
             venue_eircode = :venue_eircode,
             venue_lat = :venue_lat,
             venue_lng = :venue_lng
         WHERE venue_id = :venue_id'
    );
    $stmt->execute([
        'location'      => (string) $venue['name'],
        'venue_eircode' => $venue['venue_eircode'] ?: null,
        'venue_lat'     => $venue['venue_lat'],
        'venue_lng'     => $venue['venue_lng'],
        'venue_id'      => $venueId,
    ]);
}

function setVenueActive(PDO $pdo, int $id, bool $active): bool
{
    ensureVenuesSchema($pdo);

    $stmt = $pdo->prepare('UPDATE venues SET is_active = :active WHERE id = :id');
    $stmt->execute(['active' => $active ? 1 : 0, 'id' => $id]);

    return $stmt->rowCount() > 0;
}

/** @return string[] */
function normalizeRolesNeeded(array $data): array
{
    $raw = $data['roles_needed'] ?? [];

    if (!is_array($raw)) {
        $raw = array_filter(array_map('trim', explode(',', (string) $raw)));
    }

    $allowed = getStaffRoleValuesForEvents();
    $roles   = [];

    foreach ($raw as $role) {
        $role = normalizeStaffRole((string) $role);
        if (in_array($role, $allowed, true)) {
            $roles[] = $role;
        }
    }

    if ($roles === []) {
        return $allowed;
    }

    return array_values(array_unique($roles));
}

function rolesNeededToString(array $roles): string
{
    return implode(',', normalizeRolesNeeded(['roles_needed' => $roles]));
}

/**
 * Human-readable roles for a shift (registration UI).
 */
function formatEventRolesNeededDisplay(array $event): string
{
    $needed = normalizeRolesNeeded($event);
    $parts  = [];

    $hasDsp     = in_array('dsp', $needed, true);
    $hasStatic  = in_array('static', $needed, true);
    $hasSteward = in_array('steward', $needed, true);

    if ($hasDsp && $hasStatic) {
        $parts[] = 'DSP & Static';
    } elseif ($hasDsp) {
        $parts[] = 'DSP';
    } elseif ($hasStatic) {
        $parts[] = 'Static';
    }

    if ($hasSteward) {
        $parts[] = 'Steward';
    }

    return $parts !== [] ? implode(' · ', $parts) : 'Open';
}

function eventAcceptsStaffRole(array $event, string $staffRole): bool
{
    $staffRole = normalizeStaffRole($staffRole);
    $needed    = normalizeRolesNeeded(['roles_needed' => (string) ($event['roles_needed'] ?? '')]);

    if ($staffRole === 'both') {
        return count(array_intersect($needed, ['dsp', 'static'])) > 0;
    }

    return in_array($staffRole, $needed, true);
}
