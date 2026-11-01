<?php

declare(strict_types=1);

/**
 * Google Form → Responses Sheet → event mapping.
 * Separate from events.google_sheet_url (outbound roster sheets).
 */
function ensureGoogleFormEventIntegrationsSchema(PDO $pdo): void
{
    static $ready = [];

    $key = spl_object_id($pdo);
    if (!empty($ready[$key])) {
        return;
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS google_form_event_integrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_id INT UNSIGNED NOT NULL,
                google_form_id VARCHAR(128) NULL DEFAULT NULL,
                google_form_url VARCHAR(512) NULL DEFAULT NULL,
                google_sheet_id VARCHAR(128) NOT NULL,
                google_sheet_url VARCHAR(512) NULL DEFAULT NULL,
                google_sheet_tab VARCHAR(120) NOT NULL DEFAULT 'Form Responses 1',
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                processed_rows INT UNSIGNED NOT NULL DEFAULT 0,
                last_sync_at DATETIME NULL DEFAULT NULL,
                last_success_at DATETIME NULL DEFAULT NULL,
                last_error VARCHAR(500) NULL DEFAULT NULL,
                last_http_code INT NULL DEFAULT NULL,
                last_total INT UNSIGNED NOT NULL DEFAULT 0,
                last_imported INT UNSIGNED NOT NULL DEFAULT 0,
                last_skipped INT UNSIGNED NOT NULL DEFAULT 0,
                last_failed INT UNSIGNED NOT NULL DEFAULT 0,
                last_permission_ok TINYINT(1) NOT NULL DEFAULT 0,
                is_locked TINYINT(1) NOT NULL DEFAULT 0,
                source VARCHAR(32) NOT NULL DEFAULT 'admin',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_gfei_sheet (google_sheet_id),
                KEY idx_gfei_event (event_id),
                KEY idx_gfei_enabled (enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('[EventStaff] google_form_event_integrations schema: ' . $e->getMessage());
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM google_form_event_integrations')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $ready[$key] = true;
        return;
    }

    $adds = [
        'form_created_by_system'  => 'TINYINT(1) NOT NULL DEFAULT 0',
        'sheet_created_by_system' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'form_created_at'         => 'DATETIME NULL DEFAULT NULL',
        'published_at'            => 'DATETIME NULL DEFAULT NULL',
        'published'               => 'TINYINT(1) NOT NULL DEFAULT 0',
        'creation_status'         => "VARCHAR(32) NOT NULL DEFAULT 'ready'",
        'creation_error'          => 'VARCHAR(500) NULL DEFAULT NULL',
        'collect_pps'             => 'TINYINT(1) NOT NULL DEFAULT 0',
        'collect_dob'             => 'TINYINT(1) NOT NULL DEFAULT 0',
    ];
    foreach ($adds as $column => $definition) {
        if (in_array($column, $cols, true)) {
            continue;
        }
        try {
            $pdo->exec("ALTER TABLE google_form_event_integrations ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            // race
        }
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS google_form_pulled_responses (
                integration_id INT UNSIGNED NOT NULL,
                response_id VARCHAR(128) NOT NULL,
                pulled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (integration_id, response_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        error_log('[EventStaff] google_form_pulled_responses schema: ' . $e->getMessage());
    }

    $ready[$key] = true;
}
