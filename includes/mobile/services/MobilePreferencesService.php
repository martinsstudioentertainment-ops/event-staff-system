<?php

declare(strict_types=1);

require_once __DIR__ . '/../../workforce/staff-preferences.php';
require_once __DIR__ . '/../../workforce/preference-catalog.php';
require_once __DIR__ . '/../../workforce/preference-locations.php';

/**
 * @return array{ok: true, preferences: array<string, list<string>>, updated_at: string|null}
 */
function mobilePreferencesServiceGet(PDO $pdo, int $staffId): array
{
    $row = staffPreferencesGetByStaffId($pdo, $staffId);
    if ($row === null) {
        return [
            'ok'          => true,
            'preferences' => staffPreferencesEmptyPayload(),
            'updated_at'  => null,
        ];
    }

    return [
        'ok'          => true,
        'preferences' => staffPreferencesRowToPayload($row),
        'updated_at'  => (string) ($row['updated_at'] ?? null),
    ];
}

/**
 * @param array<string, mixed> $body
 * @return array{ok: bool, message?: string, status?: int, code?: string, preferences?: array<string, list<string>>, updated_at?: string|null}
 */
function mobilePreferencesServicePut(PDO $pdo, int $staffId, array $body): array
{
    $result = staffPreferencesSave($pdo, $staffId, $body);
    if (!($result['ok'] ?? false)) {
        return [
            'ok'      => false,
            'message' => (string) ($result['error'] ?? 'Could not save preferences.'),
            'status'  => 400,
            'code'    => 'VALIDATION_ERROR',
        ];
    }

    $row = staffPreferencesGetByStaffId($pdo, $staffId);

    return [
        'ok'          => true,
        'preferences' => $result['preferences'] ?? staffPreferencesEmptyPayload(),
        'updated_at'  => $row !== null ? (string) ($row['updated_at'] ?? null) : date('c'),
    ];
}

/**
 * @return array<string, mixed>
 */
function mobilePreferencesConfigOptions(PDO $pdo): array
{
    $catalog = preferenceCatalogOptions();

    return [
        'shift_types'         => $catalog['shift_types'],
        'roles'               => $catalog['roles'],
        'availability_hours'  => $catalog['availability_hours'],
        'availability_days'   => $catalog['availability_days'],
        'locations'           => preferenceLocationsPublicOptions($pdo),
        'certification_types' => array_map(
            static fn (string $slug): array => ['slug' => $slug, 'label' => preferenceCertificationLabel($slug)],
            preferenceCertificationTypes()
        ),
    ];
}

function preferenceCertificationLabel(string $slug): string
{
    return match ($slug) {
        'psa_licence'     => 'PSA Licence',
        'safe_pass'       => 'Safe Pass',
        'manual_handling' => 'Manual Handling',
        'first_aid'       => 'First Aid',
        'driving_licence' => 'Driving Licence',
        'additional'      => 'Additional Certification',
        default           => ucwords(str_replace('_', ' ', $slug)),
    };
}
