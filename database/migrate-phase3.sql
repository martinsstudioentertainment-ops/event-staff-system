-- Phase 3 migration — run if database already exists without admin_users
USE event_staff_system;

CREATE TABLE IF NOT EXISTS admin_users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(100) NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO admin_users (username, password_hash, full_name) VALUES
('admin', '$2y$10$7inqh046kIXZ8WQzLgFXP.nPEkHj9/rUKaiOWqCok85SzAs49Ek/u', 'System Admin');
