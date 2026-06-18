<?php

declare(strict_types=1);

require_once __DIR__ . '/production-readiness.php';

function ensureJobRecordSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    if (!tableExists($pdo, 'saved_job_records')) {
        $sql = file_get_contents(dirname(__DIR__) . '/database/migrate-phase45-job-records.sql');
        if (is_string($sql) && trim($sql) !== '') {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                error_log('[EventStaff] ensureJobRecordSchema create: ' . $e->getMessage());
            }
        }
    }

    jobRecordEnsureColumns($pdo);

    $ready = tableExists($pdo, 'saved_job_records')
        && tableExists($pdo, 'personal_invoice_lines');
}

function jobRecordEnsureColumns(PDO $pdo): void
{
    if (!tableExists($pdo, 'saved_job_records')) {
        return;
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM saved_job_records')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $e) {
        return;
    }

    if (!in_array('invoice_type', $cols, true)) {
        try {
            $pdo->exec(
                "ALTER TABLE saved_job_records
                 ADD COLUMN invoice_type ENUM('staff_commission', 'personal') NOT NULL DEFAULT 'staff_commission' AFTER id"
            );
        } catch (PDOException $e) {
            error_log('[EventStaff] jobRecordEnsureColumns invoice_type: ' . $e->getMessage());
        }
    }

    if (!in_array('record_kind', $cols, true)) {
        try {
            $pdo->exec(
                "ALTER TABLE saved_job_records
                 ADD COLUMN record_kind ENUM('invoice', 'work_log') NOT NULL DEFAULT 'invoice' AFTER invoice_type"
            );
        } catch (PDOException $e) {
            error_log('[EventStaff] jobRecordEnsureColumns record_kind: ' . $e->getMessage());
        }
    }

    if (!in_array('invoiced_via_id', $cols, true)) {
        try {
            $pdo->exec(
                'ALTER TABLE saved_job_records
                 ADD COLUMN invoiced_via_id INT UNSIGNED NULL AFTER record_kind'
            );
        } catch (PDOException $e) {
            error_log('[EventStaff] jobRecordEnsureColumns invoiced_via_id: ' . $e->getMessage());
        }
    }

    if (!tableExists($pdo, 'personal_invoice_lines')) {
        $sql = file_get_contents(dirname(__DIR__) . '/database/migrate-phase47-personal-invoice-lines.sql');
        if (is_string($sql) && trim($sql) !== '') {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                error_log('[EventStaff] ensurePersonalInvoiceLines table: ' . $e->getMessage());
            }
        }
    }
}

function normalizePersonalRecordKind(string $kind): string
{
    return $kind === 'work_log' ? 'work_log' : 'invoice';
}

/**
 * @param array<string, mixed> $record
 */
function isPersonalWorkLog(array $record): bool
{
    return isPersonalJobRecord($record)
        && normalizePersonalRecordKind((string) ($record['record_kind'] ?? '')) === 'work_log';
}

/**
 * @param array<string, mixed> $record
 */
function isPersonalInvoiceRecord(array $record): bool
{
    return isPersonalJobRecord($record)
        && normalizePersonalRecordKind((string) ($record['record_kind'] ?? '')) === 'invoice';
}

function normalizeJobRecordInvoiceType(string $type): string
{
    return $type === 'personal' ? 'personal' : 'staff_commission';
}

/**
 * @param array<string, mixed> $record
 */
function isPersonalJobRecord(array $record): bool
{
    return normalizeJobRecordInvoiceType((string) ($record['invoice_type'] ?? '')) === 'personal';
}
