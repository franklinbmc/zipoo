<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    require_once dirname(__DIR__, 2) . '/api/warehouses_lib.php';

    ensure_warehouses_table($pdo);

    // Seed a default "Main Warehouse" for every existing business that has none.
    $businessIds = $pdo->query('SELECT id FROM tbl_businesses')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($businessIds as $businessId) {
        ensure_default_warehouse($pdo, (int) $businessId);
    }
};
