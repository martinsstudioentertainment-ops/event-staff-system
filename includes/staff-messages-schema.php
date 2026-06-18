<?php

declare(strict_types=1);

require_once __DIR__ . '/production-readiness.php';

function ensureStaffMessagesSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    if (!tableExists($pdo, 'staff_messages')) {
        $sql = file_get_contents(dirname(__DIR__) . '/database/migrate-phase44-staff-messages.sql');
        if (is_string($sql) && trim($sql) !== '') {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                error_log('[EventStaff] ensureStaffMessagesSchema: ' . $e->getMessage());
            }
        }
    }

    if (tableExists($pdo, 'staff_messages')) {
        ensureStaffMessagesExtendedColumns($pdo);
        $ready = true;
    }
}

function ensureStaffMessagesExtendedColumns(PDO $pdo): void
{
    static $columnsReady = false;
    if ($columnsReady || !tableExists($pdo, 'staff_messages')) {
        return;
    }

    $additions = [
        'subject'         => 'VARCHAR(255) NULL DEFAULT NULL',
        'delivery_status' => "VARCHAR(20) NULL DEFAULT NULL COMMENT 'received|sent|failed|internal'",
        'recipient_email' => 'VARCHAR(150) NULL DEFAULT NULL',
    ];

    foreach ($additions as $column => $definition) {
        if (columnExists($pdo, 'staff_messages', $column)) {
            continue;
        }

        try {
            $pdo->exec("ALTER TABLE staff_messages ADD COLUMN {$column} {$definition}");
        } catch (PDOException $e) {
            error_log('[EventStaff] ensureStaffMessagesExtendedColumns ' . $column . ': ' . $e->getMessage());
        }
    }

    $columnsReady = true;
}
