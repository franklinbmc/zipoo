<?php
declare(strict_types=1);

/**
 * Self-heals the VAT columns added by migration 013 so the sales, items and
 * purchasing endpoints work even on databases where that migration has not run.
 * Safe to require_once anywhere: declares a function only.
 */
function ensure_vat_columns(PDO $pdo): void
{
    $addColumn = static function (string $table, string $column, string $definition) use ($pdo): void {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'")->fetchAll();
        } catch (Throwable) {
            return; // table does not exist yet; its own ensure_* will create it with the column
        }
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    };

    $addColumn('tbl_businesses', 'vat_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER tax_rate');
    $addColumn('tbl_items', 'vat_applicable', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER selling_price');
    $addColumn('tbl_purchase_order_items', 'vat_applicable', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER line_total');
    $addColumn('tbl_sale_items', 'vat_applicable', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER line_total');
}

/**
 * Self-heals the TIN / VRN tax-identity columns added by migration 014 on
 * businesses, customers and suppliers, so endpoints selecting them keep working
 * on databases where that migration has not run.
 */
function ensure_party_tax_ids(PDO $pdo): void
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

    $addColumn('tbl_customers', 'tin', 'VARCHAR(50) NULL AFTER address');
    $addColumn('tbl_customers', 'vrn', 'VARCHAR(50) NULL AFTER tin');
    $addColumn('tbl_suppliers', 'tin', 'VARCHAR(50) NULL AFTER address');
    $addColumn('tbl_suppliers', 'vrn', 'VARCHAR(50) NULL AFTER tin');
}
