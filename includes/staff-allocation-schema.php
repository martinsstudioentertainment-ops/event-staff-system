<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-registration-schema.php';

function ensureStaffAllocationSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $migration = dirname(__DIR__) . '/database/migrate-phase68-staff-allocation.sql';
    if (is_file($migration)) {
        try {
            $pdo->exec((string) file_get_contents($migration));
        } catch (PDOException $e) {
            if (!str_contains($e->getMessage(), 'already exists')) {
                error_log('[EventStaff] staff allocation schema: ' . $e->getMessage());
            }
        }
    }

    staffRegistrationInvalidateColumnCache();

    $alters = [];
    if (!staffRegistrationColumnExists($pdo, 'allocation_type')) {
        $alters[] = "ADD COLUMN allocation_type VARCHAR(32) NOT NULL DEFAULT 'standard' AFTER status";
    }
    if (!staffRegistrationColumnExists($pdo, 'admin_assigned_by')) {
        $alters[] = 'ADD COLUMN admin_assigned_by INT UNSIGNED NULL DEFAULT NULL AFTER allocation_type';
    }
    if (!staffRegistrationColumnExists($pdo, 'admin_assigned_at')) {
        $alters[] = 'ADD COLUMN admin_assigned_at DATETIME NULL DEFAULT NULL AFTER admin_assigned_by';
    }
    if (!staffRegistrationColumnExists($pdo, 'override_reason')) {
        $alters[] = 'ADD COLUMN override_reason TEXT NULL DEFAULT NULL AFTER admin_assigned_at';
    }
    if (!staffRegistrationColumnExists($pdo, 'previous_event_id')) {
        $alters[] = 'ADD COLUMN previous_event_id INT UNSIGNED NULL DEFAULT NULL AFTER override_reason';
    }

    foreach ($alters as $fragment) {
        try {
            $pdo->exec('ALTER TABLE staff_registrations ' . $fragment);
        } catch (Throwable $e) {
            error_log('[EventStaff] staff_registrations allocation columns: ' . $e->getMessage());
        }
    }

    staffRegistrationInvalidateColumnCache();
    $ready = true;
}

function staffAllocationTableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** @return list<string> */
function getWaitlistAllocationTypes(): array
{
    return ['waiting_list', 'pending_allocation', 'reserve_staff'];
}

function isWaitlistAllocationType(string $type): bool
{
    return in_array($type, getWaitlistAllocationTypes(), true);
}

function formatAllocationTypeLabel(string $type): string
{
    return match ($type) {
        'waiting_list'       => 'Waiting list',
        'pending_allocation' => 'Pending allocation',
        'reserve_staff'      => 'Reserve staff',
        'admin_assigned'     => 'Admin assigned',
        'standard'           => 'Standard',
        default              => ucwords(str_replace('_', ' ', $type)),
    };
}

function registrationCountsTowardEventCapacity(string $allocationType): bool
{
    if ($allocationType === '' || $allocationType === 'standard' || $allocationType === 'admin_assigned') {
        return true;
    }

    return !isWaitlistAllocationType($allocationType);
}
