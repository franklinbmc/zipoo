<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    require_once dirname(__DIR__, 2) . '/api/accounts_lib.php';

    ensure_accounts_tables($pdo);

    // Seed a default "Cash" account for every existing business that has none.
    $businessIds = $pdo->query('SELECT id FROM tbl_businesses')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($businessIds as $businessId) {
        ensure_default_account($pdo, (int) $businessId);
    }
};
