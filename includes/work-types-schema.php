<?php

/** @return array<string, string> */
function getLegacyWorkTypeOptions(): array
{
    return [
        'special_event' => 'Special event / concert',
        'nightclub'     => 'Nightclub shift',
        'office'        => 'Office / corporate',
        'static'        => 'Static shift (non-event)',
        'festival'      => 'Festival / multi-day',
    ];
}

/** Ensure work_types table exists and events.work_type accepts custom slugs. */
function ensureWorkTypesSchema(PDO $pdo): void
{
    static $ready = [];

    $key = spl_object_id($pdo);
    if (!empty($ready[$key])) {
        return;
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS work_types (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                slug VARCHAR(80) NOT NULL,
                description VARCHAR(255) NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_work_types_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        // Table may already exist.
    }

    seedDefaultWorkTypesIfEmpty($pdo);

    try {
        $col = $pdo->query("SHOW COLUMNS FROM events LIKE 'work_type'")->fetch(PDO::FETCH_ASSOC);
        if ($col && stripos((string) ($col['Type'] ?? ''), 'enum') !== false) {
            $pdo->exec(
                "ALTER TABLE events MODIFY COLUMN work_type VARCHAR(80) NOT NULL DEFAULT 'special_event'"
            );
        }
    } catch (Throwable $e) {
        // Column may not exist yet (venues-schema adds ENUM first).
    }

    $ready[$key] = true;
}

function seedDefaultWorkTypesIfEmpty(PDO $pdo): void
{
    try {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM work_types')->fetchColumn();
    } catch (Throwable $e) {
        return;
    }

    if ($count > 0) {
        return;
    }

    $order = 10;
    foreach (getLegacyWorkTypeOptions() as $slug => $name) {
        $stmt = $pdo->prepare(
            'INSERT INTO work_types (name, slug, sort_order, is_active) VALUES (:name, :slug, :sort_order, 1)'
        );
        $stmt->execute([
            'name'       => $name,
            'slug'       => $slug,
            'sort_order' => $order,
        ]);
        $order += 10;
    }
}
