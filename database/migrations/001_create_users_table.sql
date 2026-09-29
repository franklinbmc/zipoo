CREATE TABLE IF NOT EXISTS tbl_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(160) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(190) NULL,
    business_name VARCHAR(190) NOT NULL,
    business_type VARCHAR(80) NOT NULL,
    region_code VARCHAR(16) NOT NULL,
    district_code VARCHAR(16) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    business_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_phone (phone),
    UNIQUE KEY unique_email (email),
    KEY region_code (region_code),
    KEY district_code (district_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
