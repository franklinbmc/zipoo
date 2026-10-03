<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    require_once dirname(__DIR__, 2) . '/api/pos_lib.php';

    ensure_pos_shifts_table($pdo);
    ensure_sales_pos_columns($pdo);
};
