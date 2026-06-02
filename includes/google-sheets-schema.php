<?php

/** Ensure Google Sheets columns on events exist. */
function ensureGoogleSheetsSchema(PDO $pdo): void
{
    static $ready = [];

    $key = spl_object_id($pdo);
    if (!empty($ready[$key])) {
        return;
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM events')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }

    $adds = [
        'google_sheet_url' => 'VARCHAR(512) NULL DEFAULT NULL',
        'google_sheet_tab' => 'VARCHAR(100) NULL DEFAULT NULL',
    ];

    foreach ($adds as $column => $definition) {
        if (in_array($column, $cols, true)) {
            continue;
        }

        try {
            $pdo->exec("ALTER TABLE events ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            // Ignore race.
        }
    }

    $ready[$key] = true;
}

function getGoogleServiceAccountPath(): string
{
    return dirname(__DIR__) . '/storage/google/service-account.json';
}

function ensureGoogleStorageDirectory(): string
{
    $dir = dirname(__DIR__) . '/storage/google';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return $dir;
}
