<?php

require_once __DIR__ . '/work-types-schema.php';

function slugifyWorkTypeName(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
    $slug = trim($slug, '_');

    return $slug !== '' ? $slug : 'work_type';
}

function uniqueWorkTypeSlug(PDO $pdo, string $name, ?int $excludeId = null): string
{
    ensureWorkTypesSchema($pdo);

    $base = slugifyWorkTypeName($name);
    $slug = $base;
    $n    = 2;

    while (workTypeSlugExists($pdo, $slug, $excludeId)) {
        $slug = $base . '_' . $n;
        ++$n;
    }

    return $slug;
}

function workTypeSlugExists(PDO $pdo, string $slug, ?int $excludeId = null): bool
{
    ensureWorkTypesSchema($pdo);

    $sql = 'SELECT id FROM work_types WHERE slug = :slug';
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
 * @return array<string, string> slug => display name (active types only)
 */
function getWorkTypeOptions(?PDO $pdo = null): array
{
    if ($pdo === null) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            return getLegacyWorkTypeOptions();
        }
    }

    ensureWorkTypesSchema($pdo);

    $stmt = $pdo->query(
        'SELECT slug, name FROM work_types WHERE is_active = 1 ORDER BY sort_order ASC, name ASC'
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($rows === []) {
        return getLegacyWorkTypeOptions();
    }

    $options = [];
    foreach ($rows as $row) {
        $options[(string) $row['slug']] = (string) $row['name'];
    }

    return $options;
}

/**
 * Active types plus one extra slug (e.g. event already using a deactivated type).
 *
 * @return array<string, string>
 */
function getWorkTypeOptionsForSelect(PDO $pdo, ?string $includeSlug = null): array
{
    $options = getWorkTypeOptions($pdo);

    if ($includeSlug !== null && $includeSlug !== '' && !isset($options[$includeSlug])) {
        $row = getWorkTypeBySlug($pdo, $includeSlug);
        if ($row !== null) {
            $options[$includeSlug] = (string) $row['name'];
        }
    }

    return $options;
}

/**
 * All types for registration form checkboxes (active + any slug saved on the form).
 *
 * @param string[] $includeSlugs
 * @return array<string, string>
 */
function getWorkTypeOptionsForRegistrationForms(PDO $pdo, array $includeSlugs = []): array
{
    ensureWorkTypesSchema($pdo);

    $stmt = $pdo->query(
        'SELECT slug, name, is_active FROM work_types ORDER BY sort_order ASC, name ASC'
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($rows === []) {
        return getLegacyWorkTypeOptions();
    }

    $options = [];
    foreach ($rows as $row) {
        $slug = (string) $row['slug'];
        if ((int) ($row['is_active'] ?? 0) === 1 || in_array($slug, $includeSlugs, true)) {
            $options[$slug] = (string) $row['name'];
        }
    }

    foreach ($includeSlugs as $slug) {
        if ($slug !== '' && !isset($options[$slug])) {
            $row = getWorkTypeBySlug($pdo, $slug);
            if ($row !== null) {
                $options[$slug] = (string) $row['name'];
            }
        }
    }

    return $options;
}

function formatWorkTypeLabel(string $workType, ?PDO $pdo = null): string
{
    $workType = trim($workType);
    if ($workType === '') {
        return '';
    }

    if ($pdo === null) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            $legacy = getLegacyWorkTypeOptions();

            return $legacy[$workType] ?? ucfirst(str_replace('_', ' ', $workType));
        }
    }

    $row = getWorkTypeBySlug($pdo, $workType);
    if ($row !== null) {
        return (string) $row['name'];
    }

    $legacy = getLegacyWorkTypeOptions();

    return $legacy[$workType] ?? ucfirst(str_replace('_', ' ', $workType));
}

function isValidWorkTypeSlug(PDO $pdo, string $slug): bool
{
    if ($slug === '') {
        return false;
    }

    ensureWorkTypesSchema($pdo);

    $stmt = $pdo->prepare('SELECT 1 FROM work_types WHERE slug = :slug LIMIT 1');
    $stmt->execute(['slug' => $slug]);

    return (bool) $stmt->fetchColumn();
}

