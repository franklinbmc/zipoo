<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    require_once dirname(__DIR__, 2) . '/api/realestate_lib.php';

    ensure_realestate_tables($pdo);
};
