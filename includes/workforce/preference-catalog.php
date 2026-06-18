<?php

declare(strict_types=1);

/**
 * Canonical preference option slugs for Phase 1 (admin + mobile config).
 *
 * @return array{shift_types: list<array{slug: string, label: string}>, roles: list<array{slug: string, label: string}>, availability_hours: list<array{slug: string, label: string}>, availability_days: list<array{slug: string, label: string}>}
 */
function preferenceCatalogOptions(): array
{
    return [
        'shift_types' => [
            ['slug' => 'day_shift', 'label' => 'Day Shift'],
            ['slug' => 'night_shift', 'label' => 'Night Shift'],
            ['slug' => 'event_security', 'label' => 'Event Security'],
            ['slug' => 'static_security', 'label' => 'Static Security'],
            ['slug' => 'retail_security', 'label' => 'Retail Security'],
            ['slug' => 'corporate_security', 'label' => 'Corporate Security'],
            ['slug' => 'concert_security', 'label' => 'Concert Security'],
            ['slug' => 'festival_security', 'label' => 'Festival Security'],
            ['slug' => 'stewarding', 'label' => 'Stewarding'],
            ['slug' => 'door_security', 'label' => 'Door Security'],
            ['slug' => 'emergency_cover', 'label' => 'Emergency Cover'],
            ['slug' => 'relief_staff', 'label' => 'Relief Staff'],
        ],
        'roles' => [
            ['slug' => 'security_officer', 'label' => 'Security Officer'],
            ['slug' => 'event_security', 'label' => 'Event Security'],
            ['slug' => 'steward', 'label' => 'Steward'],
            ['slug' => 'supervisor', 'label' => 'Supervisor'],
            ['slug' => 'team_leader', 'label' => 'Team Leader'],
            ['slug' => 'control_room_operator', 'label' => 'Control Room Operator'],
            ['slug' => 'reception_security', 'label' => 'Reception Security'],
            ['slug' => 'mobile_patrol', 'label' => 'Mobile Patrol'],
        ],
        'availability_hours' => [
            ['slug' => 'mornings', 'label' => 'Mornings'],
            ['slug' => 'afternoons', 'label' => 'Afternoons'],
            ['slug' => 'evenings', 'label' => 'Evenings'],
            ['slug' => 'nights', 'label' => 'Nights'],
            ['slug' => 'any_time', 'label' => 'Any Time'],
        ],
        'availability_days' => [
            ['slug' => 'monday', 'label' => 'Monday'],
            ['slug' => 'tuesday', 'label' => 'Tuesday'],
            ['slug' => 'wednesday', 'label' => 'Wednesday'],
            ['slug' => 'thursday', 'label' => 'Thursday'],
            ['slug' => 'friday', 'label' => 'Friday'],
            ['slug' => 'saturday', 'label' => 'Saturday'],
            ['slug' => 'sunday', 'label' => 'Sunday'],
        ],
    ];
}

/**
 * @return list<string>
 */
function preferenceCatalogSlugs(string $group): array
{
    $catalog = preferenceCatalogOptions();
    if (!isset($catalog[$group])) {
        return [];
    }

    return array_map(static fn (array $row): string => (string) $row['slug'], $catalog[$group]);
}

/**
 * @return list<string>
 */
function preferenceCertificationTypes(): array
{
    return [
        'psa_licence',
        'safe_pass',
        'manual_handling',
        'first_aid',
        'driving_licence',
        'additional',
    ];
}

/**
 * @param mixed $value
 * @return list<string>
 */
function preferenceNormalizeSlugList($value, array $allowed): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value   = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $value)));
    }
    if (!is_array($value)) {
        return [];
    }

    $allowedMap = array_fill_keys($allowed, true);
    $out        = [];
    foreach ($value as $item) {
        $slug = strtolower(trim((string) $item));
        if ($slug !== '' && isset($allowedMap[$slug]) && !in_array($slug, $out, true)) {
            $out[] = $slug;
        }
    }

    return $out;
}

/**
 * @param mixed $value
 * @return list<string>
 */
function preferenceNormalizeLocationSlugList(PDO $pdo, $value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value   = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $value)));
    }
    if (!is_array($value)) {
        return [];
    }

    $active = preferenceLocationActiveSlugs($pdo);
    $out    = [];
    foreach ($value as $item) {
        $slug = strtolower(trim((string) $item));
        if ($slug !== '' && in_array($slug, $active, true) && !in_array($slug, $out, true)) {
            $out[] = $slug;
        }
    }

    return $out;
}

/**
 * @return list<string>
 */
function preferenceLocationActiveSlugs(PDO $pdo): array
{
    ensureStaffPreferencesFoundationSchema($pdo);
    if (!staffPreferencesTableExists($pdo, 'preference_locations')) {
        return [];
    }

    try {
        $rows = $pdo->query(
            "SELECT slug FROM preference_locations WHERE is_active = 1 ORDER BY sort_order ASC, label ASC"
        )->fetchAll(PDO::FETCH_COLUMN);

        return is_array($rows) ? array_map('strval', $rows) : [];
    } catch (Throwable $e) {
        return [];
    }
}
