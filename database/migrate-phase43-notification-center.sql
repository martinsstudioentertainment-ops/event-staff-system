-- In-app notification center (staff + admin)

CREATE TABLE IF NOT EXISTS app_notifications (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audience        ENUM('admin', 'staff') NOT NULL,
    staff_email     VARCHAR(150) NULL DEFAULT NULL,
    type            VARCHAR(40) NOT NULL,
    title           VARCHAR(200) NOT NULL,
    body            TEXT NOT NULL,
    action_url      VARCHAR(500) NULL DEFAULT NULL,
    action_label    VARCHAR(80) NULL DEFAULT NULL,
    related_id      INT UNSIGNED NULL DEFAULT NULL,
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_inbox (audience, is_read, created_at),
    INDEX idx_staff_inbox (staff_email, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
