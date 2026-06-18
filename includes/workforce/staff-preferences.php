<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-preferences-schema.php';
require_once __DIR__ . '/preference-catalog.php';
require_once __DIR__ . '/preference-locations.php';

/**
 * @return array<string, list<string>>
 */
function staffPreferencesEmptyPayload(): array
{
    return [
        'preferred_shift_types' => [],
        'preferred_locations'   => [],
        'preferred_roles'       => [],
        'availability_days'     => [],
        'availability_hours'    => [],
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, list<string>>
 */
function staffPreferencesRowToPayload(array $row): array
{
    return [
        'preferred_shift_types' => staffPreferencesDecodeJsonList($row['preferred_shift_types'] ?? '[]'),
        'preferred_locations'   => staffPreferencesDecodeJsonList($row['preferred_locations'] ?? '[]'),
        'preferred_roles'       => staffPreferencesDecodeJsonList($row['preferred_roles'] ?? '[]'),
        'availability_days'     => staffPreferencesDecodeJsonList($row['availability_days'] ?? '[]'),
        'availability_hours'    => staffPreferencesDecodeJsonList($row['availability_hours'] ?? '[]'),
    ];
}

/**
 * @return list<string>
 */
function staffPreferencesDecodeJsonList($json): array
{
    if (is_array($json)) {
        return array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $json)));
    }
    $decoded = json_decode((string) $json, true);

    return is_array($decoded)
        ? array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $decoded)))
        : [];
}

/**
 * @return array<string, mixed>|null
 */
function staffPreferencesGetByStaffId(PDO $pdo, int $staffId): ?array
{
    ensureStaffPreferencesFoundationSchema($pdo);
    if ($staffId < 1 || !staffPreferencesTableExists($pdo, 'staff_preferences')) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM staff_preferences WHERE staff_id = ? LIMIT 1');
    $stmt->execute([$staffId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @param array<string, mixed> $input
 * @return array{ok: bool, error?: string, preferences?: array<string, list<string>>}
 */
function staffPreferencesNormalizeInput(PDO $pdo, array $input): array
{
    $catalog = preferenceCatalogOptions();

    $payload = [
        'preferred_shift_types' => preferenceNormalizeSlugList(
            $input['preferred_shift_types'] ?? [],
            preferenceCatalogSlugs('shift_types')
        ),
        'preferred_roles' => preferenceNormalizeSlugList(
            $input['preferred_roles'] ?? [],
            preferenceCatalogSlugs('roles')
        ),
        'availability_days' => preferenceNormalizeSlugList(
            $input['availability_days'] ?? [],
            preferenceCatalogSlugs('availability_days')
        ),
        'availability_hours' => preferenceNormalizeSlugList(
            $input['availability_hours'] ?? [],
            preferenceCatalogSlugs('availability_hours')
        ),
        'preferred_locations' => preferenceNormalizeLocationSlugList(
            $pdo,
            $input['preferred_locations'] ?? []
        ),
    ];

    return ['ok' => true, 'preferences' => $payload];
}

/**
 * @param array<string, mixed> $input
 * @return array{ok: bool, error?: string, preferences?: array<string, list<string>>}
 */
function staffPreferencesSave(PDO $pdo, int $staffId, array $input): array
{
    ensureStaffPreferencesFoundationSchema($pdo);
    if ($staffId < 1) {
        return ['ok' => false, 'error' => 'Invalid staff member.'];
    }

    $normalized = staffPreferencesNormalizeInput($pdo, $input);
    if (!($normalized['ok'] ?? false)) {
        return $normalized;
    }

    $prefs = $normalized['preferences'] ?? staffPreferencesEmptyPayload();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO staff_preferences
                (staff_id, preferred_shift_types, preferred_locations, preferred_roles, availability_days, availability_hours)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                preferred_shift_types = VALUES(preferred_shift_types),
                preferred_locations = VALUES(preferred_locations),
                preferred_roles = VALUES(preferred_roles),
                availability_days = VALUES(availability_days),
                availability_hours = VALUES(availability_hours),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            $staffId,
            json_encode($prefs['preferred_shift_types'], JSON_UNESCAPED_UNICODE),
            json_encode($prefs['preferred_locations'], JSON_UNESCAPED_UNICODE),
            json_encode($prefs['preferred_roles'], JSON_UNESCAPED_UNICODE),
            json_encode($prefs['availability_days'], JSON_UNESCAPED_UNICODE),
            json_encode($prefs['availability_hours'], JSON_UNESCAPED_UNICODE),
        ]);

        return ['ok' => true, 'preferences' => $prefs];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save preferences.'];
    }
}

