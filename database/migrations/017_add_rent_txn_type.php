<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    require_once dirname(__DIR__, 2) . '/api/accounts_lib.php';

    // Adds the "rent" value to tbl_account_transactions.type (creates the table
    // first if it does not yet exist on this database).
    ensure_rent_txn_type($pdo);
};
