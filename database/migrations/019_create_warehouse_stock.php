<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    require_once dirname(__DIR__, 2) . '/api/warehouses_lib.php';

    ensure_warehouse_stock_tables($pdo);
};
