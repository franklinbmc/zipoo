CREATE TABLE IF NOT EXISTS tbl_tanzania_locations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    region_code VARCHAR(16) NOT NULL,
    region_name VARCHAR(100) NOT NULL,
    district_code VARCHAR(16) NOT NULL,
    district_name VARCHAR(120) NOT NULL,
    source_url VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_region_district (region_code, district_code),
    KEY region_name (region_name),
    KEY district_name (district_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
