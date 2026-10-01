<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    // 1. Business-level VAT toggle (rate reuses existing tbl_businesses.tax_rate)
    $cols = $pdo->query("SHOW COLUMNS FROM tbl_businesses LIKE 'vat_enabled'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE tbl_businesses ADD COLUMN vat_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER tax_rate");
    }

    // 2. Items — whether VAT applies to this product/service
    $cols = $pdo->query("SHOW COLUMNS FROM tbl_items LIKE 'vat_applicable'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE tbl_items ADD COLUMN vat_applicable TINYINT(1) NOT NULL DEFAULT 1 AFTER selling_price");
    }

    // 3. Purchase order line items — snapshot the VAT flag at time of order
    $cols = $pdo->query("SHOW COLUMNS FROM tbl_purchase_order_items LIKE 'vat_applicable'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE tbl_purchase_order_items ADD COLUMN vat_applicable TINYINT(1) NOT NULL DEFAULT 1 AFTER line_total");
    }

    // 4. Sale line items — snapshot the VAT flag at time of sale
    $cols = $pdo->query("SHOW COLUMNS FROM tbl_sale_items LIKE 'vat_applicable'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE tbl_sale_items ADD COLUMN vat_applicable TINYINT(1) NOT NULL DEFAULT 1 AFTER line_total");
    }
};
