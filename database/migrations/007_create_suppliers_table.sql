CREATE TABLE IF NOT EXISTS tbl_suppliers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_id BIGINT UNSIGNED NOT NULL,
    supplier_name VARCHAR(190) NOT NULL,
    contact_person VARCHAR(190) NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(190) NULL,
    address VARCHAR(255) NULL,
    notes TEXT NULL,
    total_purchases DECIMAL(15, 2) NOT NULL DEFAULT 0.00,
    purchases_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_business (business_id),
    KEY idx_business_name (business_id, supplier_name),
    KEY idx_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
