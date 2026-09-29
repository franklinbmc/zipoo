<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    // 1. Items table (Products & Services)
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            type ENUM("product", "service") NOT NULL DEFAULT "product",
            name VARCHAR(190) NOT NULL,
            sku VARCHAR(100) NULL,
            barcode VARCHAR(100) NULL,
            category VARCHAR(100) NOT NULL DEFAULT "General",
            unit VARCHAR(50) NOT NULL DEFAULT "pcs",
            cost_price DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
            selling_price DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
            current_stock DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
            min_stock_alert DECIMAL(12, 2) NOT NULL DEFAULT 5.00,
            description TEXT NULL,
            status ENUM("active", "inactive") NOT NULL DEFAULT "active",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_items_biz_type (business_id, type),
            KEY idx_items_biz_name (business_id, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // 2. Purchase Orders
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_purchase_orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            po_number VARCHAR(50) NOT NULL,
            supplier_id INT UNSIGNED NOT NULL,
            order_date DATE NOT NULL,
            expected_date DATE NULL,
            status ENUM("draft", "sent", "partially_received", "received", "cancelled") NOT NULL DEFAULT "draft",
            subtotal DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            tax_rate DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
            tax_amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            notes TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_po_biz (business_id),
            KEY idx_po_supplier (supplier_id),
            KEY idx_po_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // 3. Purchase Order Items
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_purchase_order_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            purchase_order_id INT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            item_name VARCHAR(190) NOT NULL,
            quantity DECIMAL(12, 2) NOT NULL DEFAULT 1.00,
            unit_cost DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            received_quantity DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_poi_po (purchase_order_id),
            KEY idx_poi_item (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // 4. Goods Received Notes
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_goods_received (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            purchase_order_id INT UNSIGNED NOT NULL,
            grn_number VARCHAR(50) NOT NULL,
            received_date DATE NOT NULL,
            notes TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_grn_biz (business_id),
            KEY idx_grn_po (purchase_order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // 5. Goods Received Items
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_goods_received_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            goods_received_id INT UNSIGNED NOT NULL,
            po_item_id INT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            quantity_received DECIMAL(12, 2) NOT NULL,
            unit_cost DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_gri_gr (goods_received_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // 6. Stock Movements (Inventory Ledger)
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_stock_movements (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            movement_type ENUM("purchase_receive", "sale", "adjustment_add", "adjustment_subtract", "return", "damage") NOT NULL,
            quantity DECIMAL(12, 2) NOT NULL,
            previous_stock DECIMAL(12, 2) NOT NULL,
            new_stock DECIMAL(12, 2) NOT NULL,
            unit_cost DECIMAL(12, 2) NULL,
            reference_type VARCHAR(50) NULL,
            reference_id VARCHAR(50) NULL,
            notes TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_sm_biz_item (business_id, item_id),
            KEY idx_sm_biz_type (business_id, movement_type),
            KEY idx_sm_date (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
};
