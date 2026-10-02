<?php
declare(strict_types=1);

/**
 * Shared warehouse helpers. Safe to require_once from any endpoint or migration:
 * declares functions only, no request-handling side effects.
 */

function ensure_warehouses_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_warehouses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            code VARCHAR(50) NULL,
            location VARCHAR(190) NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM("active", "inactive") NOT NULL DEFAULT "active",
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_wh_biz (business_id),
            KEY idx_wh_default (business_id, is_default)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * Per-warehouse stock allocations and a transfer audit log.
 *
 * Model: tbl_warehouse_stock holds an explicit quantity only for NON-default
 * warehouses. The default warehouse holds the residual
 * (tbl_items.current_stock minus the sum of all explicit allocations), so the
 * per-warehouse quantities always reconcile to each item's total stock without
 * touching the existing POS / purchasing / adjustment flows.
 */
function ensure_warehouse_stock_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_warehouse_stock (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            warehouse_id INT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            quantity DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_ws (business_id, warehouse_id, item_id),
            KEY idx_ws_item (business_id, item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_stock_transfers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            business_id INT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            from_warehouse_id INT UNSIGNED NOT NULL,
            to_warehouse_id INT UNSIGNED NOT NULL,
            quantity DECIMAL(12, 2) NOT NULL,
            notes TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_st_biz (business_id),
            KEY idx_st_item (business_id, item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** Sets a non-default warehouse's allocation for an item, deleting the row when it reaches zero. */
function set_warehouse_allocation(PDO $pdo, int $businessId, int $warehouseId, int $itemId, float $quantity): void
{
    if ($quantity <= 0) {
        $pdo->prepare('DELETE FROM tbl_warehouse_stock WHERE business_id = :bid AND warehouse_id = :wid AND item_id = :iid')
            ->execute([':bid' => $businessId, ':wid' => $warehouseId, ':iid' => $itemId]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO tbl_warehouse_stock (business_id, warehouse_id, item_id, quantity)
         VALUES (:bid, :wid, :iid, :qty)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
    )->execute([':bid' => $businessId, ':wid' => $warehouseId, ':iid' => $itemId, ':qty' => round($quantity, 2)]);
}

/**
 * Guarantees the business has at least one warehouse, creating a default
 * "Main Warehouse" when none exist. Returns the default warehouse id.
 */
function ensure_default_warehouse(PDO $pdo, int $businessId): int
{
    ensure_warehouses_table($pdo);

    $stmt = $pdo->prepare('SELECT id FROM tbl_warehouses WHERE business_id = :bid AND is_default = 1 LIMIT 1');
    $stmt->execute([':bid' => $businessId]);
    $defaultId = (int) ($stmt->fetchColumn() ?: 0);
    if ($defaultId > 0) {
        return $defaultId;
    }

    // A warehouse exists but none flagged default: promote the first one.
    $stmt = $pdo->prepare('SELECT id FROM tbl_warehouses WHERE business_id = :bid ORDER BY id ASC LIMIT 1');
    $stmt->execute([':bid' => $businessId]);
    $existingId = (int) ($stmt->fetchColumn() ?: 0);
    if ($existingId > 0) {
        $pdo->prepare('UPDATE tbl_warehouses SET is_default = 1 WHERE id = :id')->execute([':id' => $existingId]);
        return $existingId;
    }

    $ins = $pdo->prepare(
        'INSERT INTO tbl_warehouses (business_id, name, code, is_default, status)
         VALUES (:bid, "Main Warehouse", "MAIN", 1, "active")'
    );
    $ins->execute([':bid' => $businessId]);

    return (int) $pdo->lastInsertId();
}
