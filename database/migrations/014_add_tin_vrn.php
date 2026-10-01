<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    $addColumn = function (string $table, string $column, string $definition) use ($pdo): void {
        $cols = $pdo->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    };

    // TIN = Taxpayer Identification Number, VRN = VAT Registration Number
    $addColumn('tbl_businesses', 'tin', "VARCHAR(50) NULL AFTER vat_enabled");
    $addColumn('tbl_businesses', 'vrn', "VARCHAR(50) NULL AFTER tin");

    $addColumn('tbl_customers', 'tin', "VARCHAR(50) NULL AFTER address");
    $addColumn('tbl_customers', 'vrn', "VARCHAR(50) NULL AFTER tin");

    $addColumn('tbl_suppliers', 'tin', "VARCHAR(50) NULL AFTER address");
    $addColumn('tbl_suppliers', 'vrn', "VARCHAR(50) NULL AFTER tin");
};
