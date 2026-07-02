CREATE TABLE IF NOT EXISTS personal_invoice_lines (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id          INT UNSIGNED NOT NULL,
    sort_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    description         VARCHAR(500) NOT NULL,
    job_date            DATE NULL,
    venue               VARCHAR(255) NULL,
    hours               DECIMAL(8,2) NOT NULL DEFAULT 0,
    hourly_rate         DECIMAL(8,2) NOT NULL DEFAULT 0,
    line_amount         DECIMAL(12,2) NOT NULL DEFAULT 0,
    source_work_log_id  INT UNSIGNED NULL,
    INDEX idx_personal_lines_invoice (invoice_id),
    INDEX idx_personal_lines_source (source_work_log_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
