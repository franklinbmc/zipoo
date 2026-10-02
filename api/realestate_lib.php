<?php
declare(strict_types=1);

/**
 * Shared Real Estate (rentals) schema helpers. Safe to require_once from any
 * endpoint or migration: declares functions only, no request-handling side effects.
 *
 * Model: a property contains one or more rentable units. A tenancy links a unit
 * to a tenant (tbl_customers row). Rent payments post income into Bank & Cash.
 */

function ensure_realestate_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_properties (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            type VARCHAR(50) NOT NULL DEFAULT "residential",
            location VARCHAR(255) NULL,
            notes TEXT NULL,
            status ENUM("active", "inactive") NOT NULL DEFAULT "active",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_prop_biz (business_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_property_units (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            property_id INT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            charge_type ENUM("daily", "monthly") NOT NULL DEFAULT "monthly",
            rate DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            status ENUM("vacant", "occupied", "inactive") NOT NULL DEFAULT "vacant",
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_unit_biz_prop (business_id, property_id),
            KEY idx_unit_status (business_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_tenancies (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            property_id INT UNSIGNED NOT NULL,
            unit_id INT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            charge_type ENUM("daily", "monthly") NOT NULL DEFAULT "monthly",
            rate DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            start_date DATE NOT NULL,
            end_date DATE NULL,
            deposit DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            status ENUM("active", "ended") NOT NULL DEFAULT "active",
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ten_biz_unit (business_id, unit_id),
            KEY idx_ten_biz_status (business_id, status),
            KEY idx_ten_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_rent_payments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            tenancy_id INT UNSIGNED NOT NULL,
            unit_id INT UNSIGNED NOT NULL,
            property_id INT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
            period_label VARCHAR(50) NULL,
            period_start DATE NULL,
            period_end DATE NULL,
            paid_date DATE NOT NULL,
            account_id INT UNSIGNED NULL,
            account_txn_id BIGINT UNSIGNED NULL,
            notes TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rent_biz_ten (business_id, tenancy_id),
            KEY idx_rent_biz_date (business_id, paid_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_realestate_staff (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            phone VARCHAR(50) NULL,
            role VARCHAR(50) NOT NULL DEFAULT "caretaker",
            property_id INT UNSIGNED NULL,
            notes TEXT NULL,
            status ENUM("active", "inactive") NOT NULL DEFAULT "active",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_restaff_biz (business_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** Self-heals the structured period columns on tbl_rent_payments for pre-existing databases. */
function ensure_rent_period_columns(PDO $pdo): void
{
    $addColumn = static function (string $table, string $column, string $definition) use ($pdo): void {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'")->fetchAll();
        } catch (Throwable) {
            return;
        }
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    };

    $addColumn('tbl_rent_payments', 'period_start', 'DATE NULL AFTER period_label');
    $addColumn('tbl_rent_payments', 'period_end', 'DATE NULL AFTER period_start');
}
