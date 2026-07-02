CREATE TABLE IF NOT EXISTS staff_messages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id        INT UNSIGNED NOT NULL,
    staff_email     VARCHAR(150) NOT NULL,
    direction       ENUM('staff_to_admin', 'admin_to_staff') NOT NULL,
    body            TEXT NOT NULL,
    admin_user_id   INT UNSIGNED NULL DEFAULT NULL,
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_staff_thread (staff_id, created_at),
    INDEX idx_staff_email (staff_email, created_at),
    INDEX idx_admin_unread (direction, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
