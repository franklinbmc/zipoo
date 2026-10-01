<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    // Shared sales table — backs both Invoices and (later) POS sales.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_sales (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id BIGINT UNSIGNED NOT NULL,
            sale_type ENUM("invoice", "pos") NOT NULL DEFAULT "invoice",
            invoice_number VARCHAR(50) NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            customer_name VARCHAR(190) NULL,
            status ENUM("draft", "sent", "paid", "overdue", "cancelled") NOT NULL DEFAULT "draft",
            issue_date DATE NOT NULL,
            due_date DATE NULL,
            subtotal DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            discount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            tax_rate DECIMAL(6, 2) NOT NULL DEFAULT 0.00,
            tax_amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            amount_paid DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            notes TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_biz_invoice (business_id, invoice_number),
            KEY idx_biz_status (business_id, status),
            KEY idx_biz_type (business_id, sale_type),
            KEY idx_biz_issue (business_id, issue_date),
            KEY idx_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_sale_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sale_id BIGINT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NULL,
            item_name VARCHAR(190) NOT NULL,
            quantity DECIMAL(12, 2) NOT NULL DEFAULT 1.00,
            unit_price DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_sale (sale_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
};
