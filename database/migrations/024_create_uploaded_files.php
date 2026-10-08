<?php
declare(strict_types=1);

return function (PDO $pdo): void {
    require_once dirname(__DIR__, 2) . '/api/uploaded_files_lib.php';

    ensure_uploaded_files_table($pdo);
};
