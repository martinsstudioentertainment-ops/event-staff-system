<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-preferences-schema.php';

/**
 * @return list<array<string, mixed>>
 */
function preferenceLocationsList(PDO $pdo, bool $activeOnly = false): array
{
    ensureStaffPreferencesFoundationSchema($pdo);
    if (!staffPreferencesTableExists($pdo, 'preference_locations')) {
        return [];
    }

    $sql = 'SELECT * FROM preference_locations';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, label ASC';

    try {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return array<string, mixed>|null
 */
function preferenceLocationById(PDO $pdo, int $id): ?array
{
    ensureStaffPreferencesFoundationSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM preference_locations WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @return array{ok: bool, error?: string, id?: int}
 */
function preferenceLocationSave(PDO $pdo, array $input, ?int $id = null): array
{
    ensureStaffPreferencesFoundationSchema($pdo);

    $label = trim((string) ($input['label'] ?? ''));
    if ($label === '') {
        return ['ok' => false, 'error' => 'Label is required.'];
    }

    $slug = trim((string) ($input['slug'] ?? ''));
    if ($slug === '') {
        $slug = preferenceLocationSlugify($label);
    }
    $slug = preferenceLocationSlugify($slug);
    if ($slug === '') {
        return ['ok' => false, 'error' => 'Could not generate a valid slug.'];
    }

    $sortOrder = (int) ($input['sort_order'] ?? 0);
    $isActive  = !empty($input['is_active']) ? 1 : 0;

    try {
        if ($id !== null && $id > 0) {
            $dup = $pdo->prepare('SELECT id FROM preference_locations WHERE slug = ? AND id <> ? LIMIT 1');
            $dup->execute([$slug, $id]);
            if ($dup->fetchColumn()) {
                return ['ok' => false, 'error' => 'Another location already uses this slug.'];
            }
            $stmt = $pdo->prepare(
                'UPDATE preference_locations SET slug = ?, label = ?, sort_order = ?, is_active = ? WHERE id = ?'
            );
            $stmt->execute([$slug, $label, $sortOrder, $isActive, $id]);

            return ['ok' => true, 'id' => $id];
        }

        $dup = $pdo->prepare('SELECT id FROM preference_locations WHERE slug = ? LIMIT 1');
        $dup->execute([$slug]);
        if ($dup->fetchColumn()) {
            return ['ok' => false, 'error' => 'A location with this slug already exists.'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO preference_locations (slug, label, sort_order, is_active) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$slug, $label, $sortOrder, $isActive]);

        return ['ok' => true, 'id' => (int) $pdo->lastInsertId()];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save location.'];
    }
}

function preferenceLocationSlugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    $value = trim($value, '_');

    return substr($value, 0, 64);
}

/**
 * @return array{ok: bool, error?: string}
 */
function preferenceLocationSetActive(PDO $pdo, int $id, bool $active): array
{
    ensureStaffPreferencesFoundationSchema($pdo);
    $stmt = $pdo->prepare('UPDATE preference_locations SET is_active = ? WHERE id = ?');
    $stmt->execute([$active ? 1 : 0, $id]);

    return ['ok' => $stmt->rowCount() > 0];
}

/**
 * @return list<array{slug: string, label: string}>
 */
function preferenceLocationsPublicOptions(PDO $pdo): array
{
    $rows = preferenceLocationsList($pdo, true);
    $out  = [];
    foreach ($rows as $row) {
        $out[] = [
            'slug'  => (string) $row['slug'],
            'label' => (string) $row['label'],
        ];
    }

    return $out;
}
