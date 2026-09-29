<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    $cols = $pdo->query("SHOW COLUMNS FROM tbl_businesses LIKE 'currency'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE tbl_businesses ADD COLUMN currency VARCHAR(10) NOT NULL DEFAULT 'TZS' AFTER plan_name");
    }

    $cols = $pdo->query("SHOW COLUMNS FROM tbl_businesses LIKE 'timezone'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE tbl_businesses ADD COLUMN timezone VARCHAR(100) NOT NULL DEFAULT 'Africa/Dar_es_Salaam' AFTER currency");
    }

    $cols = $pdo->query("SHOW COLUMNS FROM tbl_businesses LIKE 'tax_rate'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE tbl_businesses ADD COLUMN tax_rate DECIMAL(5, 2) NOT NULL DEFAULT 18.00 AFTER timezone");
    }

    $cols = $pdo->query("SHOW COLUMNS FROM tbl_businesses LIKE 'receipt_footer'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE tbl_businesses ADD COLUMN receipt_footer TEXT NULL AFTER tax_rate");
    }
};