/**
 * @return array<int, array<string, mixed>>
 */
function getAllWorkTypes(PDO $pdo): array
{
    ensureWorkTypesSchema($pdo);

    $sql = 'SELECT wt.*, COUNT(e.id) AS event_count
            FROM work_types wt
            LEFT JOIN events e ON e.work_type = wt.slug
            GROUP BY wt.id
            ORDER BY wt.sort_order ASC, wt.name ASC';

    return $pdo->query($sql)->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function getWorkTypeById(PDO $pdo, int $id): ?array
{
    ensureWorkTypesSchema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM work_types WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * @return array<string, mixed>|null
 */
function getWorkTypeBySlug(PDO $pdo, string $slug): ?array
{
    ensureWorkTypesSchema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM work_types WHERE slug = :slug LIMIT 1');
    $stmt->execute(['slug' => trim($slug)]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, string>
 */
function validateWorkTypeData(array $data, ?int $excludeId = null): array
{
    $errors = [];
    $name   = trim((string) ($data['name'] ?? ''));

    if ($name === '') {
        $errors['name'] = 'Work type name is required.';
    } elseif (strlen($name) > 150) {
        $errors['name'] = 'Name is too long.';
    }

    $description = trim((string) ($data['description'] ?? ''));
    if (strlen($description) > 255) {
        $errors['description'] = 'Description is too long.';
    }

    $sortRaw = trim((string) ($data['sort_order'] ?? '0'));
    if ($sortRaw !== '' && (!ctype_digit($sortRaw) || (int) $sortRaw > 9999)) {
        $errors['sort_order'] = 'Sort order must be a number from 0 to 9999.';
    }

    return $errors;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function normalizeWorkTypePayload(array $data, PDO $pdo, ?int $excludeId = null): array
{
    $name = trim((string) ($data['name'] ?? ''));

    if ($excludeId !== null && $excludeId > 0) {
        $existing = getWorkTypeById($pdo, $excludeId);
        $slug     = $existing ? (string) $existing['slug'] : uniqueWorkTypeSlug($pdo, $name, $excludeId);
    } else {
        $slug = uniqueWorkTypeSlug($pdo, $name, null);
    }

    $sortRaw = trim((string) ($data['sort_order'] ?? '0'));

    return [
        'name'        => $name,
        'slug'        => $slug,
        'description' => trim((string) ($data['description'] ?? '')) ?: null,
        'sort_order'  => $sortRaw !== '' ? (int) $sortRaw : 0,
        'is_active'   => !empty($data['is_active']) ? 1 : 0,
    ];
}

/**
 * @param array<string, mixed> $data
 */
function createWorkType(PDO $pdo, array $data): int
{
    ensureWorkTypesSchema($pdo);
    $payload = normalizeWorkTypePayload($data, $pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO work_types (name, slug, description, sort_order, is_active)
         VALUES (:name, :slug, :description, :sort_order, :is_active)'
    );
    $stmt->execute($payload);

    return (int) $pdo->lastInsertId();
}

/**
 * @param array<string, mixed> $data
 */
function updateWorkType(PDO $pdo, int $id, array $data): bool
{
    ensureWorkTypesSchema($pdo);
    $payload = normalizeWorkTypePayload($data, $pdo, $id);
    $payload['id'] = $id;

    $stmt = $pdo->prepare(
        'UPDATE work_types
         SET name = :name, slug = :slug, description = :description, sort_order = :sort_order, is_active = :is_active
         WHERE id = :id'
    );
    $stmt->execute($payload);

    return $stmt->rowCount() > 0;
}

function setWorkTypeActive(PDO $pdo, int $id, bool $active): bool
{
    ensureWorkTypesSchema($pdo);

    $stmt = $pdo->prepare('UPDATE work_types SET is_active = :active WHERE id = :id');
    $stmt->execute(['active' => $active ? 1 : 0, 'id' => $id]);

    return $stmt->rowCount() > 0;
}