/**
 * Parse optional preference fields from registration POST (backward compatible).
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function staffPreferencesParseRegistrationInput(array $data): array
{
    if (!empty($data['staff_preferences_json'])) {
        $decoded = json_decode((string) $data['staff_preferences_json'], true);

        return is_array($decoded) ? $decoded : [];
    }

    $keys = [
        'preferred_shift_types',
        'preferred_locations',
        'preferred_roles',
        'availability_days',
        'availability_hours',
    ];
    $out = [];
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            $out[$key] = $data[$key];
        }
    }

    return $out;
}

/**
 * Save preferences from registration when optional fields are present.
 */
function staffPreferencesSaveFromRegistrationIfPresent(PDO $pdo, array $data, ?int $staffId = null): void
{
    $parsed = staffPreferencesParseRegistrationInput($data);
    if ($parsed === []) {
        return;
    }

    if ($staffId === null || $staffId < 1) {
        require_once __DIR__ . '/../staff-repository.php';
        $email = normalizeRegistrationEmail((string) ($data['email'] ?? ''));
        if ($email === '') {
            return;
        }
        $staffId = ensureStaffRecordForEmail($pdo, $email);
    }

    if ($staffId === null || $staffId < 1) {
        return;
    }

    try {
        staffPreferencesSave($pdo, (int) $staffId, $parsed);
    } catch (Throwable $e) {
        error_log('[EventStaff] staff preferences registration save: ' . $e->getMessage());
    }
}

/**
 * @return list<array<string, mixed>>
 */
function staffPreferencesAdminList(PDO $pdo, array $filters = []): array
{
    ensureStaffPreferencesFoundationSchema($pdo);
    if (!staffPreferencesTableExists($pdo, 'staff_preferences')) {
        return [];
    }

    $sql = "SELECT sp.*, s.first_name, s.surname, s.email, s.mobile, s.staff_role
            FROM staff_preferences sp
            INNER JOIN staff s ON s.id = sp.staff_id
            WHERE 1=1";
    $params = [];

    if (($filters['shift_type'] ?? '') !== '') {
        $sql .= ' AND JSON_CONTAINS(sp.preferred_shift_types, JSON_QUOTE(?))';
        $params[] = (string) $filters['shift_type'];
    }
    if (($filters['location'] ?? '') !== '') {
        $sql .= ' AND JSON_CONTAINS(sp.preferred_locations, JSON_QUOTE(?))';
        $params[] = (string) $filters['location'];
    }
    if (($filters['role'] ?? '') !== '') {
        $sql .= ' AND JSON_CONTAINS(sp.preferred_roles, JSON_QUOTE(?))';
        $params[] = (string) $filters['role'];
    }
    if (($filters['availability_day'] ?? '') !== '') {
        $sql .= ' AND JSON_CONTAINS(sp.availability_days, JSON_QUOTE(?))';
        $params[] = (string) $filters['availability_day'];
    }
    if (($filters['cert_type'] ?? '') !== '') {
        $sql .= ' AND EXISTS (
            SELECT 1 FROM staff_certifications sc
            WHERE sc.staff_id = sp.staff_id AND sc.cert_type = ?
        )';
        $params[] = (string) $filters['cert_type'];
    }
    if (($filters['q'] ?? '') !== '') {
        $sql .= ' AND (s.email LIKE ? OR s.first_name LIKE ? OR s.surname LIKE ? OR s.mobile LIKE ?)';
        $q = '%' . (string) $filters['q'] . '%';
        array_push($params, $q, $q, $q, $q);
    }

    $sql .= ' ORDER BY sp.updated_at DESC, s.surname ASC, s.first_name ASC LIMIT 500';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}
