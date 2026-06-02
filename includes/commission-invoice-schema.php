<?php

/** Ensure commission invoice tables exist (local dev / missed migration). */
function ensureCommissionInvoiceSchema(PDO $pdo): void
{
    static $ready = [];

    $key = spl_object_id($pdo);
    if (!empty($ready[$key])) {
        return;
    }

    if (!commissionInvoiceTablesExist($pdo)) {
        commissionInvoiceCreateTables($pdo);
    }

    commissionInvoiceEnsureColumns($pdo);

    $ready[$key] = true;
}

function commissionInvoiceTablesExist(PDO $pdo): bool
{
    try {
        $needed = ['commission_invoices', 'commission_invoice_lines'];
        foreach ($needed as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
            if ($stmt === false || $stmt->fetchColumn() === false) {
                return false;
            }
        }

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function commissionInvoiceCreateTables(PDO $pdo): void
{
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('DROP TABLE IF EXISTS commission_invoice_lines');
        $pdo->exec('DROP TABLE IF EXISTS commission_invoices');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable $e) {
        // Continue — tables may not exist yet.
    }

    $pdo->exec(
        "CREATE TABLE commission_invoices (
            id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_id            INT UNSIGNED NOT NULL,
            client_name         VARCHAR(255) NULL,
            invoice_number      VARCHAR(50) NULL,
            invoice_date        DATE NOT NULL,
            status              ENUM('draft','sent','paid','void') NOT NULL DEFAULT 'draft',
            currency            CHAR(3) NOT NULL DEFAULT 'EUR',
            staff_count         INT UNSIGNED NOT NULL DEFAULT 0,
            total_hours_worked  DECIMAL(8,2) NOT NULL DEFAULT 0,
            total_hours_billed  DECIMAL(8,2) NOT NULL DEFAULT 0,
            subtotal_amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_amount        DECIMAL(12,2) NOT NULL DEFAULT 0,
            print_layout        ENUM('detailed','summary') NOT NULL DEFAULT 'detailed',
            notes               TEXT NULL,
            created_by          INT UNSIGNED NULL,
            created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at          TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_commission_invoice_event (event_id),
            CONSTRAINT fk_commission_invoice_event
                FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
            CONSTRAINT fk_commission_invoice_admin
                FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE commission_invoice_lines (
            id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id          INT UNSIGNED NOT NULL,
            attendance_id       INT UNSIGNED NULL,
            registration_id     INT UNSIGNED NULL,
            staff_name          VARCHAR(255) NOT NULL,
            staff_role          VARCHAR(50) NULL,
            hours_worked        DECIMAL(6,2) NOT NULL DEFAULT 0,
            hours_billed        DECIMAL(6,2) NOT NULL DEFAULT 0,
            hourly_rate         DECIMAL(8,2) NOT NULL DEFAULT 0,
            line_amount         DECIMAL(10,2) NOT NULL DEFAULT 0,
            amount_override     TINYINT(1) NOT NULL DEFAULT 0,
            note                VARCHAR(255) NULL,
            sort_order          INT NOT NULL DEFAULT 0,
            CONSTRAINT fk_commission_line_invoice
                FOREIGN KEY (invoice_id) REFERENCES commission_invoices(id) ON DELETE CASCADE,
            CONSTRAINT fk_commission_line_attendance
                FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE SET NULL,
            CONSTRAINT fk_commission_line_registration
                FOREIGN KEY (registration_id) REFERENCES staff_registrations(id) ON DELETE RESTRICT,
            INDEX idx_commission_line_invoice (invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function commissionInvoiceEnsureColumns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $invoiceCols = $pdo->query('SHOW COLUMNS FROM commission_invoices')->fetchAll(PDO::FETCH_COLUMN);
        if ($invoiceCols !== [] && !in_array('print_layout', $invoiceCols, true)) {
            $pdo->exec(
                "ALTER TABLE commission_invoices
                 ADD COLUMN print_layout ENUM('detailed','summary') NOT NULL DEFAULT 'detailed' AFTER total_amount"
            );
        }

        $lineCols = $pdo->query('SHOW COLUMNS FROM commission_invoice_lines')->fetchAll(PDO::FETCH_ASSOC);
        if ($lineCols === []) {
            return;
        }

        $regCol = null;
        foreach ($lineCols as $col) {
            if (($col['Field'] ?? '') === 'registration_id') {
                $regCol = $col;
                break;
            }
        }

        if ($regCol !== null && strtoupper((string) ($regCol['Null'] ?? '')) === 'NO') {
            try {
                $pdo->exec('ALTER TABLE commission_invoice_lines DROP FOREIGN KEY fk_commission_line_registration');
            } catch (Throwable $e) {
                // FK may already be absent or named differently.
            }

            $pdo->exec('ALTER TABLE commission_invoice_lines MODIFY registration_id INT UNSIGNED NULL');

            try {
                $pdo->exec(
                    'ALTER TABLE commission_invoice_lines
                     ADD CONSTRAINT fk_commission_line_registration
                     FOREIGN KEY (registration_id) REFERENCES staff_registrations(id) ON DELETE RESTRICT'
                );
            } catch (Throwable $e) {
                // Ignore if constraint already exists.
            }
        }
    } catch (Throwable $e) {
        // Non-fatal — page may still work on fresh installs.
    }
}
