-- Website visitor location log (auto-created on first visit if missing)
CREATE TABLE IF NOT EXISTS website_visits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    site_area VARCHAR(20) NOT NULL DEFAULT 'marketing',
    request_path VARCHAR(500) NOT NULL DEFAULT '/',
    http_host VARCHAR(120) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    referrer VARCHAR(500) NULL,
    country VARCHAR(80) NULL,
    region VARCHAR(120) NULL,
    city VARCHAR(120) NULL,
    visitor_key CHAR(40) NULL,
    INDEX idx_visited_at (visited_at DESC),
    INDEX idx_site_area (site_area),
    INDEX idx_country (country),
    INDEX idx_visitor_key (visitor_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ip_geo_cache (
    ip_address VARCHAR(45) NOT NULL PRIMARY KEY,
    country VARCHAR(80) NULL,
    region VARCHAR(120) NULL,
    city VARCHAR(120) NULL,
    looked_up_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
