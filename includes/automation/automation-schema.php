<?php

declare(strict_types=1);

require_once __DIR__ . '/../production-readiness.php';

function auto_schema_tables(): array
{
    return [
        'event_roster_slots',
        'event_roster_assignments',
        'recruitment_pipeline',
        'staff_training_records',
        'staff_rate_cards',
        'comms_campaigns',
        'staff_incidents',
        'clients',
        'client_contacts',
        'staff_contracts',
        'client_contracts',
        'ops_automation_log',
    ];
}

function auto_schema_ready(PDO $pdo): bool
{
    foreach (auto_schema_tables() as $table) {
        if (!tableExists($pdo, $table)) {
            return false;
        }
    }

    return true;
}

function auto_ensure_schema(PDO $pdo): bool
{
    if (auto_schema_ready($pdo)) {
        return true;
    }

    $path = dirname(__DIR__, 2) . '/database/migrate-phase66-operations-automation.sql';
    if (!is_file($path)) {
        return false;
    }

    try {
        $sql = (string) file_get_contents($path);
        $pdo->exec($sql);

        return auto_schema_ready($pdo);
    } catch (Throwable $e) {
        error_log('[Automation] schema ensure: ' . $e->getMessage());

        return false;
    }
}

function auto_phase67_tables(): array
{
    return [
        'comms_message_templates',
        'client_notes',
        'recruitment_interview_notes',
        'staff_payroll_adjustments',
    ];
}

function auto_ensure_phase67_schema(PDO $pdo): bool
{
    foreach (auto_phase67_tables() as $table) {
        if (!tableExists($pdo, $table)) {
            $path = dirname(__DIR__, 2) . '/database/migrate-phase67-batch2-enhancements.sql';
            if (is_file($path)) {
                try {
                    $pdo->exec((string) file_get_contents($path));
                } catch (Throwable $e) {
                    error_log('[Automation] phase67 schema: ' . $e->getMessage());
                }
            }
            break;
        }
    }

    auto_ensure_incident_extensions($pdo);

    return true;
}

function auto_ensure_incident_extensions(PDO $pdo): void
{
    if (!tableExists($pdo, 'staff_incidents')) {
        return;
    }

    try {
        $pdo->exec(
            "ALTER TABLE staff_incidents MODIFY incident_type ENUM(
                'attendance','venue','conduct','gps','safety','client_complaint','other'
            ) NOT NULL DEFAULT 'other'"
        );
    } catch (Throwable $e) {
        // optional
    }

    foreach ([
        'evidence_text' => 'TEXT NULL AFTER description',
        'actions_taken' => 'TEXT NULL AFTER evidence_text',
        'risk_level'    => "ENUM('low','medium','high','critical') NULL DEFAULT 'medium' AFTER actions_taken",
    ] as $col => $def) {
        if (!columnExists($pdo, 'staff_incidents', $col)) {
            try {
                $pdo->exec("ALTER TABLE staff_incidents ADD COLUMN {$col} {$def}");
            } catch (Throwable $e) {
                error_log('[Automation] incident column ' . $col . ': ' . $e->getMessage());
            }
        }
    }

    if (tableExists($pdo, 'staff_training_records')) {
        try {
            $pdo->exec(
                "ALTER TABLE staff_training_records MODIFY record_status
                 ENUM('completed','pending','expired','upcoming','scheduled') NOT NULL DEFAULT 'pending'"
            );
        } catch (Throwable $e) {
            // optional
        }
    }
}

function auto_shift_response_available(PDO $pdo): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        $cols      = $pdo->query('SHOW COLUMNS FROM staff_registrations')->fetchAll(PDO::FETCH_COLUMN);
        $available = in_array('shift_response', $cols, true);
    } catch (Throwable $e) {
        $available = false;
    }

    return $available;
}
