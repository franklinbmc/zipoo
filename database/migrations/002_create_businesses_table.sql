CREATE TABLE IF NOT EXISTS tbl_businesses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_user_id BIGINT UNSIGNED NULL,
    business_name VARCHAR(190) NOT NULL,
    business_type VARCHAR(80) NOT NULL,
    region_code VARCHAR(16) NOT NULL,
    district_code VARCHAR(16) NOT NULL,
    plan_name VARCHAR(80) NOT NULL DEFAULT 'Starter',
    account_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_owner (owner_user_id),
    KEY idx_status (account_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
