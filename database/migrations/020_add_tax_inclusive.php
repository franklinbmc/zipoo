<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    require_once dirname(__DIR__, 2) . '/api/vat_lib.php';

    // ensure_vat_columns adds tbl_items.tax_inclusive and tbl_sale_items.tax_inclusive.
    ensure_vat_columns($pdo);
};
